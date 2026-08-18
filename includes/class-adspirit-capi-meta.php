<?php
/**
 * AdSpirit Connector — Meta Conversion API server-side.
 *
 * Dispara eventos pro Facebook/Meta Pixel via server-side (Graph API)
 * em paralelo ao envio pro CRM. Sobrevive a ad-blockers + reduz latência.
 *
 * Eventos suportados v1:
 *   - PageView: a cada page load (opt-in)
 *   - Lead: a cada CF7 submit (opt-in)
 *
 * Endpoint: https://graph.facebook.com/v22.0/{pixel_id}/events
 *
 * Hash: email, phone (E.164 sem +). User data inclui fbc/fbp (cookies),
 * client_ip_address, client_user_agent.
 *
 * Event ID: igual ao submission_id do CF7 — Meta dedupa quando o pixel
 * client-side também dispara o mesmo evento.
 *
 * Docs: https://developers.facebook.com/docs/marketing-api/conversions-api
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Capi_Meta {
    const GRAPH_VERSION = 'v22.0';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action(
            'adspirit_connector_render_tab_capi-meta',
            AdSpirit_Safe_Hook::action(array($this, 'render_tab'), 'capi_meta_tab')
        );
        add_action(
            'adspirit_connector_save_capi-meta',
            AdSpirit_Safe_Hook::action(array($this, 'handle_save'), 'capi_meta_save')
        );
        // Page view via hook wp (depois do template_redirect)
        add_action(
            'wp',
            AdSpirit_Safe_Hook::action(array($this, 'maybe_send_page_view'), 'capi_meta_page_view'),
            99
        );
    }

    /**
     * Critério único de "CAPI pronta pra disparar": enabled + pixel_id +
     * access_token. Gate de envio, badge da aba e Setup Wizard usam este
     * método — a regra não pode viver em mais de um lugar.
     */
    public static function is_configured($cfg = null) {
        if (!is_array($cfg)) $cfg = AdSpirit_Settings::get_capi_meta();
        return !empty($cfg['enabled']) && $cfg['enabled'] === '1'
            && !empty($cfg['pixel_id']) && !empty($cfg['access_token']);
    }

    /**
     * Chamado pelo CF7 handler quando lead é enviado pro CRM.
     */
    public static function send_lead_for_submission($submission_id, array $data, $referrer_url = '') {
        $cfg = AdSpirit_Settings::get_capi_meta();
        if (!self::is_configured($cfg) || $cfg['send_lead'] !== '1') return null;

        $user_data = self::build_user_data($data);
        $event = array(
            'event_name'       => 'Lead',
            'event_time'       => time(),
            'event_id'         => $submission_id, // dedup com pixel client-side
            'action_source'    => 'website',
            'event_source_url' => $referrer_url ?: home_url('/'),
            'user_data'        => $user_data,
            'custom_data'      => array(
                'currency' => 'BRL',
            ),
        );
        return self::dispatch($event, 'Lead');
    }

    public function maybe_send_page_view() {
        $cfg = AdSpirit_Settings::get_capi_meta();
        if (!self::is_configured($cfg) || $cfg['send_page_view'] !== '1') return;
        if (is_admin() || wp_doing_ajax() || wp_doing_cron()) return;

        // Throttling: 1 page_view por session_id por minuto (transient)
        $session_id = self::session_id();
        $throttle_key = 'adspirit_pv_' . md5($session_id);
        if (get_transient($throttle_key)) return;
        set_transient($throttle_key, 1, 60);

        $event = array(
            'event_name'       => 'PageView',
            'event_time'       => time(),
            'event_id'         => $session_id . '-' . dechex(time()),
            'action_source'    => 'website',
            'event_source_url' => self::current_url(),
            'user_data'        => self::build_user_data(array()),
        );
        self::dispatch($event, 'PageView');
    }

    private static function dispatch(array $event, $event_label) {
        $cfg = AdSpirit_Settings::get_capi_meta();
        $url = 'https://graph.facebook.com/' . self::GRAPH_VERSION . '/' . urlencode($cfg['pixel_id']) . '/events';

        $body = array(
            'data' => array($event),
            'access_token' => $cfg['access_token'],
        );
        if (!empty($cfg['test_event_code'])) {
            $body['test_event_code'] = $cfg['test_event_code'];
        }

        $args = array(
            'timeout'  => 6,
            'blocking' => false, // fire-and-forget
            'headers'  => array('Content-Type' => 'application/json'),
            'body'     => wp_json_encode($body),
        );
        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            error_log('[AdSpirit Connector] CAPI Meta dispatch failed: ' . $response->get_error_message());
            return false;
        }
        return true;
    }

    private static function build_user_data(array $data) {
        $email = isset($data['your-email']) ? strtolower(trim((string) $data['your-email'])) : '';
        $phone = isset($data['Telefone']) ? preg_replace('/\D/', '', (string) $data['Telefone']) : '';
        $u = array();
        if ($email) $u['em'] = array(hash('sha256', $email));
        if ($phone) $u['ph'] = array(hash('sha256', $phone));
        $u['client_ip_address'] = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
        $u['client_user_agent'] = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        if (!empty($_COOKIE['_fbc'])) $u['fbc'] = (string) $_COOKIE['_fbc'];
        if (!empty($_COOKIE['_fbp'])) $u['fbp'] = (string) $_COOKIE['_fbp'];
        return $u;
    }

    private static function session_id() {
        if (!empty($_COOKIE['_fbp'])) return (string) $_COOKIE['_fbp'];
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
        $c = AdSpirit_Settings::get_capi_meta();
        if (self::is_configured($c)) {
            $status_badge = '<span class="as-badge ok">Ativo</span>';
        } elseif ($c['enabled'] === '1') {
            $missing = array();
            if (empty($c['pixel_id'])) $missing[] = 'o Pixel ID';
            if (empty($c['access_token'])) $missing[] = 'a chave de acesso';
            $status_badge = '<span class="as-badge warn">Incompleto — falta ' . esc_html(implode(' e ', $missing)) . '</span>';
        } else {
            $status_badge = '<span class="as-badge muted">Desligado</span>';
        }
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Meta CAPI</span>Conversões Meta</h2>
        <p class="as-section-help">Envia leads e visitas pro Facebook/Instagram direto do servidor — funciona mesmo com bloqueador de anúncios.</p>

        <?php AdSpirit_Menu::card_open('Credenciais e eventos', 'As duas chaves você gera no Events Manager do Meta', $status_badge); ?>
        <?php AdSpirit_Menu::form_open('capi-meta'); ?>

        <div class="as-toggle">
            <input type="checkbox" id="meta_enabled" name="enabled" value="1" <?php checked($c['enabled'], '1'); ?>>
            <label class="t" for="meta_enabled">Envio pro Meta ligado<small>Precisa do Pixel ID e da chave de acesso abaixo pra começar a enviar.</small></label>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="meta_pixel_id">Pixel ID</label>
            <input type="text" id="meta_pixel_id" name="pixel_id" value="<?php echo esc_attr($c['pixel_id']); ?>" class="regular-text" pattern="[0-9]+">
            <p class="description">Só números. Está no Events Manager → Data Sources → seu pixel.</p>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="meta_access_token">Chave de acesso (Access Token)</label>
            <input type="password" id="meta_access_token" name="access_token" value="<?php echo esc_attr($c['access_token']); ?>" class="regular-text" autocomplete="off">
            <button type="button" class="button" onclick="var e=document.getElementById('meta_access_token');e.type=e.type==='password'?'text':'password';">Mostrar</button>
            <p class="description">Gere em Events Manager → Settings → Conversions API → Generate Access Token.</p>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="meta_test_event_code">Código de teste (opcional)</label>
            <input type="text" id="meta_test_event_code" name="test_event_code" value="<?php echo esc_attr($c['test_event_code']); ?>" class="regular-text">
            <p class="description">Com um código aqui, os eventos aparecem em "Test Events" no Events Manager pra você conferir.</p>
        </div>

        <div class="as-toggle">
            <input type="checkbox" id="meta_send_lead" name="send_lead" value="1" <?php checked($c['send_lead'], '1'); ?>>
            <label class="t" for="meta_send_lead">Enviar cada lead<small>Dispara o evento <code>Lead</code> quando um formulário é enviado.</small></label>
        </div>

        <div class="as-toggle">
            <input type="checkbox" id="meta_send_page_view" name="send_page_view" value="1" <?php checked($c['send_page_view'], '1'); ?>>
            <label class="t" for="meta_send_page_view">Enviar cada visita de página<small>Dispara o evento <code>PageView</code>, no máximo 1 por minuto por visitante.</small></label>
        </div>

        <details class="as-help">
            <summary>Como funciona junto com o pixel do site</summary>
            <ul>
                <li>O envio sai do servidor, então conta conversões mesmo quando o visitante usa bloqueador de anúncios.</li>
                <li>Se o pixel do site também disparar o mesmo evento, o Meta reconhece e não conta em dobro (mesmo <code>event_id</code>).</li>
                <li>A chave de acesso precisa ser de um system user com permissão <code>ads_management</code>.</li>
            </ul>
        </details>

        <?php AdSpirit_Menu::form_close('Salvar Meta CAPI'); ?>
        <?php AdSpirit_Menu::card_close(); ?>
        <?php
    }

    public function handle_save($post) {
        $patch = array();
        $patch['enabled']         = !empty($post['enabled']) ? '1' : '0';
        $patch['pixel_id']        = preg_replace('/\D/', '', (string) ($post['pixel_id'] ?? ''));
        $patch['access_token']    = sanitize_text_field((string) ($post['access_token'] ?? ''));
        $patch['test_event_code'] = sanitize_text_field((string) ($post['test_event_code'] ?? ''));
        $patch['send_lead']       = !empty($post['send_lead']) ? '1' : '0';
        $patch['send_page_view']  = !empty($post['send_page_view']) ? '1' : '0';
        AdSpirit_Settings::update_capi_meta($patch);
        add_settings_error(AdSpirit_Settings::OPTION_CAPI_META, 'saved', 'Meta CAPI salvo.', 'updated');
    }
}
