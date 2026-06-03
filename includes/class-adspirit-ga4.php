<?php
/**
 * AdSpirit Connector — GA4 Measurement Protocol v2 (server-side).
 *
 * Dispara eventos GA4 direto do servidor, paralelo ao gtag client-side.
 *
 * Eventos suportados v1:
 *   - page_view (opt-in, throttled)
 *   - generate_lead (CF7 submit)
 *
 * Endpoint: https://www.google-analytics.com/mp/collect
 *   ?measurement_id={G-XXXXXX}&api_secret={secret}
 *
 * client_id: cookie _ga (parse) ou hash IP+UA fallback.
 *
 * Docs: https://developers.google.com/analytics/devguides/collection/protocol/ga4
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Ga4 {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action(
            'adspirit_connector_render_tab_ga4',
            AdSpirit_Safe_Hook::action(array($this, 'render_tab'), 'ga4_tab')
        );
        add_action(
            'adspirit_connector_save_ga4',
            AdSpirit_Safe_Hook::action(array($this, 'handle_save'), 'ga4_save')
        );
        add_action(
            'wp',
            AdSpirit_Safe_Hook::action(array($this, 'maybe_send_page_view'), 'ga4_page_view'),
            99
        );
    }

    public static function send_lead_for_submission($submission_id, array $data, $referrer_url = '') {
        $cfg = AdSpirit_Settings::get_ga4();
        if ($cfg['enabled'] !== '1' || $cfg['send_lead'] !== '1') return null;
        if (empty($cfg['measurement_id']) || empty($cfg['api_secret'])) return null;

        $event = array(
            'name'   => 'generate_lead',
            'params' => array(
                'currency'      => 'BRL',
                'value'         => 0,
                'engagement_time_msec' => 1,
                'page_location' => $referrer_url ?: home_url('/'),
                'event_id'      => $submission_id,
            ),
        );
        return self::dispatch($cfg, $event);
    }

    public function maybe_send_page_view() {
        $cfg = AdSpirit_Settings::get_ga4();
        if ($cfg['enabled'] !== '1' || $cfg['send_page_view'] !== '1') return;
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return;
        if (empty($cfg['measurement_id']) || empty($cfg['api_secret'])) return;

        $client_id = self::client_id();
        $throttle_key = 'adspirit_ga4_pv_' . md5($client_id . '|' . self::current_url());
        if (get_transient($throttle_key)) return;
        set_transient($throttle_key, 1, 30);

        $event = array(
            'name'   => 'page_view',
            'params' => array(
                'page_location' => self::current_url(),
                'engagement_time_msec' => 1,
            ),
        );
        self::dispatch($cfg, $event);
    }

    private static function dispatch(array $cfg, array $event) {
        $url = 'https://www.google-analytics.com/mp/collect?measurement_id='
            . urlencode($cfg['measurement_id'])
            . '&api_secret='
            . urlencode($cfg['api_secret']);

        $body = array(
            'client_id' => self::client_id(),
            'events'    => array($event),
        );

        $args = array(
            'timeout'  => 6,
            'blocking' => false,
            'headers'  => array('Content-Type' => 'application/json'),
            'body'     => wp_json_encode($body),
        );
        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            error_log('[AdSpirit Connector] GA4 dispatch failed: ' . $response->get_error_message());
            return false;
        }
        return true;
    }

    /**
     * Tenta extrair client_id de cookie _ga (formato "GA1.1.<cid1>.<cid2>").
     * Fallback: hash IP+UA (não-determinístico mas estável por sessão).
     */
    public static function client_id() {
        $ga_cookie = isset($_COOKIE['_ga']) ? (string) $_COOKIE['_ga'] : '';
        if ($ga_cookie) {
            $parts = explode('.', $ga_cookie);
            if (count($parts) >= 4) {
                return $parts[count($parts) - 2] . '.' . $parts[count($parts) - 1];
            }
        }
        if (!empty($_COOKIE['adspirit_vid'])) return (string) $_COOKIE['adspirit_vid'];
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 40) : '';
        return md5($ip . '|' . $ua);
    }

    private static function current_url() {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $uri   = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        return $proto . '://' . $host . $uri;
    }

    public function render_tab() {
        $c = AdSpirit_Settings::get_ga4();
        AdSpirit_Menu::form_open('ga4');
        ?>
        <h2>Google Analytics 4 (server-side)</h2>
        <p class="description">
            Dispara eventos GA4 via Measurement Protocol v2 direto do servidor. Funciona em paralelo ao gtag.js client-side se quiser dedupe.
        </p>

        <table class="form-table">
            <tr>
                <th>Status</th>
                <td>
                    <label>
                        <input type="checkbox" name="enabled" value="1" <?php checked($c['enabled'], '1'); ?>>
                        Ativar GA4 server-side
                    </label>
                </td>
            </tr>
            <tr>
                <th><label for="ga4_measurement_id">Measurement ID</label></th>
                <td>
                    <input type="text" id="ga4_measurement_id" name="measurement_id" value="<?php echo esc_attr($c['measurement_id']); ?>" class="regular-text" placeholder="G-XXXXXXXXXX">
                    <p class="description">GA4 Admin → Data Streams → Web stream → Measurement ID.</p>
                </td>
            </tr>
            <tr>
                <th><label for="ga4_api_secret">API Secret</label></th>
                <td>
                    <input type="password" id="ga4_api_secret" name="api_secret" value="<?php echo esc_attr($c['api_secret']); ?>" class="regular-text" autocomplete="off">
                    <button type="button" class="button" onclick="var e=document.getElementById('ga4_api_secret');e.type=e.type==='password'?'text':'password';">Mostrar</button>
                    <p class="description">GA4 Admin → Data Streams → Web stream → Measurement Protocol API secrets → Create.</p>
                </td>
            </tr>
            <tr>
                <th>Eventos</th>
                <td>
                    <label>
                        <input type="checkbox" name="send_lead" value="1" <?php checked($c['send_lead'], '1'); ?>>
                        Disparar <code>generate_lead</code> em cada CF7 submit
                    </label><br>
                    <label>
                        <input type="checkbox" name="send_page_view" value="1" <?php checked($c['send_page_view'], '1'); ?>>
                        Disparar <code>page_view</code> em cada page load (throttle 30s/URL/cliente)
                    </label>
                </td>
            </tr>
        </table>
        <?php
        AdSpirit_Menu::form_close('Salvar GA4');
    }

    public function handle_save($post) {
        $patch = array();
        $patch['enabled']        = !empty($post['enabled']) ? '1' : '0';
        $patch['measurement_id'] = sanitize_text_field((string) ($post['measurement_id'] ?? ''));
        $patch['api_secret']     = sanitize_text_field((string) ($post['api_secret'] ?? ''));
        $patch['send_lead']      = !empty($post['send_lead']) ? '1' : '0';
        $patch['send_page_view'] = !empty($post['send_page_view']) ? '1' : '0';
        AdSpirit_Settings::update_ga4($patch);
        add_settings_error(AdSpirit_Settings::OPTION_GA4, 'saved', 'GA4 salvo.', 'updated');
    }
}
