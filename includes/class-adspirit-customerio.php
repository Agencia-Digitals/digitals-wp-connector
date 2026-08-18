<?php
/**
 * AdSpirit Connector — Customer.io passthrough dedicado.
 *
 * Identifica o lead + dispara evento `form_submitted` no Customer.io
 * em paralelo ao envio pro CRM. Sobrevive a ad-blockers (tudo
 * server-side) e antecipa o customer journey antes do CRM processar.
 *
 * Endpoints Customer.io Track API v1:
 *   - PUT  https://track.customer.io/api/v1/customers/{email}
 *          → identify (cria/atualiza customer)
 *   - POST https://track.customer.io/api/v1/customers/{email}/events
 *          → event `form_submitted` com payload do form
 *
 * Auth: Basic Auth com `Site ID:API Key` (App API key NÃO funciona aqui —
 * o Tracking API exige Site ID + Tracking API Key da workspace).
 *
 * Estratégia: fire-and-forget igual CAPI/GA4. Falha não bloqueia o submit
 * nem o dispatch pro CRM. Tudo loga em error_log com prefixo.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Customerio {
    const DEFAULT_BASE_URL = 'https://track.customer.io/api/v1';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action(
            'adspirit_connector_render_tab_customerio',
            AdSpirit_Safe_Hook::action(array($this, 'render_tab'), 'customerio_tab')
        );
        add_action(
            'adspirit_connector_save_customerio',
            AdSpirit_Safe_Hook::action(array($this, 'handle_save'), 'customerio_save')
        );
        // Registra tab no router — FILTER (não action) pra preservar array
        add_filter(
            'adspirit_connector_tabs',
            AdSpirit_Safe_Hook::filter(array($this, 'register_tab'), 'customerio_register_tab')
        );
        // Endpoint admin-post pra Testar conexão
        add_action(
            'admin_post_adspirit_customerio_test',
            AdSpirit_Safe_Hook::action(array($this, 'handle_test_connection'), 'customerio_test')
        );
    }

    public function register_tab($tabs) {
        if (!is_array($tabs)) return $tabs;
        $tabs['customerio'] = 'Customer.io';
        return $tabs;
    }

    // ─────────────────────────────────────────────────────────
    // SETTINGS (option key: adspirit_connector_customerio)
    // ─────────────────────────────────────────────────────────
    const OPTION_KEY = 'adspirit_connector_customerio';

    public static function defaults() {
        return array(
            'enabled'            => '0',
            'site_id'            => '',
            'api_key'            => '',
            'tracking_api_url'   => self::DEFAULT_BASE_URL,
            'identify_on_submit' => '1',
            'event_on_submit'    => '1',
            'event_name'         => 'form_submitted',
        );
    }

    public static function get_config() {
        $stored = get_option(self::OPTION_KEY, array());
        return wp_parse_args(is_array($stored) ? $stored : array(), self::defaults());
    }

    public static function update_config(array $patch) {
        $current = self::get_config();
        update_option(self::OPTION_KEY, array_merge($current, $patch), false);
    }

    // ─────────────────────────────────────────────────────────
    // DISPATCH — chamado externamente pelo Cf7_Handler / Form
    // ─────────────────────────────────────────────────────────
    /**
     * Identifica + dispara event no Customer.io a partir do payload do form.
     *
     * @param array $payload Dados do form (canonical, conforme CRM espera).
     *                       Espera-se que 'your-email' ou 'Email' esteja
     *                       presente — sem email, no-op.
     * @return bool|null true=disparado, false=erro, null=skipped (config off / sem email)
     */
    public static function dispatch_for_payload(array $payload) {
        $cfg = self::get_config();
        if ($cfg['enabled'] !== '1') return null;
        if (empty($cfg['site_id']) || empty($cfg['api_key'])) return null;

        $email = self::extract_email($payload);
        if (!$email) {
            error_log('[AdSpirit Connector] Customer.io dispatch skipped: no email in payload');
            return null;
        }

        $ok_identify = true;
        $ok_event    = true;

        // 1) PUT identify
        if ($cfg['identify_on_submit'] === '1') {
            $ok_identify = self::send_identify($cfg, $email, $payload);
        }

        // 2) POST event form_submitted
        if ($cfg['event_on_submit'] === '1') {
            $event_name = !empty($cfg['event_name'])
                ? sanitize_key($cfg['event_name'])
                : 'form_submitted';
            $ok_event = self::send_event($cfg, $email, $event_name, $payload);
        }

        return ($ok_identify !== false && $ok_event !== false);
    }

    private static function send_identify(array $cfg, $email, array $payload) {
        $base = self::normalize_base_url($cfg['tracking_api_url']);
        $url  = $base . '/customers/' . rawurlencode($email);

        $traits = self::build_identify_traits($payload, $email);

        return self::http_request($cfg, 'PUT', $url, $traits);
    }

    private static function send_event(array $cfg, $email, $event_name, array $payload) {
        $base = self::normalize_base_url($cfg['tracking_api_url']);
        $url  = $base . '/customers/' . rawurlencode($email) . '/events';

        $body = array(
            'name' => $event_name,
            'data' => self::build_event_data($payload),
        );

        return self::http_request($cfg, 'POST', $url, $body);
    }

    /**
     * Mapeia payload do form pra atributos do customer (identify traits).
     * Customer.io aceita qualquer atributo — vamos enviar nome, telefone,
     * empresa, e tudo mais que vier sem ruído.
     */
    private static function build_identify_traits(array $payload, $email) {
        $traits = array(
            'email'      => $email,
            'created_at' => time(), // só impacta na 1ª vez
        );

        // Mapping canonical → trait amigável pro Customer.io
        $map = array(
            'your-name'             => 'name',
            'Nome'                  => 'name',
            'Telefone'              => 'phone',
            'phone'                 => 'phone',
            'empresa'               => 'company',
            'Empresa'               => 'company',
            'cargo'                 => 'job_title',
            'Cargo'                 => 'job_title',
            'Numero-funcionarios'   => 'company_size',
            'nicho'                 => 'industry',
            'site-empresa'          => 'company_website',
            'Investimento'          => 'budget',
            'urgencia para começar' => 'urgency',
        );

        foreach ($map as $src => $dst) {
            if (isset($payload[$src]) && $payload[$src] !== '') {
                $val = is_scalar($payload[$src]) ? (string) $payload[$src] : wp_json_encode($payload[$src]);
                $traits[$dst] = $val;
            }
        }

        // Telemetria (utm, referrer, etc) — se Cf7 anexou
        if (!empty($payload['_adspirit_telemetry']) && is_array($payload['_adspirit_telemetry'])) {
            $tel = $payload['_adspirit_telemetry'];
            foreach (array('utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term', 'referrer', 'landing_page') as $k) {
                if (!empty($tel[$k])) {
                    $traits[$k] = (string) $tel[$k];
                }
            }
        }

        return $traits;
    }

    /**
     * Payload do event = tudo que veio do form, exceto telemetria interna.
     * Customer.io guarda como event properties e fica acessível em segmentos.
     */
    private static function build_event_data(array $payload) {
        $data = array();
        foreach ($payload as $k => $v) {
            if ($k === '_adspirit_telemetry') continue;
            // Customer.io aceita qualquer key — só normaliza pra string/scalar
            if (is_scalar($v)) {
                $data[$k] = (string) $v;
            } elseif (is_array($v)) {
                $data[$k] = wp_json_encode($v);
            }
        }
        // Anexa form_id + url se vieram do Cf7_Handler
        if (!empty($payload['cf7_url']))  $data['form_url']  = (string) $payload['cf7_url'];
        if (!empty($payload['cf7_time'])) $data['form_time'] = (string) $payload['cf7_time'];
        return $data;
    }

    /**
     * Wrapper HTTP único pra PUT/POST. Basic Auth com Site ID:API Key.
     * Fire-and-forget por padrão (blocking=false) — caller passa $blocking=true
     * pra "Testar conexão" no admin.
     */
    private static function http_request(array $cfg, $method, $url, array $body, $blocking = false) {
        $auth = base64_encode($cfg['site_id'] . ':' . $cfg['api_key']);
        $args = array(
            'method'   => strtoupper($method),
            'timeout'  => 8,
            'blocking' => (bool) $blocking,
            'headers'  => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic ' . $auth,
                'User-Agent'    => 'AdSpirit-Connector/' . (defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : 'dev'),
            ),
            'body'     => wp_json_encode($body),
        );
        $response = wp_remote_request($url, $args);
        if (is_wp_error($response)) {
            error_log('[AdSpirit Connector] Customer.io ' . $method . ' failed: ' . $response->get_error_message());
            return false;
        }
        return $response;
    }

    private static function extract_email(array $payload) {
        foreach (array('your-email', 'Email', 'email') as $k) {
            if (!empty($payload[$k]) && is_string($payload[$k])) {
                $email = sanitize_email(trim(strtolower($payload[$k])));
                if ($email && is_email($email)) return $email;
            }
        }
        return null;
    }

    private static function normalize_base_url($url) {
        $url = trim((string) $url);
        if ($url === '') return self::DEFAULT_BASE_URL;
        return rtrim($url, '/');
    }

    // ─────────────────────────────────────────────────────────
    // TEST CONNECTION (admin-post handler)
    // ─────────────────────────────────────────────────────────
    public function handle_test_connection() {
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) wp_die('forbidden', 403);
        check_admin_referer('adspirit_customerio_test', '_adspirit_nonce');

        $cfg = self::get_config();
        $redirect = admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=customerio');

        if (empty($cfg['site_id']) || empty($cfg['api_key'])) {
            set_transient('adspirit_customerio_test_result', array(
                'ok'  => false,
                'msg' => 'Configure Site ID + API Key antes de testar.',
            ), 60);
            wp_safe_redirect($redirect);
            exit;
        }

        // Ping com identify dummy num email throwaway. Customer.io retorna 200
        // pra qualquer identify válido — usamos isso pra validar credenciais.
        $base = self::normalize_base_url($cfg['tracking_api_url']);
        $admin_email = get_option('admin_email');
        $test_email = $admin_email && is_email($admin_email)
            ? $admin_email
            : 'adspirit-test@example.com';
        $url = $base . '/customers/' . rawurlencode($test_email);

        $response = self::http_request($cfg, 'PUT', $url, array(
            'email'                    => $test_email,
            'adspirit_test_connection' => time(),
        ), true /* blocking */);

        if ($response === false || is_wp_error($response)) {
            $msg = is_wp_error($response) ? $response->get_error_message() : 'erro desconhecido';
            set_transient('adspirit_customerio_test_result', array(
                'ok'  => false,
                'msg' => 'Falha de conexão: ' . $msg,
            ), 60);
            wp_safe_redirect($redirect);
            exit;
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($code >= 200 && $code < 300) {
            set_transient('adspirit_customerio_test_result', array(
                'ok'  => true,
                'msg' => 'Conexão OK (HTTP ' . (int) $code . '). Customer "' . esc_html($test_email) . '" identificado.',
            ), 60);
        } elseif ($code === 401 || $code === 403) {
            set_transient('adspirit_customerio_test_result', array(
                'ok'  => false,
                'msg' => 'Credenciais rejeitadas (HTTP ' . (int) $code . '). Confira Site ID + API Key — precisa ser Tracking API key, não App API.',
            ), 60);
        } else {
            set_transient('adspirit_customerio_test_result', array(
                'ok'  => false,
                'msg' => 'HTTP ' . (int) $code . ': ' . esc_html(substr((string) $body, 0, 200)),
            ), 60);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    // ─────────────────────────────────────────────────────────
    // RENDER TAB
    // ─────────────────────────────────────────────────────────
    public function render_tab() {
        $c = self::get_config();
        $status_badge = $c['enabled'] === '1'
            ? '<span class="as-badge ok">Ativo</span>'
            : '<span class="as-badge muted">Desligado</span>';

        $test_result = get_transient('adspirit_customerio_test_result');
        if ($test_result) {
            delete_transient('adspirit_customerio_test_result');
        }
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Customer.io</span>Leads no Customer.io</h2>
        <p class="as-section-help">Cada lead do site também vira um contato no Customer.io, na hora — pra suas automações de email começarem antes.</p>

        <?php if (is_array($test_result)): ?>
            <div class="as-notice <?php echo $test_result['ok'] ? 'info' : 'danger'; ?>">
                <div class="as-notice-kicker"><?php echo $test_result['ok'] ? 'Teste OK' : 'Teste falhou'; ?></div>
                <p><?php echo esc_html($test_result['msg']); ?></p>
            </div>
        <?php endif; ?>

        <?php AdSpirit_Menu::card_open('Credenciais Customer.io', 'As duas chaves estão em <em>Customer.io → Settings → Account → API Credentials → Tracking API Keys</em>', $status_badge); ?>
        <?php AdSpirit_Menu::form_open('customerio'); ?>

        <div class="as-toggle">
            <input type="checkbox" id="cio_enabled" name="enabled" value="1" <?php checked($c['enabled'], '1'); ?>>
            <label class="t" for="cio_enabled">Envio pro Customer.io ligado<small>Precisa do Site ID e da chave abaixo pra começar a enviar.</small></label>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="cio_site_id">Site ID</label>
            <input type="text" id="cio_site_id" name="site_id" value="<?php echo esc_attr($c['site_id']); ?>" class="regular-text" autocomplete="off">
            <p class="description">Identifica sua conta. Está em Settings → Account → API Credentials.</p>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="cio_api_key">Chave da API (Tracking API Key)</label>
            <input type="password" id="cio_api_key" name="api_key" value="<?php echo esc_attr($c['api_key']); ?>" class="regular-text" autocomplete="off">
            <button type="button" class="button" onclick="var e=document.getElementById('cio_api_key');e.type=e.type==='password'?'text':'password';">Mostrar</button>
            <p class="description">Atenção: é a Tracking API Key — a App API Key não funciona aqui.</p>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="cio_url">Endereço da API (avançado)</label>
            <input type="url" id="cio_url" name="tracking_api_url" value="<?php echo esc_attr($c['tracking_api_url']); ?>" class="regular-text" placeholder="<?php echo esc_attr(self::DEFAULT_BASE_URL); ?>">
            <p class="description">Deixe como está, salvo se sua conta for da região Europa — aí use <code>https://track-eu.customer.io/api/v1</code>.</p>
        </div>

        <div class="as-toggle">
            <input type="checkbox" id="cio_identify" name="identify_on_submit" value="1" <?php checked($c['identify_on_submit'], '1'); ?>>
            <label class="t" for="cio_identify">Criar/atualizar o contato<small>Cada envio de formulário cria ou atualiza a pessoa no Customer.io.</small></label>
        </div>

        <div class="as-toggle">
            <input type="checkbox" id="cio_event" name="event_on_submit" value="1" <?php checked($c['event_on_submit'], '1'); ?>>
            <label class="t" for="cio_event">Registrar o evento de envio<small>Dispara o evento abaixo com todos os campos do formulário — útil pra segmentar.</small></label>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="cio_event_name">Nome do evento</label>
            <input type="text" id="cio_event_name" name="event_name" value="<?php echo esc_attr($c['event_name']); ?>" class="regular-text" placeholder="form_submitted">
            <p class="description">Padrão <code>form_submitted</code>. Use letras minúsculas e underline — o Customer.io segmenta pelo nome exato.</p>
        </div>

        <details class="as-help">
            <summary>Detalhes técnicos (pra suporte)</summary>
            <ul>
                <li>Identify: <code>PUT /customers/{email}</code>; evento: <code>POST /customers/{email}/events</code>.</li>
                <li>Autenticação Basic com Site ID + Tracking API Key.</li>
                <li>Fire-and-forget: falha no Customer.io não bloqueia o envio do formulário nem o envio pro AdSpirit.</li>
            </ul>
        </details>

        <?php AdSpirit_Menu::form_close('Salvar Customer.io'); ?>
        <?php AdSpirit_Menu::card_close(); ?>

        <?php AdSpirit_Menu::card_open('Testar conexão', 'Confirma que as credenciais salvas funcionam — não cria evento nenhum'); ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="adspirit_customerio_test">
            <?php wp_nonce_field('adspirit_customerio_test', '_adspirit_nonce'); ?>
            <p>
                <button type="submit" class="button">Testar conexão</button>
            </p>
            <p class="description">Salve as credenciais antes de testar. O resultado aparece no topo da tela; se der "credenciais rejeitadas", confira se usou a Tracking API Key.</p>
        </form>
        <?php AdSpirit_Menu::card_close(); ?>
        <?php
    }

    // ─────────────────────────────────────────────────────────
    // SAVE handler
    // ─────────────────────────────────────────────────────────
    public function handle_save($post) {
        $patch = array();
        $patch['enabled']            = !empty($post['enabled']) ? '1' : '0';
        $patch['site_id']            = sanitize_text_field((string) ($post['site_id'] ?? ''));
        $patch['api_key']            = sanitize_text_field((string) ($post['api_key'] ?? ''));
        $patch['tracking_api_url']   = esc_url_raw((string) ($post['tracking_api_url'] ?? self::DEFAULT_BASE_URL));
        if ($patch['tracking_api_url'] === '') {
            $patch['tracking_api_url'] = self::DEFAULT_BASE_URL;
        }
        $patch['identify_on_submit'] = !empty($post['identify_on_submit']) ? '1' : '0';
        $patch['event_on_submit']    = !empty($post['event_on_submit']) ? '1' : '0';
        $event_name = sanitize_text_field((string) ($post['event_name'] ?? 'form_submitted'));
        // Customer.io aceita event names com letras/números/underscore/hífen
        $event_name = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $event_name);
        $patch['event_name'] = $event_name !== '' ? $event_name : 'form_submitted';

        self::update_config($patch);
        add_settings_error(self::OPTION_KEY, 'saved', 'Customer.io salvo.', 'updated');
    }
}
