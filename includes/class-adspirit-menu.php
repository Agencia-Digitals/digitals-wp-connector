<?php
/**
 * AdSpirit Connector — top-level menu + tabs router.
 *
 * Aparece no menu principal do wp-admin, não em Configurações.
 * Estrutura:
 *   AdSpirit Connector  (top-level)
 *     ├── Visão geral          (default tab)
 *     ├── Conexão CRM          (slug, secret, URL)
 *     ├── Forms / Field mapping
 *     ├── Anti-spam
 *     ├── Meta CAPI
 *     ├── Google Analytics 4
 *     ├── Cross-domain
 *     ├── Logs
 *
 * Cada tab é renderizada pela sua classe via filtro
 * `adspirit_connector_tabs` (registro) + `adspirit_connector_render_tab_{slug}`
 * (render). Mantém features desacopladas — adicionar tab = registrar filtro,
 * não mexer no menu.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Menu {
    const PAGE_SLUG = 'adspirit-connector';
    const CAPABILITY = 'manage_options';
    const NONCE_PREFIX = 'adspirit_';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action(
            'admin_menu',
            AdSpirit_Safe_Hook::action(array($this, 'register_menu'), 'menu_register'),
            9
        );
        add_action(
            'admin_post_adspirit_save',
            AdSpirit_Safe_Hook::action(array($this, 'handle_save'), 'menu_save')
        );
        add_action(
            'admin_enqueue_scripts',
            AdSpirit_Safe_Hook::action(array($this, 'enqueue_assets'), 'menu_assets')
        );
        add_action(
            'admin_post_adspirit_exit_safe_mode',
            AdSpirit_Safe_Hook::action(array($this, 'handle_exit_safe_mode'), 'exit_safe_mode')
        );
    }

    public function handle_exit_safe_mode() {
        if (!current_user_can(self::CAPABILITY)) wp_die('forbidden', 403);
        check_admin_referer('adspirit_exit_safe_mode');
        if (class_exists('AdSpirit_Safe_Bootstrap')) {
            AdSpirit_Safe_Bootstrap::exit_safe_mode();
        }
        wp_safe_redirect(admin_url('admin.php?page=' . self::PAGE_SLUG . '&safe_mode=off'));
        exit;
    }

    public static function tabs() {
        // Cada feature register-se via filtro. Slug → label.
        $tabs = array(
            'overview'     => 'Visão geral',
            'connection'   => 'Conexão CRM',
            'forms'        => 'Forms / Field mapping',
            'antispam'     => 'Anti-spam',
            'capi-meta'    => 'Meta CAPI',
            'ga4'          => 'Google Analytics 4',
            'cross-domain' => 'Cross-domain',
            'logs'         => 'Logs',
        );
        return apply_filters('adspirit_connector_tabs', $tabs);
    }

    public function register_menu() {
        // Menu top-level com ícone de antena
        $icon = 'dashicons-rss';

        add_menu_page(
            'AdSpirit Connector',
            'AdSpirit',
            self::CAPABILITY,
            self::PAGE_SLUG,
            array($this, 'render_page'),
            $icon,
            58 // posição: entre Comments(25) e Appearance(60)
        );

        // Submenus = um por tab pra deep linking
        foreach (self::tabs() as $slug => $label) {
            add_submenu_page(
                self::PAGE_SLUG,
                $label,
                $label,
                self::CAPABILITY,
                self::PAGE_SLUG . '&tab=' . $slug,
                array($this, 'render_page')
            );
        }

        // Remove auto-criado primeiro submenu duplicado pelo WP
        remove_submenu_page(self::PAGE_SLUG, self::PAGE_SLUG);
    }

    public function enqueue_assets($hook) {
        if (strpos((string) $hook, self::PAGE_SLUG) === false) return;
        // Inline pra simplicidade (1 arquivo CSS pequeno).
        $css = '
        .adspirit-wrap { max-width: 1100px; }
        .adspirit-wrap h1 { display:flex; align-items:center; gap:12px; }
        .adspirit-wrap .badge { display:inline-block; padding:2px 8px; border-radius:10px; font-size:11px; font-weight:600; vertical-align:middle; }
        .adspirit-wrap .badge.ok { background:#dcfce7; color:#15803d; }
        .adspirit-wrap .badge.warn { background:#fef3c7; color:#a16207; }
        .adspirit-wrap .badge.danger { background:#fee2e2; color:#b91c1c; }
        .adspirit-wrap .badge.muted { background:#e5e7eb; color:#374151; }
        .adspirit-wrap .nav-tab-wrapper { margin-bottom: 18px; }
        .adspirit-wrap .checklist { list-style:none; margin:0; padding:0; }
        .adspirit-wrap .checklist li { padding:10px 14px; border:1px solid #e5e7eb; border-radius:6px; margin-bottom:8px; display:flex; align-items:flex-start; gap:12px; background:#fff; }
        .adspirit-wrap .checklist .icon { font-size:18px; line-height:1; margin-top:2px; }
        .adspirit-wrap .checklist .icon.done { color:#15803d; }
        .adspirit-wrap .checklist .icon.todo { color:#a16207; }
        .adspirit-wrap .checklist .icon.fail { color:#b91c1c; }
        .adspirit-wrap .checklist .body { flex:1; }
        .adspirit-wrap .checklist .title { font-weight:600; }
        .adspirit-wrap .checklist .desc { font-size:13px; color:#4b5563; margin-top:2px; }
        .adspirit-wrap .checklist .cta { margin-top:8px; }
        .adspirit-wrap .metric-grid { display:grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap:12px; margin: 16px 0; }
        .adspirit-wrap .metric { background:#fff; border:1px solid #e5e7eb; border-radius:6px; padding:14px; }
        .adspirit-wrap .metric .label { font-size:11px; color:#6b7280; text-transform:uppercase; letter-spacing:0.06em; font-weight:600; }
        .adspirit-wrap .metric .value { font-size:24px; font-weight:600; margin-top:4px; }
        .adspirit-wrap .metric .sub { font-size:12px; color:#6b7280; margin-top:2px; }
        .adspirit-wrap pre.json { background:#f3f4f6; padding:12px; border-radius:6px; max-width: 900px; overflow:auto; }
        .adspirit-wrap .form-table th { width: 240px; }
        .adspirit-wrap .field-map-row { display:grid; grid-template-columns: 1fr 24px 1fr; gap:10px; align-items:center; margin-bottom:6px; }
        .adspirit-wrap .field-map-row select { width:100%; }
        .adspirit-wrap .test-result { margin-top:10px; }
        .adspirit-wrap details { border:1px solid #e5e7eb; border-radius:6px; padding:10px 14px; background:#f9fafb; margin: 8px 0; }
        .adspirit-wrap summary { cursor:pointer; font-weight:600; }
        ';
        wp_register_style('adspirit-connector-inline', false);
        wp_enqueue_style('adspirit-connector-inline');
        wp_add_inline_style('adspirit-connector-inline', $css);
    }

    public function render_page() {
        if (!current_user_can(self::CAPABILITY)) return;

        $tabs = self::tabs();
        $current_tab = isset($_GET['tab']) ? sanitize_key((string) $_GET['tab']) : 'overview';
        if (!isset($tabs[$current_tab])) $current_tab = 'overview';

        ?>
        <div class="wrap adspirit-wrap">
            <h1>
                AdSpirit Connector
                <span class="badge muted">v<?php echo esc_html(ADSPIRIT_CONNECTOR_VERSION); ?></span>
            </h1>

            <?php settings_errors(); ?>

            <h2 class="nav-tab-wrapper">
                <?php foreach ($tabs as $slug => $label): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=' . $slug)); ?>"
                       class="nav-tab <?php echo $current_tab === $slug ? 'nav-tab-active' : ''; ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </h2>

            <?php
            // Safe Mode banner — sempre visível no topo se ativo
            if (class_exists('AdSpirit_Safe_Bootstrap') && AdSpirit_Safe_Bootstrap::is_safe_mode()) {
                self::render_safe_mode_banner();
            }

            /**
             * Render tab com output-buffering + try/catch. Fatal numa tab
             * não cascadeia pra rest do wp-admin — buffer descarta,
             * mostra notice clean.
             */
            ob_start();
            try {
                do_action('adspirit_connector_render_tab_' . $current_tab);
                echo ob_get_clean();
            } catch (\Throwable $e) {
                ob_end_clean();
                if (class_exists('AdSpirit_Crash_Tracker')) {
                    AdSpirit_Crash_Tracker::record(
                        'admin_render_' . $current_tab,
                        get_class($e) . ': ' . $e->getMessage(),
                        $e->getFile(),
                        $e->getLine()
                    );
                }
                ?>
                <div class="notice notice-error">
                    <p><strong>Erro ao renderizar essa aba.</strong> O resto do plugin continua funcionando. Detalhes em <em>error_log</em> do servidor.</p>
                    <p><code><?php echo esc_html(get_class($e) . ': ' . $e->getMessage()); ?></code></p>
                </div>
                <?php
            }
            ?>
        </div>
        <?php
    }

    private static function render_safe_mode_banner() {
        $reason = AdSpirit_Safe_Bootstrap::safe_mode_reason();
        $at     = AdSpirit_Safe_Bootstrap::safe_mode_at();
        $exit_url = wp_nonce_url(
            admin_url('admin-post.php?action=adspirit_exit_safe_mode'),
            'adspirit_exit_safe_mode'
        );
        ?>
        <div class="notice notice-error" style="border-left-color:#b91c1c;">
            <h3 style="margin:6px 0;">Safe Mode ativo — features do plugin desligadas</h3>
            <p>
                Motivo: <code><?php echo esc_html($reason ?: 'manual'); ?></code>
                <?php if ($at): ?>
                    · ativado <?php echo esc_html(human_time_diff($at, time()) . ' atrás'); ?>
                <?php endif; ?>
            </p>
            <p>
                Enquanto Safe Mode estiver ligado, o plugin NÃO envia leads pro CRM, NÃO dispara Meta CAPI/GA4, NÃO injeta scripts. Site fica intocado. A aba "Logs" mostra os erros que levaram aqui.
            </p>
            <p>
                <a href="<?php echo esc_url($exit_url); ?>" class="button button-primary">Sair do Safe Mode</a>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=logs')); ?>" class="button">Ver logs</a>
            </p>
        </div>
        <?php
    }

    /**
     * Handler universal de save de form. Cada tab posta pra
     *   <?php echo admin_url('admin-post.php'); ?>
     * com action=adspirit_save, nonce_name=adspirit_<tab>_nonce, e
     * o body do form serializado.
     */
    public function handle_save() {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die('forbidden', 403);
        }
        $tab = isset($_POST['adspirit_tab']) ? sanitize_key((string) $_POST['adspirit_tab']) : '';
        $nonce_action = self::NONCE_PREFIX . $tab . '_save';
        check_admin_referer($nonce_action, '_adspirit_nonce');

        /**
         * Cada tab implementa:
         *   add_action('adspirit_connector_save_<tab>', function($post) { ... });
         * e popula settings_errors() com sucessos/falhas.
         */
        do_action('adspirit_connector_save_' . $tab, $_POST);

        $redirect = isset($_POST['_wp_http_referer']) ? esc_url_raw((string) $_POST['_wp_http_referer']) : admin_url('admin.php?page=' . self::PAGE_SLUG);
        wp_safe_redirect(add_query_arg('saved', '1', $redirect));
        exit;
    }

    /**
     * Helper pra cada tab render seu próprio form com nonce.
     */
    public static function form_open($tab) {
        $nonce_action = self::NONCE_PREFIX . $tab . '_save';
        ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="adspirit_save">
            <input type="hidden" name="adspirit_tab" value="<?php echo esc_attr($tab); ?>">
            <?php wp_nonce_field($nonce_action, '_adspirit_nonce'); ?>
        <?php
    }

    public static function form_close($submit_label = 'Salvar') {
        ?>
            <?php submit_button($submit_label); ?>
        </form>
        <?php
    }
}
