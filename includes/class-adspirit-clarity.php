<?php
/**
 * AdSpirit Connector — Microsoft Clarity adapter.
 *
 * Injeta o loader oficial do Clarity (clarity.ms/tag/{ID}) no <head>
 * respeitando o consent de analytics do popup LGPD do plugin. No footer
 * enfileira um init JS que (a) propaga visitor_id/brand_slug/session_id/
 * consent_level/ab_variant via clarity('set', ...) e (b) chama
 * clarity('identify', ...) quando o lead submete um form (CF7 ou
 * [adspirit_form]).
 *
 * Cookies lidos pelo init JS:
 *   - adspirit_vid   (visitor id mintado pelo CRM, 90d)
 *   - adspirit_brand (brand slug)
 *   - adspirit_sid   (session id)
 *   - adspirit_consent (categoria de consent)
 *   - adspirit_ab_variant_* (variants de teste A/B)
 *
 * Project ID validation: regex `/^[a-z0-9]{8,12}$/i`. Settings inválido
 * força enabled='0' (não injeta loader, não enfileira init).
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Clarity {
    const OPTION_CLARITY = 'adspirit_connector_clarity';
    const PROJECT_ID_REGEX = '/^[a-z0-9]{8,12}$/i';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Loader inline no <head> (prioridade 20 — depois de scripts críticos)
        add_action(
            'wp_head',
            AdSpirit_Safe_Hook::action(array($this, 'inject_loader'), 'clarity_loader'),
            20
        );
        // Init JS no <footer> (prioridade 15)
        add_action(
            'wp_footer',
            AdSpirit_Safe_Hook::action(array($this, 'enqueue_init'), 'clarity_init'),
            15
        );
        // Tab UI
        add_filter(
            'adspirit_connector_tabs',
            AdSpirit_Safe_Hook::filter(array($this, 'register_tab'), 'clarity_register_tab')
        );
        add_action(
            'adspirit_connector_render_tab_clarity',
            AdSpirit_Safe_Hook::action(array($this, 'render_tab'), 'clarity_tab')
        );
        // Save handler — usa o action genérico do menu (adspirit_save dispara
        // adspirit_connector_save_clarity), e também registra alias direto
        // pra compatibilidade com admin_post_adspirit_save_clarity caso algum
        // form externo poste pra esse endpoint.
        add_action(
            'adspirit_connector_save_clarity',
            AdSpirit_Safe_Hook::action(array($this, 'handle_save'), 'clarity_save')
        );
        add_action(
            'admin_post_adspirit_save_clarity',
            AdSpirit_Safe_Hook::action(array($this, 'handle_save_direct'), 'clarity_save_direct')
        );
    }

    public function register_tab($tabs) {
        if (!is_array($tabs)) return $tabs;
        $tabs['clarity'] = 'Clarity';
        return $tabs;
    }

    // ─────────────────────────────────────────────────────────
    // SETTINGS
    // ─────────────────────────────────────────────────────────
    public static function defaults() {
        return array(
            'enabled'           => '0',
            'project_id'        => '',
            'identify_on_lead'  => '1',
            'share_visitor_id'  => '1',
            'hash_email'        => '1',
        );
    }

    public static function get_settings() {
        $stored = get_option(self::OPTION_CLARITY, array());
        $merged = wp_parse_args(is_array($stored) ? $stored : array(), self::defaults());
        // Defesa: Project ID inválido força enabled '0'
        if ($merged['enabled'] === '1' && !self::is_valid_project_id($merged['project_id'])) {
            $merged['enabled'] = '0';
        }
        return $merged;
    }

    public static function update_settings(array $patch) {
        $current = self::get_settings();
        update_option(self::OPTION_CLARITY, array_merge($current, $patch), false);
    }

    public static function is_valid_project_id($id) {
        $id = (string) $id;
        if ($id === '') return false;
        return (bool) preg_match(self::PROJECT_ID_REGEX, $id);
    }

    /**
     * Wrapper do consent. Frente 1 cria has_analytics_consent() no popup;
     * se ainda não estiver disponível, cai pro has_consent('analytics').
     */
    private static function analytics_consent_ok() {
        if (!class_exists('AdSpirit_Lgpd_Popup')) return false;
        if (method_exists('AdSpirit_Lgpd_Popup', 'has_analytics_consent')) {
            return (bool) AdSpirit_Lgpd_Popup::has_analytics_consent();
        }
        return (bool) AdSpirit_Lgpd_Popup::has_consent('analytics')
            || (bool) AdSpirit_Lgpd_Popup::has_consent('all');
    }

    // ─────────────────────────────────────────────────────────
    // LOADER no <head>
    // ─────────────────────────────────────────────────────────
    public function inject_loader() {
        if (is_admin()) return;
        $c = self::get_settings();
        if ($c['enabled'] !== '1') return;
        if (!self::is_valid_project_id($c['project_id'])) return;
        if (!self::analytics_consent_ok()) return;
        $project_id = $c['project_id'];
        ?>
<script>
(function(c,l,a,r,i,t,y){
  c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
  t=l.createElement(r);t.async=1;t.src='https://www.clarity.ms/tag/'+i;
  y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
})(window,document,'clarity','script','<?php echo esc_js($project_id); ?>');
</script>
        <?php
    }

    // ─────────────────────────────────────────────────────────
    // INIT JS no <footer>
    // ─────────────────────────────────────────────────────────
    public function enqueue_init() {
        if (is_admin()) return;
        $c = self::get_settings();
        if ($c['enabled'] !== '1') return;
        if (!self::is_valid_project_id($c['project_id'])) return;
        if (!self::analytics_consent_ok()) return;

        $handle = 'adspirit-clarity-init';
        $url = ADSPIRIT_CONNECTOR_URL . 'assets/clarity-init.js';
        wp_enqueue_script($handle, $url, array(), ADSPIRIT_CONNECTOR_VERSION, true);
        wp_localize_script($handle, 'AdSpiritClarityCfg', array(
            'identify_on_lead' => $c['identify_on_lead'] === '1',
            'share_visitor_id' => $c['share_visitor_id'] === '1',
            'hash_email'       => $c['hash_email'] === '1',
        ));
    }

    // ─────────────────────────────────────────────────────────
    // RENDER TAB
    // ─────────────────────────────────────────────────────────
    public function render_tab() {
        $c = self::get_settings();
        $valid_id = self::is_valid_project_id($c['project_id']);
        $status_badge = ($c['enabled'] === '1' && $valid_id)
            ? '<span class="as-badge ok">Ativo</span>'
            : '<span class="as-badge muted">Desligado</span>';

        $dashboard_url = $valid_id
            ? 'https://clarity.microsoft.com/projects/view/' . rawurlencode($c['project_id'])
            : '';
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Clarity</span>Heatmaps, session replay e funnels</h2>
        <p class="as-section-help">
            Injeta o tag oficial Microsoft Clarity no <code>&lt;head&gt;</code> respeitando o consent de analytics do popup LGPD. Propaga <code>visitor_id</code>, <code>brand_slug</code>, <code>session_id</code> e variants de A/B test como custom tags, e identifica o lead via <code>clarity('identify')</code> no submit de form.
        </p>

        <?php if (!empty($c['project_id']) && !$valid_id): ?>
            <div class="as-notice danger">
                <div class="as-notice-kicker">Project ID inválido</div>
                <p>O ID deve ter 8–12 caracteres alfanuméricos. Enquanto inválido, o loader fica desligado mesmo com a feature marcada como ativa.</p>
            </div>
        <?php endif; ?>

        <?php AdSpirit_Menu::card_open('Configuração', 'Cole o Project ID que aparece em <em>clarity.microsoft.com → Settings → Setup</em>', $status_badge); ?>
        <?php AdSpirit_Menu::form_open('clarity'); ?>
        <table class="form-table">
            <tr>
                <th>Status</th>
                <td>
                    <label>
                        <input type="checkbox" name="enabled" value="1" <?php checked($c['enabled'], '1'); ?>>
                        Ativar Clarity (depende de consent de analytics + Project ID válido)
                    </label>
                </td>
            </tr>
            <tr>
                <th><label for="clarity_project_id">Project ID</label></th>
                <td>
                    <input type="text" id="clarity_project_id" name="project_id" value="<?php echo esc_attr($c['project_id']); ?>" class="regular-text" autocomplete="off" pattern="[a-zA-Z0-9]{8,12}" placeholder="abc1d2e3f4">
                    <p class="description">8 a 12 caracteres alfanuméricos (regex <code>^[a-z0-9]{8,12}$</code>, case-insensitive).</p>
                </td>
            </tr>
            <tr>
                <th>Identify</th>
                <td>
                    <label>
                        <input type="checkbox" name="identify_on_lead" value="1" <?php checked($c['identify_on_lead'], '1'); ?>>
                        Chamar <code>clarity('identify')</code> em CF7 submit / <code>adspirit:form-submitted</code>
                    </label>
                </td>
            </tr>
            <tr>
                <th>Visitor stitch</th>
                <td>
                    <label>
                        <input type="checkbox" name="share_visitor_id" value="1" <?php checked($c['share_visitor_id'], '1'); ?>>
                        Propagar <code>adspirit_vid</code> como custom tag <code>visitor_id</code> (recomendado pra stitch com CRM)
                    </label>
                </td>
            </tr>
            <tr>
                <th>Email hash</th>
                <td>
                    <label>
                        <input type="checkbox" name="hash_email" value="1" <?php checked($c['hash_email'], '1'); ?>>
                        Enviar SHA-256 do email (primeiros 16 chars) em vez do plaintext no <code>identify</code>
                    </label>
                    <p class="description">Recomendado por padrão pra reduzir superfície de dado pessoal exposto ao Clarity. Desligar só se você precisa cruzar manualmente com outro sistema por email cleartext.</p>
                </td>
            </tr>
        </table>
        <?php AdSpirit_Menu::form_close('Salvar Clarity'); ?>
        <?php AdSpirit_Menu::card_close(); ?>

        <?php if ($valid_id): ?>
            <?php AdSpirit_Menu::card_open('Dashboard', 'Atalho pro projeto Clarity correspondente a este Project ID'); ?>
            <p>
                <a href="<?php echo esc_url($dashboard_url); ?>" target="_blank" rel="noopener" class="button button-secondary">
                    Abrir dashboard Clarity &rarr;
                </a>
            </p>
            <p class="description">URL: <code><?php echo esc_html($dashboard_url); ?></code></p>
            <?php AdSpirit_Menu::card_close(); ?>
        <?php endif; ?>
        <?php
    }

    // ─────────────────────────────────────────────────────────
    // SAVE handlers
    // ─────────────────────────────────────────────────────────
    /**
     * Save chamado pelo menu via do_action('adspirit_connector_save_clarity', $_POST).
     * Nonce já validado pelo handler genérico do menu.
     */
    public function handle_save($post) {
        $patch = array();
        $patch['enabled']          = !empty($post['enabled']) ? '1' : '0';
        $patch['project_id']       = sanitize_text_field((string) ($post['project_id'] ?? ''));
        $patch['identify_on_lead'] = !empty($post['identify_on_lead']) ? '1' : '0';
        $patch['share_visitor_id'] = !empty($post['share_visitor_id']) ? '1' : '0';
        $patch['hash_email']       = !empty($post['hash_email']) ? '1' : '0';

        // Se enabled='1' mas project_id inválido, salva mesmo assim (UI mostra
        // erro) — get_settings() força '0' em runtime, então loader não sai.
        self::update_settings($patch);
        add_settings_error(self::OPTION_CLARITY, 'saved', 'Clarity salvo.', 'updated');
    }

    /**
     * Endpoint direto admin_post_adspirit_save_clarity — para integrações
     * externas que queiram postar fora do fluxo do menu. Faz nonce check
     * próprio + sanitize + redirect.
     */
    public function handle_save_direct() {
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) wp_die('forbidden', 403);
        check_admin_referer('adspirit_clarity_save', '_adspirit_nonce');
        $this->handle_save($_POST);
        $redirect = admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=clarity&saved=1');
        wp_safe_redirect($redirect);
        exit;
    }
}
