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

        // Aba de ajuda no admin: qual shortcode usar + classe do botão.
        add_filter('adspirit_connector_tabs',
            AdSpirit_Safe_Hook::filter(array($this, 'register_tab'), 'qualifier_tab_register'));
        add_action('adspirit_connector_render_tab_qualifier',
            AdSpirit_Safe_Hook::action(array($this, 'render_tab'), 'qualifier_tab'));
    }

    public function register_tab($tabs) {
        $tabs['qualifier'] = 'Form de avaliação';
        return $tabs;
    }

    /** Guia visual: qual shortcode escolher + como abrir via botão próprio. */
    public function render_tab() {
        $modes = array(
            array('Botão "Iniciar avaliação"', 'Mostra um botão; ao clicar, abre o form em TELA CHEIA. Bom pra CTA em hero/seção escura.', '[adspirit_form_qualifier]'),
            array('Contido na seção', 'O form aparece DENTRO da seção (card escuro, sem cobrir a tela). Pra encaixar numa landing com hero + seção.', '[adspirit_form_qualifier mode="embed"]'),
            array('Abre sozinho (tela cheia)', 'O form abre em tela cheia assim que a página carrega, sem botão. Pra página dedicada só do form.', '[adspirit_form_qualifier mode="inline"]'),
            array('Com o SEU botão', 'Não renderiza botão — você usa um botão próprio (estilizado no builder) pra abrir. Coloque este shortcode uma vez na página.', '[adspirit_form_qualifier mode="trigger"]'),
        );
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Form</span>Form de avaliação (qualifier)</h2>
        <p class="as-section-help">O form de avaliação com o design da agência (preto + glassmorphism, multi-step BANT). <strong>Não confunda</strong> com <code>[adspirit_form]</code> (form branco genérico antigo). Veja na PÁGINA publicada — no editor do builder ele aparece vazio (é montado por JavaScript).</p>

        <?php AdSpirit_Menu::card_open('Qual shortcode usar', 'Escolha conforme onde o form vai aparecer'); ?>
        <table class="as-table" style="width:100%;">
            <thead><tr><th style="width:200px;">Quero…</th><th>O que faz</th><th style="width:340px;">Shortcode (copie)</th></tr></thead>
            <tbody>
            <?php foreach ($modes as $m): ?>
                <tr>
                    <td><strong><?php echo esc_html($m[0]); ?></strong></td>
                    <td><?php echo esc_html($m[1]); ?></td>
                    <td>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <input type="text" readonly value="<?php echo esc_attr($m[2]); ?>" class="regular-text code" style="flex:1; font-size:12px;" onclick="this.select();">
                            <button type="button" class="button as-copy" data-copy="<?php echo esc_attr($m[2]); ?>">Copiar</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php AdSpirit_Menu::card_close(); ?>

        <?php AdSpirit_Menu::card_open('Usar o seu próprio botão', 'Com o shortcode mode="trigger" na página, marque QUALQUER botão pra abrir o form'); ?>
        <p class="as-section-help">Coloque <code>[adspirit_form_qualifier mode="trigger"]</code> uma vez na página (em qualquer lugar) e então marque o seu botão de UMA destas formas:</p>
        <table class="form-table">
            <tr>
                <th>Link do botão</th>
                <td><code>#adspirit-avaliacao</code><p class="description">O jeito mais fácil no builder: aponte o link do botão pra <code>#adspirit-avaliacao</code>.</p></td>
            </tr>
            <tr>
                <th>Atributo HTML</th>
                <td><code>data-adspirit-qualifier</code><p class="description">Adicione esse atributo (custom attribute) ao botão/elemento.</p></td>
            </tr>
            <tr>
                <th>Classe CSS</th>
                <td><code>adspirit-qualifier-trigger</code><p class="description">Funciona, mas essa classe carrega o estilo do plugin — prefira as duas de cima pra manter o SEU design.</p></td>
            </tr>
        </table>
        <?php AdSpirit_Menu::card_close(); ?>

        <script>
        (function(){
            document.querySelectorAll('.as-copy').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var txt = btn.getAttribute('data-copy') || '';
                    var done = function(){ var o = btn.textContent; btn.textContent = 'Copiado!'; setTimeout(function(){ btn.textContent = o; }, 1400); };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(txt).then(done).catch(done);
                    } else {
                        var t = document.createElement('textarea'); t.value = txt; document.body.appendChild(t); t.select();
                        try { document.execCommand('copy'); } catch(e){}
                        document.body.removeChild(t); done();
                    }
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Render shortcode.
     *   atts: mode="popup"|"inline"|"embed"|"trigger" (default popup), button_label="..."
     *     - popup:   botão CTA → form em tela cheia (overlay)
     *     - inline:  form em tela cheia, abre direto no load (sem botão)
     *     - embed:   form contido na seção (card dark, sem overlay full-screen)
     *     - trigger: só o popup (sem botão) → você usa SEU botão pra abrir.
     *                Qualquer elemento abre o form se tiver:
     *                  • class "adspirit-qualifier-trigger", OU
     *                  • atributo data-adspirit-qualifier, OU
     *                  • link href="#adspirit-avaliacao"
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'mode' => 'popup',
            'button_label' => 'Iniciar avaliação',
        ), $atts, 'adspirit_form_qualifier');
        $mode = in_array($atts['mode'], array('inline', 'embed', 'trigger'), true) ? $atts['mode'] : 'popup';

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

        // Trigger: SÓ o popup (sem botão). Use seu PRÓPRIO botão pra abrir —
        // qualquer elemento com data-adspirit-qualifier, class adspirit-qualifier-trigger,
        // ou link href="#adspirit-avaliacao" dispara o form.
        if ($mode === 'trigger') {
            return '<div class="adspirit-qualifier-root" data-mode="popup" hidden></div>';
        }
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

        // Lead PARCIAL: disparado após a etapa de contato (email+WhatsApp).
        // submission_id (do front) liga parcial e final via o mesmo id-base
        // (parcial usa sufixo "-p"); o CRM dedupa por contato e promove o
        // lead parcial in-place quando o final chega.
        $is_partial = !empty($_POST['_adspirit_partial']);
        $client_sid = isset($_POST['submission_id'])
            ? sanitize_text_field((string) $_POST['submission_id'])
            : '';

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
        if ($is_partial) {
            $payload['_adspirit_partial'] = '1';
        }

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

        // Submission id estável: usa o do front quando vier (liga parcial↔final),
        // senão gera. Parcial leva sufixo "-p" → idempotency distinta do final,
        // então o final NÃO cai no cache do webhook e roda o scoring.
        $base_sid = $client_sid !== '' ? $client_sid : ('q-' . time() . '-' . wp_generate_password(8, false));
        $submission_id = $base_sid . ($is_partial ? '-p' : '');

        $endpoint = rtrim($core['endpoint_url'], '/') . '/api/webhooks/contact-form-7';
        $response = wp_remote_post($endpoint, array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-brand-slug' => $core['brand_slug'],
                'x-cf7-secret' => $core['secret'],
                'x-cf7-submission-id' => $submission_id,
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

        // Webhook out (fan-out pra n8n/Elisa, Zapier, etc) — fire-and-forget.
        // NÃO faz fanout no parcial (lead incompleto não dispara automações
        // downstream; só o envio final completo faz).
        if (!$is_partial && class_exists('AdSpirit_Integrations') && method_exists('AdSpirit_Integrations', 'fanout')) {
            try {
                AdSpirit_Integrations::instance()->fanout($payload);
            } catch (\Throwable $e) { /* silenciado */ }
        }

        // Resposta pro JS — repassa redirect_url + profile do CRM.
        // No parcial o front ignora a resposta (segue nos próximos steps).
        wp_send_json_success(array(
            'partial' => $is_partial,
            'redirect_url' => isset($body['redirect_url']) ? (string) $body['redirect_url'] : home_url('/'),
            'profile' => isset($body['profile']) ? (string) $body['profile'] : null,
            'duplicate' => !empty($body['duplicate']),
        ));
    }
}
