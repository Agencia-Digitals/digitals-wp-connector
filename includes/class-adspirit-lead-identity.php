<?php
/**
 * AdSpirit Connector — identidade do visitante + status do lead (pull).
 *
 * Webhook CRM→WP fatia 1, modelo WP Fusion: depois de um submit com e-mail,
 * o site "lembra" o visitante (cookie HttpOnly com o e-mail CIFRADO — nunca
 * em claro no browser) e pode perguntar ao AdSpirit que status ele tem:
 * perfil, is_customer (deal ganho), estágio aberto. Casos de uso âncora:
 *
 *   - SUPRESSÃO: esconder captação pra quem já é cliente, via CSS
 *     (`.adspirit-lead-customer .minha-cta { display:none }`) — as classes
 *     entram no <html> pelo JS.
 *   - GATING: [adspirit_if_lead customer="yes"]…[/adspirit_if_lead]
 *     (atrs: customer=yes|no, profile="A,B", known=yes).
 *   - PERSONALIZAÇÃO JS: window.__adspiritLead = {known, customer, profile}.
 *
 * Performance: NADA bloqueia o render. Shortcode/body usam só o cache; o
 * fetch remoto acontece via admin-ajax disparado pelo JS (1x/h por
 * visitante) e aquece o cache pras próximas pageviews. Fail-soft total:
 * CRM antigo sem a rota (404) = visitante desconhecido, site como sempre.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Lead_Identity {
    const COOKIE = 'adspirit_li';
    const COOKIE_TTL = 15552000; // 180d

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action(
            'wp_enqueue_scripts',
            AdSpirit_Safe_Hook::action(array($this, 'enqueue_assets'), 'lead_identity_enqueue')
        );
        add_action(
            'wp_ajax_adspirit_lead_status',
            AdSpirit_Safe_Hook::action(array($this, 'handle_status_ajax'), 'lead_status_ajax')
        );
        add_action(
            'wp_ajax_nopriv_adspirit_lead_status',
            AdSpirit_Safe_Hook::action(array($this, 'handle_status_ajax'), 'lead_status_ajax_nopriv')
        );
        add_shortcode('adspirit_if_lead', array($this, 'shortcode_if_lead'));
    }

    // ─── Cifra do cookie (e-mail nunca em claro no browser) ───

    private static function crypto_key() {
        return hash('sha256', wp_salt('auth') . '|adspirit-li', true);
    }

    private static function encrypt_email($email) {
        if (!function_exists('openssl_encrypt')) return '';
        $iv = random_bytes(16);
        $ct = openssl_encrypt((string) $email, 'aes-256-cbc', self::crypto_key(), OPENSSL_RAW_DATA, $iv);
        if ($ct === false) return '';
        return rtrim(strtr(base64_encode($iv . $ct), '+/', '-_'), '=');
    }

    private static function decrypt_email($blob) {
        if (!function_exists('openssl_decrypt') || !is_string($blob) || $blob === '') return '';
        $raw = base64_decode(strtr($blob, '-_', '+/'));
        if ($raw === false || strlen($raw) < 17) return '';
        $pt = openssl_decrypt(substr($raw, 16), 'aes-256-cbc', self::crypto_key(), OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return is_string($pt) && is_email($pt) ? strtolower($pt) : '';
    }

    /**
     * Lembra o visitante depois de um submit com e-mail. Chamar nos handlers
     * de submit (qualifier, form nativo, CF7, coletor) — nunca no cron.
     */
    public static function remember($email) {
        $email = strtolower(trim((string) $email));
        if ($email === '' || !is_email($email)) return;
        if (headers_sent() || (function_exists('wp_doing_cron') && wp_doing_cron())) return;
        $blob = self::encrypt_email($email);
        if ($blob === '') return;
        setcookie(self::COOKIE, $blob, array(
            'expires'  => time() + self::COOKIE_TTL,
            'path'     => '/',
            'secure'   => is_ssl(),
            'httponly' => true,
            'samesite' => 'Lax',
        ));
    }

    /** E-mail do visitante atual ('' se desconhecido). */
    private static function current_email() {
        if (empty($_COOKIE[self::COOKIE])) return '';
        return self::decrypt_email((string) $_COOKIE[self::COOKIE]);
    }

    // ─── Status (cache-first; remoto só no AJAX) ───

    /**
     * Status do visitante atual. $allow_remote=false (default) lê SÓ o
     * cache — é o modo dos caminhos de render (shortcode), que nunca podem
     * bloquear a página. O AJAX usa true e aquece o cache.
     * Retorna array {known, customer, profile, lead_status, stage_type}
     * ou null (desconhecido / cache frio).
     */
    public static function status($allow_remote = false) {
        $email = self::current_email();
        if ($email === '') return null;
        $key = 'adspirit_ls_' . md5($email);
        $cached = get_transient($key);
        if (is_array($cached)) return empty($cached['miss']) ? $cached : null;
        if (!$allow_remote) return null;

        $core = AdSpirit_Settings::get_core();
        if (empty($core['endpoint_url']) || empty($core['brand_slug']) || empty($core['secret'])) return null;
        $url = rtrim((string) $core['endpoint_url'], '/')
            . '/api/wp/lead-status?brand_slug=' . rawurlencode((string) $core['brand_slug'])
            . '&email=' . rawurlencode($email);
        $resp = wp_remote_get($url, array(
            'timeout' => 5,
            'headers' => array(
                'x-cf7-secret' => (string) $core['secret'],
                'User-Agent'   => 'AdSpirit-Connector/' . (defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : ''),
            ),
        ));
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            set_transient($key, array('miss' => true), 600); // CRM antigo/fora: re-tenta em 10 min
            return null;
        }
        $body = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($body)) {
            set_transient($key, array('miss' => true), 600);
            return null;
        }
        $status = array(
            'known'       => !empty($body['found']),
            'customer'    => !empty($body['is_customer']),
            'profile'     => isset($body['profile']) && is_string($body['profile']) ? $body['profile'] : '',
            'lead_status' => isset($body['lead_status']) && is_string($body['lead_status']) ? $body['lead_status'] : '',
            'stage_type'  => isset($body['stage_type']) && is_string($body['stage_type']) ? $body['stage_type'] : '',
        );
        set_transient($key, $status, 3600);
        return $status['known'] ? $status : null;
    }

    // ─── Front: JS só quando o visitante é conhecido ───

    public function enqueue_assets() {
        if (self::current_email() === '') return;
        $version = defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : '2.30.0';
        wp_enqueue_script(
            'adspirit-lead-status',
            ADSPIRIT_CONNECTOR_URL . 'assets/lead-status.js',
            array(),
            $version,
            true
        );
        // Se o cache já está quente, o JS nem chama o AJAX.
        $cached = self::status(false);
        wp_add_inline_script(
            'adspirit-lead-status',
            'window.__adspiritLeadCfg = ' . wp_json_encode(array(
                'endpoint' => admin_url('admin-ajax.php'),
                'cached'   => $cached, // null = frio, o JS busca
            )) . ';',
            'before'
        );
    }

    /** AJAX: busca (com remoto permitido) e devolve só flags não-PII. */
    public function handle_status_ajax() {
        $status = self::status(true);
        if ($status === null) {
            wp_send_json_success(array('known' => false));
        }
        wp_send_json_success($status);
    }

    // ─── Gating server-side (cache-only, nunca bloqueia) ───

    /**
     * [adspirit_if_lead customer="yes" profile="A,B" known="yes"]…[/…]
     * Todas as condições presentes precisam bater (AND). Cache frio =
     * visitante tratado como desconhecido (o AJAX aquece pra próxima).
     */
    public function shortcode_if_lead($atts, $content = '') {
        $atts = shortcode_atts(array(
            'known'    => '',
            'customer' => '',
            'profile'  => '',
        ), $atts, 'adspirit_if_lead');
        $st = self::status(false);
        $known = is_array($st) && !empty($st['known']);
        $customer = $known && !empty($st['customer']);
        $profile = $known ? (string) $st['profile'] : '';

        if ($atts['known'] !== '' && (($atts['known'] === 'yes') !== $known)) return '';
        if ($atts['customer'] !== '' && (($atts['customer'] === 'yes') !== $customer)) return '';
        if ($atts['profile'] !== '') {
            $wanted = array_map('trim', explode(',', strtoupper((string) $atts['profile'])));
            if (!in_array(strtoupper($profile), $wanted, true)) return '';
        }
        return do_shortcode((string) $content);
    }
}
