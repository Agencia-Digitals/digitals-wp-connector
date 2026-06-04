<?php
/**
 * AdSpirit Connector — Magic-link install.
 *
 * Operadora gera um token no CRM (/install-wp) e entrega o link
 *   https://wp.cliente.com/wp-admin/?adspirit_token=XYZ
 * pro cliente colar no navegador. Aqui detectamos a query, trocamos o
 * token por credenciais via /api/wp/redeem-install-token e salvamos
 * tudo com cf7 + pixel ativos.
 *
 * É um atalho do flow OAuth-like de AdSpirit_Connect: pula o passo de
 * o cliente entrar no painel do plugin e clicar "Conectar". Útil quando
 * o cliente nem sabe que o plugin existe e a agência tá fazendo
 * onboarding assistido.
 *
 * Segurança:
 *   - só roda no admin (admin_init)
 *   - exige capability AdSpirit_Menu::CAPABILITY (manage_options)
 *   - token é single-use no servidor (CRM responde 410 na segunda)
 *   - se já estiver conectado, ignora e mostra notice (não sobrescreve)
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Magic_Install {
    const QUERY_PARAM     = 'adspirit_token';
    const NOTICE_OPTION   = 'adspirit_magic_install_notice';
    const REDEEM_PATH     = '/api/wp/redeem-install-token';
    const DEFAULT_CRM_URL = 'https://crm.agenciadigitals.com.br';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action(
            'admin_init',
            AdSpirit_Safe_Hook::action(array($this, 'maybe_redeem'), 'magic_install_redeem')
        );
        add_action(
            'admin_notices',
            AdSpirit_Safe_Hook::action(array($this, 'render_notice'), 'magic_install_notice')
        );
    }

    /**
     * Detecta `?adspirit_token=…` em qualquer página do wp-admin.
     * Se válido, redime contra o CRM e salva credenciais.
     */
    public function maybe_redeem() {
        if (!is_admin()) return;
        if (empty($_GET[self::QUERY_PARAM])) return;

        $raw_token = (string) $_GET[self::QUERY_PARAM];
        $token = sanitize_text_field($raw_token);
        // Format check defensivo — token gerado pelo CRM é hex (24 bytes = 48 chars)
        if (!preg_match('/^[a-f0-9]{16,128}$/i', $token)) {
            $this->set_notice('error', 'Token mágico inválido.');
            $this->redirect_clean();
        }

        // Capability check — sem isso, qualquer subscriber-logged-in
        // poderia reconfigurar o plugin abrindo o link.
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) {
            $this->set_notice('error', 'Você precisa ser admin do WordPress pra ativar o link mágico.');
            $this->redirect_clean();
        }

        // Se já conectado, não sobrescreve — operadora deve disconnect
        // primeiro caso queira reapontar. Evita acidente de quebrar uma
        // instalação live.
        if (class_exists('AdSpirit_Connect') && AdSpirit_Connect::is_connected()) {
            $this->set_notice('warning', 'Este site já está conectado a uma marca. Desconecte antes de usar um novo link mágico.');
            $this->redirect_clean();
        }

        // Endpoint: usa o salvo nas options OR fallback default
        $core = AdSpirit_Settings::get_core();
        $endpoint = !empty($core['endpoint_url'])
            ? $core['endpoint_url']
            : self::DEFAULT_CRM_URL;
        $endpoint = AdSpirit_Settings::normalize_endpoint_url($endpoint);

        $current_user = wp_get_current_user();
        $body = array(
            'token' => $token,
            'site_url' => home_url('/'),
            'site_name' => get_bloginfo('name'),
            'admin_email' => $current_user ? $current_user->user_email : get_option('admin_email'),
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'plugin_version' => defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : 'unknown',
        );

        $response = wp_remote_post($endpoint . self::REDEEM_PATH, array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type' => 'application/json',
                'User-Agent' => 'AdSpirit-Connector/' . (defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : 'unknown'),
            ),
            'body' => wp_json_encode($body),
        ));

        if (is_wp_error($response)) {
            $this->set_notice('error', 'Erro ao contatar o CRM: ' . $response->get_error_message());
            $this->redirect_clean();
        }

        $code = wp_remote_retrieve_response_code($response);
        $raw  = wp_remote_retrieve_body($response);
        $data = json_decode($raw, true);

        if ($code !== 200 || !is_array($data) || empty($data['ok'])) {
            $err = is_array($data) && !empty($data['error']) ? $data['error'] : ('HTTP ' . $code);
            $human = $this->humanize_error($err);
            $this->set_notice('error', 'Link mágico falhou: ' . $human);
            $this->redirect_clean();
        }

        // Persiste credenciais + ativa CF7 e pixel automaticamente
        AdSpirit_Settings::update_core(array(
            'endpoint_url'  => $endpoint,
            'brand_slug'    => (string) ($data['brand_slug'] ?? ''),
            'brand_name'    => (string) ($data['brand_name'] ?? ''),
            'secret'        => (string) ($data['secret'] ?? ''),
            'pixel_token'   => (string) ($data['pixel_token'] ?? ''),
            'cf7_enabled'   => '1',
            'pixel_enabled' => '1',
        ));

        $brand_label = !empty($data['brand_name']) ? $data['brand_name'] : (string) ($data['brand_slug'] ?? '');
        $this->set_notice('success', 'Conectado ao AdSpirit como ' . $brand_label . '. CF7 e pixel já estão ativos.');

        // Redireciona pra tab Conexão CRM (visualização do estado)
        $back = add_query_arg(
            array(
                'page' => AdSpirit_Menu::PAGE_SLUG,
                'tab' => 'connection',
                'magic_connected' => '1',
            ),
            admin_url('admin.php')
        );
        wp_safe_redirect($back);
        exit;
    }

    /**
     * Mostra notice persistido em option (single-shot — apaga depois
     * de exibir). Aparece em qualquer página do admin.
     */
    public function render_notice() {
        $notice = get_option(self::NOTICE_OPTION);
        if (!is_array($notice) || empty($notice['message'])) return;

        $type = isset($notice['type']) ? $notice['type'] : 'info';
        $class = 'notice';
        switch ($type) {
            case 'success': $class .= ' notice-success'; break;
            case 'error':   $class .= ' notice-error';   break;
            case 'warning': $class .= ' notice-warning'; break;
            default:        $class .= ' notice-info';
        }

        echo '<div class="' . esc_attr($class) . ' is-dismissible">'
           . '<p>' . esc_html($notice['message']) . '</p>'
           . '</div>';

        delete_option(self::NOTICE_OPTION);
    }

    private function set_notice($type, $message) {
        update_option(self::NOTICE_OPTION, array(
            'type' => (string) $type,
            'message' => (string) $message,
        ), false);
    }

    /**
     * Tira o ?adspirit_token=… da URL e redireciona. Evita re-submit
     * do mesmo token em refresh + esconde o token do histórico do
     * browser.
     */
    private function redirect_clean() {
        $request_uri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        $stripped = remove_query_arg(self::QUERY_PARAM, $request_uri);
        if (!$stripped) {
            $stripped = admin_url('index.php');
        }
        wp_safe_redirect($stripped);
        exit;
    }

    private function humanize_error($code) {
        switch ($code) {
            case 'token_not_found': return 'token não existe ou já foi limpo';
            case 'token_used':      return 'token já foi usado em outro site';
            case 'token_expired':   return 'token expirou (limite 7 dias)';
            case 'brand_not_found': return 'marca não localizada no CRM';
            case 'invalid_json':
            case 'missing_required_fields':
                return 'requisição inválida — atualize o plugin';
            default:
                return (string) $code;
        }
    }
}
