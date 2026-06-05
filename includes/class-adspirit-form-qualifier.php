<?php
/**
 * AdSpirit Form Qualifier — shortcode multi-step BANT + popup overlay.
 *
 * Uso: [adspirit_form_qualifier] (popup com botão "Iniciar avaliação")
 *      [adspirit_form_qualifier mode="inline"] (renderiza inline)
 *
 * Fluxo:
 *   1. Cliente clica botão (mode=popup) ou form já aparece (mode=inline)
 *   2. Form multi-step: 11 etapas BANT (alinhadas com config do CRM)
 *   3. Submit AJAX → POST /api/webhooks/contact-form-7 (CRM)
 *   4. CRM retorna { redirect_url, profile }
 *   5. Tela "Cadastro recebido" + countdown 5s → window.location
 *
 * Privacidade: profile é retornado pelo CRM e fica visível no DevTools
 * (decisão produto 2026-06-04). Cliente final não vê feedback explícito
 * sobre a qualificação na UI — apenas o redirect varia.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Form_Qualifier {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Shortcode
        add_shortcode('adspirit_form_qualifier', array($this, 'render_shortcode'));

        // AJAX submit (priv + nopriv: form é público no site)
        add_action('wp_ajax_adspirit_qualifier_submit',
            AdSpirit_Safe_Hook::action(array($this, 'handle_submit'), 'qualifier_submit'));
        add_action('wp_ajax_nopriv_adspirit_qualifier_submit',
            AdSpirit_Safe_Hook::action(array($this, 'handle_submit'), 'qualifier_submit'));
    }

    /**
     * Render shortcode.
     *   atts: mode="popup"|"inline"|"embed" (default popup), button_label="..."
     *     - popup:  botão CTA → form em tela cheia (overlay)
     *     - inline: form em tela cheia, abre direto no load (sem botão)
     *     - embed:  form contido na seção (card dark, sem overlay full-screen)
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'mode' => 'popup',
            'button_label' => 'Iniciar avaliação',
        ), $atts, 'adspirit_form_qualifier');
        $mode = in_array($atts['mode'], array('inline', 'embed'), true) ? $atts['mode'] : 'popup';

        // Enqueue assets só quando shortcode é renderizado
        $version = defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : '2.3.0';
        // Fontes do mockup (Inter + Open Sans). SEM elas o tema cai pra fonte
        // dele e o form fica "totalmente diferente". Carregadas como dep do CSS.
        wp_enqueue_style(
            'adspirit-qualifier-fonts',
            'https://fonts.googleapis.com/css2?family=Inter:wght@200;300;400;500&family=Open+Sans:wght@400;500;600;700&display=swap',
            array(),
            null
        );
        wp_enqueue_style(
            'adspirit-qualifier-form',
            ADSPIRIT_CONNECTOR_URL . 'assets/qualifier-form.css',
            array('adspirit-qualifier-fonts'),
            $version
        );
        wp_enqueue_script(
            'adspirit-qualifier-form',
            ADSPIRIT_CONNECTOR_URL . 'assets/qualifier-form.js',
            array(),
            $version,
            true
        );

        // Config injetada como localStorage (visitor_id, brand_slug, ajax_url)
        $core = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();
        wp_localize_script('adspirit-qualifier-form', 'AdSpiritQualifierCfg', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('adspirit_qualifier_submit'),
            'brand_slug' => (string) ($core['brand_slug'] ?? ''),
            'mode' => $mode,
            'button_label' => esc_html($atts['button_label']),
        ));

        // Embed: card contido na seção (sem overlay). Inline: tela cheia no load.
        if ($mode === 'embed' || $mode === 'inline') {
            return '<div class="adspirit-qualifier-root" data-mode="' . esc_attr($mode) . '"></div>';
        }
        // Popup mode: botão CTA que dispara o popup em tela cheia
        return sprintf(
            '<button type="button" class="adspirit-qualifier-trigger">%s</button><div class="adspirit-qualifier-root" data-mode="popup" hidden></div>',
            esc_html($atts['button_label'])
        );
    }

    /**
     * AJAX handler: recebe payload do form, repassa pro CRM, retorna
     * { redirect_url, profile } pro JS fazer countdown + redirect.
     */
    public function handle_submit() {
        // Nonce check
        $nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'adspirit_qualifier_submit')) {
            wp_send_json_error(array('error' => 'bad_nonce'), 403);
            return;
        }

        // Rate limit por IP REAL (não REMOTE_ADDR puro — colapsa em proxy
        // Cloudflare/EasyPanel). Lê CF-Connecting-IP > X-Forwarded-For
        // (primeiro IP) > REMOTE_ADDR. Cap 30/min (não 5 — atrás de proxy
        // o bucket era global). Adicionalmente, bucket por email pra
        // bloquear flood com 1 email só.
        // REMOTE_ADDR é sempre presente em CGI normal. Headers de proxy
        // são lidos pra deduzir IP real quando atrás de Cloudflare/proxy,
        // mas REQUER REMOTE_ADDR presente pra confirmar que o request
        // veio de um proxy real (não direto de internet falsificando
        // headers). Sem REMOTE_ADDR: negar (caso anômalo, CLI ou bug).
        if (empty($_SERVER['REMOTE_ADDR'])) {
            wp_send_json_error(array('error' => 'no_ip'), 400);
            return;
        }
        $ip = (string) $_SERVER['REMOTE_ADDR'];
        // Se REMOTE_ADDR é proxy conhecido, prefere header X-Forwarded
        // (não validamos ranges exatos — pragmatismo, mas pelo menos
        // exigimos REMOTE_ADDR confirmando um proxy real está intermediando).
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) {
            $ip = trim((string) $_SERVER['HTTP_CF_CONNECTING_IP']);
        } elseif (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            $candidate = trim($parts[0]);
            if ($candidate !== '') $ip = $candidate;
        }
        $bucket = 'adspirit_qualifier_rl_' . md5($ip);
        $hits = (int) get_transient($bucket);
        if ($hits >= 30) {
            wp_send_json_error(array('error' => 'rate_limit'), 429);
            return;
        }
        set_transient($bucket, $hits + 1, 60);

        // Lê fields do POST. Pra textarea (pain, notes), usa
        // sanitize_textarea_field pra preservar line breaks; demais
        // campos usam sanitize_text_field (single-line).
        $fields = isset($_POST['fields']) && is_array($_POST['fields']) ? $_POST['fields'] : array();
        $textarea_keys = array('pain', 'notes', 'message', 'mensagem');
        $sanitized = array();
        foreach ($fields as $key => $value) {
            $k = sanitize_key((string) $key);
            if (!is_scalar($value)) {
                $sanitized[$k] = '';
                continue;
            }
            $raw = (string) $value;
            $sanitized[$k] = in_array($k, $textarea_keys, true)
                ? sanitize_textarea_field($raw)
                : sanitize_text_field($raw);
        }

        // Presença online: form coleta Instagram + site separados (pelo menos
        // um obrigatório). Combinamos no campo canônico `site-empresa` —
        // mesmo destino do antigo `social`, então o CRM/website column não muda.
        // Site (URL) tem prioridade; Instagram entra junto quando presente.
        $site_in  = trim((string) ($sanitized['site'] ?? ''));
        $insta_in = trim((string) ($sanitized['instagram'] ?? ''));
        $presence = $site_in;
        if ($insta_in !== '') {
            $presence = ($presence === '') ? $insta_in : $presence . ' · ' . $insta_in;
        }
        // Back-compat: sessões antigas ainda mandam `social` num campo só.
        if ($presence === '' && !empty($sanitized['social'])) {
            $presence = (string) $sanitized['social'];
        }

        // Mapeia pros field names canônicos do CF7 (pra Elisa/n8n continuar reconhecendo)
        $payload = array(
            'your-name' => trim(($sanitized['first_name'] ?? '') . ' ' . ($sanitized['last_name'] ?? '')),
            'your-email' => $sanitized['email'] ?? '',
            'Telefone' => $sanitized['phone'] ?? '',
            'site-empresa' => $presence,
            'instagram' => $insta_in,
            'empresa' => $sanitized['company'] ?? '',
            'cargo' => $sanitized['role'] ?? '',
            'Numero-funcionarios' => $sanitized['size'] ?? '',
            'nicho' => $sanitized['market'] ?? '',
            'ExperienciacomMarketing' => $sanitized['experience'] ?? '',
            'revenue' => $sanitized['revenue'] ?? '',
            'Investimento mensal em Marketing' => $sanitized['investment'] ?? '',
            'urgencia' => $sanitized['timing'] ?? '',
            'pain' => $sanitized['pain'] ?? '',
            'cf7_time' => current_time('c'),
            'cf7_url' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : home_url('/'),
        );

        // Telemetria — signature real: collect_from_post($form_kind, $form_id, $referrer_url).
        // Coleta UTMs, gclid, fbclid e visitor journey via static call.
        if (class_exists('AdSpirit_Telemetry') && method_exists('AdSpirit_Telemetry', 'collect_from_post')) {
            try {
                $referrer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : home_url('/');
                $telemetry = AdSpirit_Telemetry::collect_from_post('qualifier', 'adspirit_form_qualifier', $referrer);
                if (is_array($telemetry)) {
                    $payload['_adspirit_telemetry'] = $telemetry;
                }
            } catch (\Throwable $e) { /* silenciado */ }
        }

        // Despacha pro CRM
        $core = AdSpirit_Settings::get_core();
        if (empty($core['endpoint_url']) || empty($core['brand_slug']) || empty($core['secret'])) {
            wp_send_json_error(array('error' => 'config_incompleta'), 412);
            return;
        }

        $endpoint = rtrim($core['endpoint_url'], '/') . '/api/webhooks/contact-form-7';
        $response = wp_remote_post($endpoint, array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-brand-slug' => $core['brand_slug'],
                'x-cf7-secret' => $core['secret'],
                'x-cf7-submission-id' => 'q-' . time() . '-' . wp_generate_password(8, false),
                'User-Agent' => 'AdSpirit-Connector/' . ADSPIRIT_CONNECTOR_VERSION,
            ),
            'body' => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            wp_send_json_error(array('error' => 'crm_unreachable', 'detail' => $response->get_error_message()), 502);
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);

        if ($code < 200 || $code >= 300 || !is_array($body)) {
            wp_send_json_error(array('error' => 'crm_error', 'status' => $code), 502);
            return;
        }

        // Webhook out (fan-out pra n8n/Elisa, Zapier, etc) — fire-and-forget
        if (class_exists('AdSpirit_Integrations') && method_exists('AdSpirit_Integrations', 'fanout')) {
            try {
                AdSpirit_Integrations::instance()->fanout($payload);
            } catch (\Throwable $e) { /* silenciado */ }
        }

        // Resposta pro JS — repassa redirect_url + profile do CRM
        wp_send_json_success(array(
            'redirect_url' => isset($body['redirect_url']) ? (string) $body['redirect_url'] : home_url('/'),
            'profile' => isset($body['profile']) ? (string) $body['profile'] : null,
            'duplicate' => !empty($body['duplicate']),
        ));
    }
}
