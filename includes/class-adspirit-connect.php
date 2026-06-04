<?php
/**
 * AdSpirit Connector — Click-to-Connect (OAuth-like).
 *
 * Flow:
 *   1. User clica "Conectar ao AdSpirit" no painel
 *   2. Plugin gera state CSRF, redireciona nova aba pro CRM
 *      ${endpoint}/connect-wp?return_url=...&state=...
 *   3. CRM autoriza, redireciona de volta pro
 *      admin-post.php?action=adspirit_connect_callback&state=...&token=...
 *   4. Callback aqui: valida state, POSTa /api/connect-wp/exchange,
 *      recebe credenciais, salva tudo nas options
 *   5. UI mostra "Conectado"
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Connect {
    const STATE_TRANSIENT_PREFIX = 'adspirit_connect_state_';
    const STATE_TTL_SECONDS = 600; // 10 min — tempo confortável pra autorizar

    private static $instance = null;
    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action(
            'admin_post_adspirit_connect_start',
            AdSpirit_Safe_Hook::action(array($this, 'start_connect'), 'connect_start')
        );
        add_action(
            'admin_post_adspirit_connect_callback',
            AdSpirit_Safe_Hook::action(array($this, 'handle_callback'), 'connect_callback')
        );
        add_action(
            'admin_post_adspirit_disconnect',
            AdSpirit_Safe_Hook::action(array($this, 'handle_disconnect'), 'connect_disconnect')
        );
    }

    /**
     * Step 1: gera state CSRF, redireciona pro CRM.
     */
    public function start_connect() {
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) wp_die('forbidden', 403);
        check_admin_referer('adspirit_connect_start');

        $endpoint = isset($_POST['endpoint_url']) ? esc_url_raw((string) $_POST['endpoint_url']) : '';
        $endpoint = AdSpirit_Settings::normalize_endpoint_url($endpoint);
        if (!$endpoint) {
            $endpoint = 'https://crm.agenciadigitals.com.br';
        }

        // Salva o endpoint escolhido antes de iniciar (pra usar no exchange)
        AdSpirit_Settings::update_core(array('endpoint_url' => $endpoint));

        // State CSRF — 32 bytes hex
        $state = bin2hex(random_bytes(16));
        set_transient(self::STATE_TRANSIENT_PREFIX . $state, array(
            'created_at' => time(),
            'user_id' => get_current_user_id(),
        ), self::STATE_TTL_SECONDS);

        // Return URL: callback handler com state na query
        $return_url = admin_url('admin-post.php?action=adspirit_connect_callback');

        $crm_url = rtrim($endpoint, '/') . '/connect-wp?'
            . 'return_url=' . urlencode($return_url)
            . '&state=' . urlencode($state);

        wp_safe_redirect($crm_url);
        exit;
    }

    /**
     * Step 4: CRM redirecionou de volta com state + token. Valida e
     * troca por credenciais via API exchange.
     */
    public function handle_callback() {
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) wp_die('forbidden', 403);

        $state = isset($_GET['state']) ? sanitize_text_field((string) $_GET['state']) : '';
        $token = isset($_GET['token']) ? sanitize_text_field((string) $_GET['token']) : '';

        if (!$state || !$token) {
            $this->fail_redirect('Parâmetros faltando no callback.');
        }

        // Valida state
        $cached = get_transient(self::STATE_TRANSIENT_PREFIX . $state);
        if (!$cached || empty($cached['user_id']) || (int) $cached['user_id'] !== get_current_user_id()) {
            $this->fail_redirect('State inválido — possível CSRF. Tente conectar novamente.');
        }
        delete_transient(self::STATE_TRANSIENT_PREFIX . $state);

        $core = AdSpirit_Settings::get_core();
        $endpoint = $core['endpoint_url'] ?: 'https://crm.agenciadigitals.com.br';

        // POST /api/connect-wp/exchange
        $current_user = wp_get_current_user();
        $body = array(
            'nonce' => $token,
            'site_url' => home_url('/'),
            'site_name' => get_bloginfo('name'),
            'admin_email' => $current_user ? $current_user->user_email : get_option('admin_email'),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'plugin_version' => ADSPIRIT_CONNECTOR_VERSION,
        );

        $response = wp_remote_post($endpoint . '/api/connect-wp/exchange', array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'AdSpirit-Connector/' . ADSPIRIT_CONNECTOR_VERSION,
            ),
            'body' => wp_json_encode($body),
        ));

        if (is_wp_error($response)) {
            $this->fail_redirect('Erro ao chamar CRM: ' . $response->get_error_message());
        }

        $code = wp_remote_retrieve_response_code($response);
        $resp_body = wp_remote_retrieve_body($response);
        $data = json_decode($resp_body, true);

        if ($code !== 200 || !is_array($data) || empty($data['ok'])) {
            $err = is_array($data) && !empty($data['error']) ? $data['error'] : ('HTTP ' . $code);
            $this->fail_redirect('CRM rejeitou exchange: ' . $err);
        }

        // Valida state de volta (CRM ecoa)
        if (!empty($data['state']) && $data['state'] !== $state) {
            $this->fail_redirect('State não bate na resposta — possível MITM.');
        }

        // Salva tudo!
        AdSpirit_Settings::update_core(array(
            'endpoint_url' => $endpoint,
            'brand_slug' => (string) ($data['brand_slug'] ?? ''),
            'secret' => (string) ($data['secret'] ?? ''),
            'pixel_token' => (string) ($data['pixel_token'] ?? ''),
            'cf7_enabled' => '1',  // ativa por padrão
            'pixel_enabled' => '1', // ativa por padrão
            'brand_name' => (string) ($data['brand_name'] ?? ''),
        ));

        // Redirect pra Visão geral com mensagem de sucesso
        $back = add_query_arg(
            array('page' => AdSpirit_Menu::PAGE_SLUG, 'tab' => 'overview', 'connected' => '1'),
            admin_url('admin.php')
        );
        wp_safe_redirect($back);
        exit;
    }

    public function handle_disconnect() {
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) wp_die('forbidden', 403);
        check_admin_referer('adspirit_disconnect');

        // Limpa credenciais sensíveis, mantém endpoint pra próxima conexão
        AdSpirit_Settings::update_core(array(
            'brand_slug' => '',
            'secret' => '',
            'pixel_token' => '',
            'cf7_enabled' => '0',
            'pixel_enabled' => '0',
            'brand_name' => '',
        ));

        $back = add_query_arg(
            array('page' => AdSpirit_Menu::PAGE_SLUG, 'tab' => 'connection', 'disconnected' => '1'),
            admin_url('admin.php')
        );
        wp_safe_redirect($back);
        exit;
    }

    private function fail_redirect($reason) {
        $back = add_query_arg(
            array(
                'page' => AdSpirit_Menu::PAGE_SLUG,
                'tab' => 'connection',
                'connect_error' => urlencode($reason),
            ),
            admin_url('admin.php')
        );
        wp_safe_redirect($back);
        exit;
    }

    public static function is_connected() {
        $c = AdSpirit_Settings::get_core();
        return !empty($c['brand_slug']) && !empty($c['secret']);
    }
}
