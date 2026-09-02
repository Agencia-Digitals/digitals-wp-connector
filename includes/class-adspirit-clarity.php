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

    // O wrapper de consentimento no PHP saiu em 02/09. Ele decidia, no
    // servidor, se o Clarity entrava na página — e com cache essa decisão
    // vaza de um visitante pro outro. Quem decide agora é o navegador, no
    // trecho do <head> e no consent.js. Não reintroduzir gate de saída aqui.

    // ─────────────────────────────────────────────────────────
    // LOADER no <head>
    // ─────────────────────────────────────────────────────────
    public function inject_loader() {
        if (is_admin()) return;
        $c = self::get_settings();
        if ($c['enabled'] !== '1') return;
        if (!self::is_valid_project_id($c['project_id'])) return;
        $project_id = $c['project_id'];
        $cookie = class_exists('AdSpirit_Lgpd_Popup') ? AdSpirit_Lgpd_Popup::COOKIE : 'adspirit_consent';
        // O consentimento é lido AQUI, no navegador, e não no PHP: com cache
        // de página a resposta do primeiro visitante viraria a de todos
        // (02/09). A leitura está duplicada de propósito — este trecho vive no
        // <head> e não pode depender do consent.js, que carrega no rodapé.
        ?>
<script>
(function(){
  var COOKIE = <?php echo wp_json_encode($cookie); ?>, ligado = false;
  function ok(){
    var n = COOKIE.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + n + '=([^;]*)'));
    if (!m) return false;
    var raw = m[1];
    if (raw === 'accept_all') return true;
    if (raw.indexOf('custom:') === 0) return raw.slice(7).split(',').indexOf('analytics') !== -1;
    return false;
  }
  function carrega(c,l,a,r,i,t,y){
    if (ligado) return; ligado = true;
    c[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};
    t=l.createElement(r);t.async=1;t.src='https://www.clarity.ms/tag/'+i;
    y=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);
  }
  function tenta(){ if (ok()) carrega(window,document,'clarity','script',<?php echo wp_json_encode($project_id); ?>); }
  tenta();
  document.addEventListener('adspirit:consent', tenta);
})();
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

        // Mesma razão do loader: quem decide é o navegador. O arquivo
        // clarity-init.js continua idêntico — muda quem manda buscar.
        $url = ADSPIRIT_CONNECTOR_URL . 'assets/clarity-init.js?ver=' . rawurlencode(ADSPIRIT_CONNECTOR_VERSION);
        wp_enqueue_script('adspirit-consent');
        wp_add_inline_script('adspirit-consent', sprintf(
            '(function(){if(!window.AdSpiritConsent)return;'
            . 'window.AdSpiritConsent.onGrant("analytics",function(){'
            . 'if(window.__adspiritClarityOn)return;window.__adspiritClarityOn=1;'
            . 'var s=document.createElement("script");s.src=%s;s.async=true;'
            . '(document.head||document.documentElement).appendChild(s);});})();',
            wp_json_encode($url)
        ));
        wp_localize_script('adspirit-consent', 'AdSpiritClarityCfg', array(
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
        <h2 class="as-section"><span class="as-kicker-inline">Clarity</span>Gravações e mapas de calor</h2>
        <p class="as-section-help">Liga o Microsoft Clarity no site pra você assistir sessões e ver onde as pessoas clicam. Só roda pra quem aceitou cookies de análise.</p>

        <?php if (!empty($c['project_id']) && !$valid_id): ?>
            <div class="as-notice danger">
                <div class="as-notice-kicker">Project ID inválido</div>
                <p>O ID precisa ter de 8 a 12 letras e números, sem espaços. Corrija o campo abaixo — até lá, o Clarity fica desligado mesmo com a chave marcada.</p>
            </div>
        <?php endif; ?>

        <?php // Doutrina: o dado (as gravações) vem antes do controle. ?>
        <?php if ($valid_id): ?>
            <?php AdSpirit_Menu::card_open('Suas gravações', 'Atalho pro projeto Clarity deste site'); ?>
            <p>
                <a href="<?php echo esc_url($dashboard_url); ?>" target="_blank" rel="noopener" class="button button-secondary">
                    Abrir painel do Clarity &rarr;
                </a>
            </p>
            <?php AdSpirit_Menu::card_close(); ?>
        <?php endif; ?>

        <?php AdSpirit_Menu::card_open('Configuração', 'O Project ID aparece em <em>clarity.microsoft.com → Settings → Setup</em>', $status_badge); ?>
        <?php AdSpirit_Menu::form_open('clarity'); ?>

        <div class="as-toggle">
            <input type="checkbox" id="clarity_enabled" name="enabled" value="1" <?php checked($c['enabled'], '1'); ?>>
            <label class="t" for="clarity_enabled">Clarity ligado<small>Precisa de um Project ID válido e do aceite de cookies de análise pelo visitante.</small></label>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="clarity_project_id">Project ID</label>
            <input type="text" id="clarity_project_id" name="project_id" value="<?php echo esc_attr($c['project_id']); ?>" class="regular-text" autocomplete="off" pattern="[a-zA-Z0-9]{8,12}" placeholder="abc1d2e3f4">
            <p class="description">De 8 a 12 letras e números. Copie do painel do Clarity, em Settings → Setup.</p>
        </div>

        <div class="as-toggle">
            <input type="checkbox" id="clarity_identify" name="identify_on_lead" value="1" <?php checked($c['identify_on_lead'], '1'); ?>>
            <label class="t" for="clarity_identify">Ligar a gravação ao lead<small>Quando alguém envia um formulário, a sessão gravada fica identificada com esse lead.</small></label>
        </div>

        <div class="as-toggle">
            <input type="checkbox" id="clarity_share_vid" name="share_visitor_id" value="1" <?php checked($c['share_visitor_id'], '1'); ?>>
            <label class="t" for="clarity_share_vid">Cruzar com o AdSpirit<small>Marca a gravação com o mesmo código de visitante do CRM. Recomendado.</small></label>
        </div>

        <div class="as-toggle">
            <input type="checkbox" id="clarity_hash_email" name="hash_email" value="1" <?php checked($c['hash_email'], '1'); ?>>
            <label class="t" for="clarity_hash_email">Proteger o email do lead<small>Envia o email embaralhado (hash) em vez do texto real. Recomendado; desligue só se precisar cruzar por email em outro sistema.</small></label>
        </div>

        <details class="as-help">
            <summary>Detalhes técnicos (pra suporte)</summary>
            <ul>
                <li>O tag oficial do Clarity é injetado no <code>&lt;head&gt;</code>, condicionado ao consent de análise do aviso de cookies.</li>
                <li>Propaga <code>visitor_id</code>, <code>brand_slug</code>, <code>session_id</code> e variante de teste A/B como custom tags.</li>
                <li>O identify dispara no submit do CF7 e no evento <code>adspirit:form-submitted</code>.</li>
                <li>Com a proteção de email ligada, vai o SHA-256 (primeiros 16 caracteres) em vez do texto.</li>
            </ul>
        </details>

        <?php AdSpirit_Menu::form_close('Salvar Clarity'); ?>
        <?php AdSpirit_Menu::card_close(); ?>
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
