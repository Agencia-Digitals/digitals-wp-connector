<?php
/**
 * AdSpirit Connector — coletor DOM genérico (rede de segurança).
 *
 * Padrão HubSpot "non-HubSpot forms": um form de builder que o plugin não
 * conhece (Elementor, Forminator, form artesanal do tema...) também entrega
 * o lead — captura no submit via JS + sendBeacon, com cara-de-lead exigida
 * (e-mail válido OU telefone). Os hooks dedicados (CF7, AdSpirit, Gravity,
 * WPForms) continuam PRIMÁRIOS: o JS ignora os forms deles.
 *
 * Beta, opt-in por site (`generic_forms_enabled`, default OFF — enriquecimento
 * nasce desligado). Entrega pelo dispatcher canônico do Lead Store (source
 * 'generic') → registro durável + retry do cron de graça.
 *
 * Sem nonce de propósito: sendBeacon em página cacheada não carrega nonce
 * fresco (mesma cicatriz do qualifier). Contenção: opt-in + rate limit por
 * IP real + anti-spam validate_payload + exigência de e-mail/telefone.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Generic_Collector {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action(
            'wp_enqueue_scripts',
            AdSpirit_Safe_Hook::action(array($this, 'enqueue_assets'), 'generic_enqueue')
        );
        add_action(
            'wp_ajax_adspirit_generic_capture',
            AdSpirit_Safe_Hook::action(array($this, 'handle_capture'), 'generic_capture')
        );
        add_action(
            'wp_ajax_nopriv_adspirit_generic_capture',
            AdSpirit_Safe_Hook::action(array($this, 'handle_capture'), 'generic_capture_nopriv')
        );
    }

    private static function enabled() {
        $core = AdSpirit_Settings::get_core();
        return ($core['generic_forms_enabled'] ?? '0') === '1';
    }

    public function enqueue_assets() {
        if (!self::enabled()) return;
        $version = defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : '2.30.0';
        wp_enqueue_script(
            'adspirit-generic-collector',
            ADSPIRIT_CONNECTOR_URL . 'assets/generic-collector.js',
            array(),
            $version,
            true
        );
        wp_add_inline_script(
            'adspirit-generic-collector',
            'window.__adspiritGenericCfg = ' . wp_json_encode(array(
                'endpoint' => admin_url('admin-ajax.php'),
            )) . ';',
            'before'
        );
    }

    public function handle_capture() {
        // Beacon não espera resposta — mas devolvemos cedo e barato em
        // qualquer recusa. Nunca ecoa dado do request (sem nonce).
        if (!self::enabled()) { wp_send_json_error(null, 403); }

        // Rate limit por IP real (helper canônico) — 10 capturas/min.
        $ip = class_exists('AdSpirit_Telemetry') ? AdSpirit_Telemetry::client_ip() : '';
        if ($ip === '') $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        if ($ip === '') { wp_send_json_error(null, 400); }
        $bucket = 'adspirit_generic_rl_' . md5($ip);
        $hits = (int) get_transient($bucket);
        if ($hits >= 10) { wp_send_json_error(null, 429); }
        set_transient($bucket, $hits + 1, 60);

        $raw = (string) file_get_contents('php://input');
        if ($raw === '' || strlen($raw) > 65536) { wp_send_json_error(null, 400); }
        $data = json_decode($raw, true);
        if (!is_array($data) || empty($data['fields']) || !is_array($data['fields'])) {
            wp_send_json_error(null, 400);
        }

        $payload = self::build_payload($data);
        if ($payload === null) { wp_send_json_error(null, 422); }

        // Mesmas 6 checagens de spam do CF7/qualifier (rate limit próprio já
        // rodou; aqui pega blocklist, UA, reverse-text etc). Spam → quarentena.
        if (class_exists('AdSpirit_Anti_Spam') && method_exists('AdSpirit_Anti_Spam', 'validate_payload')) {
            $check = AdSpirit_Anti_Spam::validate_payload($payload, (string) ($payload['your-email'] ?? ''));
            if (is_array($check) && isset($check['valid']) && !$check['valid']) {
                if (class_exists('AdSpirit_Lead_Store') && method_exists('AdSpirit_Lead_Store', 'record_spam')) {
                    AdSpirit_Lead_Store::record_spam($payload, 'generic', (string) ($data['form_hint'] ?? ''),
                        (string) ($check['reason_code'] ?? 'spam') . ': ' . (string) ($check['reason_text'] ?? ''));
                }
                wp_send_json_error(null, 422);
            }
        }

        // Registro durável ANTES do POST (parede-mestra) + dispatcher canônico.
        $submission_id = 'gc-' . time() . '-' . wp_generate_password(8, false);
        if (class_exists('AdSpirit_Lead_Store')) {
            AdSpirit_Lead_Store::record_pending($submission_id, $payload, 'generic', (string) ($data['form_hint'] ?? ''));
            $result = AdSpirit_Lead_Store::dispatch_to_crm($submission_id, $payload, 10);
            AdSpirit_Lead_Store::mark_crm_attempt($submission_id, $result);
            if (class_exists('AdSpirit_Lead_Identity') && !empty($payload['your-email'])) {
                AdSpirit_Lead_Identity::remember((string) $payload['your-email']);
            }
        }
        wp_send_json_success(null, 200);
    }

    /**
     * Campos crus do DOM → payload com as chaves canônicas do contrato
     * (your-name / your-email / Telefone). Sem e-mail E sem telefone o CRM
     * recusa — devolve null e nada é gravado (evita retry eterno de 422).
     */
    private static function build_payload(array $data) {
        $fields = $data['fields'];
        $payload = array();
        $email = ''; $phone = ''; $name = '';

        foreach ($fields as $key => $value) {
            if (!is_string($key) || !is_scalar($value)) continue;
            $key = substr(trim($key), 0, 100);
            $value = substr(trim((string) $value), 0, 500);
            if ($key === '' || $value === '') continue;
            $kl = strtolower($key);
            // Nunca aceitar dado sensível de form desconhecido.
            if (preg_match('/(password|senha|card|cart[aã]o|cvv|cvc|ssn)/', $kl)) continue;

            if ($email === '' && is_email($value)) {
                $email = sanitize_email($value);
                continue;
            }
            if ($phone === '' && preg_match('/(phone|tel|whats|celular|fone)/', $kl)
                && preg_match('/^[\d\s()+.\-]{8,20}$/', $value)) {
                $phone = $value;
                continue;
            }
            if ($name === '' && preg_match('/(^|[_\-])(name|nome)($|[_\-])/', $kl)
                && !preg_match('/(company|empresa|user|usuario)/', $kl)) {
                $name = sanitize_text_field($value);
                continue;
            }
            $payload[sanitize_key($key)] = sanitize_text_field($value);
        }

        if ($email === '' && $phone === '') return null;
        if ($name !== '')  $payload['your-name'] = $name;
        if ($email !== '') $payload['your-email'] = $email;
        if ($phone !== '') $payload['Telefone'] = $phone;
        $payload['_adspirit_source'] = 'generic_collector';
        $payload['_adspirit_page'] = esc_url_raw(substr((string) ($data['page'] ?? ''), 0, 300));
        return $payload;
    }
}
