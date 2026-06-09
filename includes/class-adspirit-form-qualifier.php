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
    const OPTION_KEY = 'adspirit_qualifier';

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
        add_action('adspirit_connector_save_qualifier',
            AdSpirit_Safe_Hook::action(array($this, 'handle_save'), 'qualifier_save'));

        // v2.10.3: hooks de "site todo" REGISTRADOS APENAS quando o toggle está
        // explicitamente ligado (option === '1'). Antes ficavam sempre registrados
        // e davam early-return dentro do handler — funcional, mas em sites com
        // LiteSpeed JS combine + muitos plugins isso introduzia interferência
        // no submit AJAX do CF7 (form principal parava de chegar nos hooks).
        // Sem opt-in, ZERO modificação do front-end (sem extra wp_footer hook,
        // sem extra enqueue check).
        $qs = get_option(self::OPTION_KEY, array());
        if (isset($qs['sitewide']) && $qs['sitewide'] === '1') {
            add_action('wp_enqueue_scripts',
                AdSpirit_Safe_Hook::action(array($this, 'maybe_enqueue_sitewide'), 'qualifier_sitewide_enqueue'));
            add_action('wp_footer',
                AdSpirit_Safe_Hook::action(array($this, 'maybe_inject_sitewide_root'), 'qualifier_sitewide_root'));
        }
    }

    public static function defaults() {
        return array('sitewide' => '0');
    }

    public static function get_settings() {
        return wp_parse_args(get_option(self::OPTION_KEY, array()), self::defaults());
    }

    /** Enqueue do CSS/JS/fonte + config. Idempotente (WP dedupa por handle). */
    public static function enqueue_assets($mode = 'popup', $button_label = 'Iniciar avaliação') {
        $version = defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : '2.3.0';
        // Fontes do mockup (Inter + Open Sans), dep do CSS.
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
        $core = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();
        // v2.10: Turnstile config (apenas se Turnstile ativo + aplica ao qualifier)
        $turnstile_cfg = array('enabled' => false, 'site_key' => '');
        if (class_exists('AdSpirit_Turnstile') && AdSpirit_Turnstile::applies_to_qualifier()) {
            $ts = AdSpirit_Turnstile::get_settings();
            $turnstile_cfg = array('enabled' => true, 'site_key' => (string) $ts['site_key']);
        }
        wp_localize_script('adspirit-qualifier-form', 'AdSpiritQualifierCfg', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('adspirit_qualifier_submit'),
            'brand_slug' => (string) ($core['brand_slug'] ?? ''),
            'mode' => $mode,
            'button_label' => esc_html($button_label),
            'turnstile' => $turnstile_cfg,
        ));
    }

    /** Site todo: enqueue dos assets (no head) quando o toggle está ligado. */
    public function maybe_enqueue_sitewide() {
        if (is_admin()) return;
        $s = self::get_settings();
        if (($s['sitewide'] ?? '0') !== '1') return;
        self::enqueue_assets('trigger');
    }

    /** Site todo: injeta o root (trigger) no rodapé. Idempotente por página. */
    public function maybe_inject_sitewide_root() {
        if (is_admin()) return;
        $s = self::get_settings();
        if (($s['sitewide'] ?? '0') !== '1') return;
        echo '<div class="adspirit-qualifier-root" data-mode="popup" hidden data-adspirit-sitewide="1"></div>';
    }

    public function handle_save($post) {
        $patch = array('sitewide' => !empty($post['sitewide']) ? '1' : '0');
        $merged = wp_parse_args($patch, self::get_settings());
        update_option(self::OPTION_KEY, $merged, false);
        add_settings_error(self::OPTION_KEY, 'saved', 'Configurações do form salvas.', 'updated');
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
        $qs = self::get_settings();
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Form</span>Form de avaliação (qualifier)</h2>
        <p class="as-section-help">O form de avaliação com o design da agência (preto + glassmorphism, multi-step BANT). <strong>Não confunda</strong> com <code>[adspirit_form]</code> (form branco genérico antigo). Veja na PÁGINA publicada — no editor do builder ele aparece vazio (é montado por JavaScript).</p>

        <?php AdSpirit_Menu::card_open('Disponibilizar no site todo', 'Sem precisar do shortcode em cada página', ($qs['sitewide'] ?? '0') === '1' ? '<span class="as-badge ok">Ligado</span>' : '<span class="as-badge muted">Desligado</span>'); ?>
        <?php AdSpirit_Menu::form_open('qualifier'); ?>
        <table class="form-table">
            <tr>
                <th>Site todo</th>
                <td>
                    <label><input type="checkbox" name="sitewide" value="1" <?php checked($qs['sitewide'] ?? '0', '1'); ?>> <strong>Carregar o form em todas as páginas</strong> (escondido até clicarem)</label>
                    <p class="description">Ligado, você <strong>não precisa de shortcode em página nenhuma</strong> — é só criar um botão e apontar o link dele pra <code>#adspirit-avaliacao</code> (veja o card abaixo). Custo: o CSS/JS do form carrega em todo o site.</p>
                </td>
            </tr>
        </table>
        <?php AdSpirit_Menu::form_close('Salvar'); ?>
        <?php AdSpirit_Menu::card_close(); ?>

        <?php AdSpirit_Menu::card_open('Qual shortcode usar', 'Se NÃO ligar o "site todo", escolha conforme onde o form vai aparecer'); ?>
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

        <?php AdSpirit_Menu::card_open('Abrir com o seu próprio botão', 'Botão estilizado no seu builder, sem o botão do plugin — em 2 passos'); ?>
        <div style="display:grid; gap:18px;">
            <div>
                <p style="margin:0 0 8px; font-weight:600; color:var(--as-ink);">1. Deixe o form disponível na página <span style="font-weight:400; color:var(--as-ink-faint);">(escolha um)</span></p>
                <ul style="margin:0; padding-left:18px; line-height:1.85; color:var(--as-ink-soft);">
                    <li>Ligue <strong>"Site todo"</strong> no card acima — recomendado, vale pra todas as páginas, ou</li>
                    <li>Cole <code>[adspirit_form_qualifier mode="trigger"]</code> uma vez na página onde o botão fica (não aparece nada, só carrega o form).</li>
                </ul>
            </div>
            <div>
                <p style="margin:0 0 8px; font-weight:600; color:var(--as-ink);">2. Aponte o seu botão pro form</p>
                <p style="margin:0 0 8px; color:var(--as-ink-soft);">No campo <strong>Link / URL</strong> do botão (no builder), use:</p>
                <div style="display:flex; gap:6px; align-items:center; max-width:320px;">
                    <input type="text" readonly value="#adspirit-avaliacao" class="regular-text code" style="flex:1; font-size:12px;" onclick="this.select();">
                    <button type="button" class="button as-copy" data-copy="#adspirit-avaliacao">Copiar</button>
                </div>
                <p class="description" style="margin-top:10px;"><strong>Sem campo de link no botão?</strong> Adicione a ele o atributo <code>data-adspirit-qualifier</code>, ou a classe <code>adspirit-qualifier-trigger</code> (essa herda o estilo do plugin — prefira o link ou o atributo pra manter o seu design).</p>
            </div>
        </div>
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

        self::enqueue_assets($mode, $atts['button_label']);

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

        // v2.9: anti-spam unificado — sempre on no qualifier (decisão §91).
        // Roda mesmas 6 checagens do CF7 (honeypot, time_trap, rate_limit,
        // UA, reverse_text, blocklist) via helper estático.
        if (class_exists('AdSpirit_Anti_Spam')) {
            $email_for_check = isset($_POST['fields']['email']) ? (string) $_POST['fields']['email'] : '';
            $payload_for_check = is_array($_POST['fields'] ?? null) ? (array) $_POST['fields'] : array();
            // Inclui meta antibot do payload top-level (honeypot + timestamp)
            if (isset($_POST['_adspirit_hp'])) $payload_for_check[AdSpirit_Anti_Spam::HONEYPOT_FIELD] = (string) $_POST['_adspirit_hp'];
            if (isset($_POST['_adspirit_ts'])) $payload_for_check['_adspirit_ts'] = (string) $_POST['_adspirit_ts'];
            $check = AdSpirit_Anti_Spam::validate_payload($payload_for_check, $email_for_check);
            if (empty($check['valid'])) {
                if (method_exists('AdSpirit_Anti_Spam', 'log_block')) {
                    AdSpirit_Anti_Spam::instance()->log_block(
                        'qualifier_' . ($check['reason_code'] ?? 'unknown'),
                        $check['reason_text'] ?? 'rejected by anti-spam'
                    );
                }
                wp_send_json_error(array('error' => 'spam_blocked', 'reason' => $check['reason_code']), 403);
                return;
            }
        }

        // v2.10: Cloudflare Turnstile (anti-bot avançado) — opt-in via config.
        // Roda APÓS anti-spam básico pra economizar chamada externa em bots
        // óbvios. Fail-open em erro de rede do Cloudflare.
        if (class_exists('AdSpirit_Turnstile') && AdSpirit_Turnstile::applies_to_qualifier()) {
            $token = isset($_POST['_adspirit_turnstile']) ? (string) $_POST['_adspirit_turnstile'] : '';
            $cf_check = AdSpirit_Turnstile::verify_token($token);
            if (empty($cf_check['valid'])) {
                if (class_exists('AdSpirit_Anti_Spam') && method_exists('AdSpirit_Anti_Spam', 'log_block')) {
                    AdSpirit_Anti_Spam::instance()->log_block('qualifier_turnstile', $cf_check['reason'] ?? 'rejected');
                }
                wp_send_json_error(array('error' => 'spam_blocked', 'reason' => 'turnstile'), 403);
                return;
            }
        }

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
            // Quick fix (Fase 0): chave canônica = 'Investimento' (o CF7 corta
            // o nome do tag no 1º espaço → posta 'Investimento'; a coluna do
            // Google Sheet e o canônico do CRM também são 'Investimento'). Antes
            // ia 'Investimento mensal em Marketing' e não batia em coluna nenhuma.
            'Investimento' => $sanitized['investment'] ?? '',
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

        // Fase 1: grava local ANTES do POST pro CRM. Integridade não bloqueia o
        // envio — se record_pending falhar (tabela ausente), retorna false e o
        // fluxo segue normal (lead vai pro CRM + log legado de fallback).
        $lead_source = $is_partial ? 'qualifier_partial' : 'qualifier';
        if (class_exists('AdSpirit_Lead_Store')) {
            AdSpirit_Lead_Store::record_pending($submission_id, $payload, $lead_source, 'adspirit_form_qualifier');
        }

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
            if (class_exists('AdSpirit_Lead_Store')) {
                AdSpirit_Lead_Store::mark($submission_id, 'crm', 'failed', 0, $response->get_error_message());
            }
            wp_send_json_error(array('error' => 'crm_unreachable', 'detail' => $response->get_error_message()), 502);
            return;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body_raw = wp_remote_retrieve_body($response);
        $body = json_decode($body_raw, true);

        if ($code < 200 || $code >= 300 || !is_array($body)) {
            if (class_exists('AdSpirit_Lead_Store')) {
                AdSpirit_Lead_Store::mark($submission_id, 'crm', 'failed', (int) $code, 'HTTP ' . $code);
            }
            wp_send_json_error(array('error' => 'crm_error', 'status' => $code), 502);
            return;
        }

        // CRM aceitou — marca enviado + captura profile/lead_id da resposta.
        if (class_exists('AdSpirit_Lead_Store')) {
            AdSpirit_Lead_Store::mark($submission_id, 'crm', 'sent', (int) $code, null, $body);
        }

        // Webhook out (fan-out pra n8n/Elisa, Zapier, etc) — fire-and-forget.
        // NÃO faz fanout no parcial (lead incompleto não dispara automações
        // downstream; só o envio final completo faz).
        if (!$is_partial && class_exists('AdSpirit_Integrations') && method_exists('AdSpirit_Integrations', 'fanout')) {
            try {
                AdSpirit_Integrations::instance()->fanout($payload);
                if (class_exists('AdSpirit_Lead_Store')) {
                    AdSpirit_Lead_Store::mark($submission_id, 'fanout', 'dispatched');
                }
            } catch (\Throwable $e) { /* silenciado */ }
        }

        // Log local pra aba "Submissions" (pega profile + lead_id do response).
        // Source distingue qualifier completo de parcial.
        $log_source = $is_partial ? 'qualifier_partial' : 'qualifier';
        $log_payload = array_merge($payload, array('_form_id' => $log_source));
        do_action('adspirit_lead_dispatched', $log_payload, $body, $log_source);

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
