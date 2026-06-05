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
        // Ícone do menu adaptativo (recolore como os dashicons) — CSS global,
        // todas as páginas do admin (o menu está sempre visível).
        add_action(
            'admin_head',
            AdSpirit_Safe_Hook::action(array($this, 'print_menu_icon_css'), 'menu_icon_css')
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
            'submissions'  => 'Submissões recentes',
            'logs'         => 'Logs',
        );
        return apply_filters('adspirit_connector_tabs', $tabs);
    }

    /**
     * Agrupamento das tabs em temas (nível 1) com sub-tabs (nível 2).
     * Features continuam registrando tabs flat via `adspirit_connector_tabs`;
     * aqui só mapeamos slug → grupo. Tabs registradas que não estão em nenhum
     * grupo caem num grupo "Mais" automático (nada some).
     */
    public static function tab_groups() {
        return apply_filters('adspirit_connector_tab_groups', array(
            'geral'       => array('label' => 'Geral',       'tabs' => array('overview', 'connection')),
            'captura'     => array('label' => 'Captura',     'tabs' => array('qualifier', 'forms', 'antispam', 'lgpd')),
            'tracking'    => array('label' => 'Tracking',    'tabs' => array('capi-meta', 'ga4', 'cross-domain', 'behavioral', 'clarity')),
            'integracoes' => array('label' => 'Integrações', 'tabs' => array('customerio', 'mailchimp', 'webhook-out', 'ab-tests')),
            'sistema'     => array('label' => 'Sistema',     'tabs' => array('submissions', 'logs')),
        ));
    }

    /**
     * Monta os grupos só com as tabs que de fato existem (registradas),
     * preservando ordem. Tabs órfãs (sem grupo) vão pra "Mais".
     * Retorna [ group_key => ['label'=>..., 'tabs'=> [slug=>label]] ].
     */
    public static function grouped_tabs() {
        $tabs = self::tabs();
        $groups = self::tab_groups();
        $out = array();
        $seen = array();
        foreach ($groups as $gkey => $g) {
            $sub = array();
            foreach ($g['tabs'] as $slug) {
                if (isset($tabs[$slug])) { $sub[$slug] = $tabs[$slug]; $seen[$slug] = true; }
            }
            if ($sub) $out[$gkey] = array('label' => $g['label'], 'tabs' => $sub);
        }
        $orphans = array();
        foreach ($tabs as $slug => $label) {
            if (empty($seen[$slug])) $orphans[$slug] = $label;
        }
        if ($orphans) $out['mais'] = array('label' => 'Mais', 'tabs' => $orphans);
        return $out;
    }

    /** Data-URI do SVG da marca (limpo), usado no ícone e na máscara CSS. */
    private static function mark_data_uri() {
        $svg_path = ADSPIRIT_CONNECTOR_DIR . 'assets/adspirit-mark.svg';
        $svg = is_readable($svg_path) ? file_get_contents($svg_path) : '';
        if (!$svg) {
            $svg = '<svg viewBox="0 0 20 20" xmlns="http://www.w3.org/2000/svg"><circle cx="10" cy="10" r="6"/></svg>';
        } else {
            $svg = preg_replace('/<\?xml[^>]+\?>\s*/i', '', $svg);
            $svg = preg_replace('/style="enable-background:[^"]*"/i', '', $svg);
        }
        return 'data:image/svg+xml;base64,' . base64_encode($svg);
    }

    /**
     * CSS global do ícone do menu. WP NÃO recolore um SVG passado como
     * data-URI (vira background-image e mantém o preto). Pra adaptar como os
     * dashicons: desenha o ícone via mask + background-color:currentColor —
     * currentColor herda a cor que o WP já aplica no `.wp-menu-image:before`
     * (cinza padrão → branco no hover/ativo, por esquema de cor).
     */
    public function print_menu_icon_css() {
        $mask = self::mark_data_uri();
        $sel = '#toplevel_page_' . self::PAGE_SLUG . ' .wp-menu-image';
        echo '<style id="adspirit-menu-icon">'
            . $sel . '{background-image:none!important;}'
            . $sel . '::before{content:"";display:block;width:20px;height:20px;margin:7px auto 0;'
            . 'background-color:currentColor;'
            . '-webkit-mask:url(\'' . $mask . '\') no-repeat center/20px 20px;'
            . 'mask:url(\'' . $mask . '\') no-repeat center/20px 20px;}'
            . '</style>';
    }

    public function register_menu() {
        // Fallback: WP mostra esse base64 se o CSS do ícone não carregar.
        // O visual real (adaptativo) vem de print_menu_icon_css().
        $icon = self::mark_data_uri();

        add_menu_page(
            'AdSpirit Connector',
            'AdSpirit',
            self::CAPABILITY,
            self::PAGE_SLUG,
            array($this, 'render_page'),
            $icon,
            3 // logo abaixo do Painel (Dashboard=2), acima de Posts(5)
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
        // Design system do AdSpirit dentro do wp-admin. Tudo escopado em
        // .adspirit-app pra não vazar pra outros plugins.
        $css = '
/* ============================================================
   AdSpirit Connector — design system (mirror do CRM)
   ============================================================ */
.adspirit-app {
  --as-accent: #00B7B7;
  --as-accent-soft: #E6F7F7;
  --as-accent-hover: #009999;
  --as-ink: #0F1419;
  --as-ink-soft: #3A4550;
  --as-ink-faint: #8A95A0;
  --as-line: #E5EAEE;
  --as-bg: #FFFFFF;
  --as-bg-card: #F8FAFC;
  --as-bg-subtle: #F3F6F9;
  --as-bg-warning: #FFF8E1;
  --as-bg-danger: #FFF0F0;
  --as-bg-success: #DCFCE7;
  --as-warning: #B07900;
  --as-danger: #C73838;
  --as-success: #15803D;
  font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Segoe UI", "Helvetica Neue", Arial, sans-serif;
  color: var(--as-ink);
  font-size: 13.5px;
  line-height: 1.55;
  max-width: 1100px;
  margin-right: 20px;
  padding: 8px 0 32px;
}
.adspirit-app * { box-sizing: border-box; }

/* ---------- Header (cinematic/premium typography) ---------- */
/* Inspiração: login AdSpirit / stripe.com/login / rauno.me — wordmark
   thin (weight 200) com letter-spacing negativo apertado, ® minúsculo
   superscript em accent, sense of calm + premium. */
.adspirit-app .as-header { margin: 8px 0 24px; }
.adspirit-app .as-kicker {
  display: inline-block;
  font-size: 10px;
  letter-spacing: 0.28em;
  text-transform: uppercase;
  color: var(--as-accent);
  font-weight: 600;
  margin-bottom: 14px;
}
.adspirit-app h1.as-title {
  font-family: -apple-system, BlinkMacSystemFont, "SF Pro Display", "Inter", "Helvetica Neue", Arial, sans-serif;
  font-size: 44px;
  font-weight: 200;
  letter-spacing: -0.045em;
  color: var(--as-ink);
  margin: 0;
  padding: 0;
  display: flex;
  align-items: center;
  gap: 18px;
  line-height: 1.05;
}
.adspirit-app h1.as-title .wordmark {
  display: inline-flex;
  align-items: baseline;
  gap: 14px;
}
.adspirit-app h1.as-title .brand { font-weight: 300; }
.adspirit-app h1.as-title .reg {
  font-size: 14px;
  font-weight: 400;
  color: var(--as-accent);
  vertical-align: super;
  margin-left: 2px;
  letter-spacing: 0;
}
.adspirit-app h1.as-title .product { font-weight: 200; color: var(--as-ink-soft); }
.adspirit-app .as-version {
  font-size: 10.5px;
  font-weight: 600;
  letter-spacing: 0.1em;
  text-transform: uppercase;
  background: var(--as-bg-subtle);
  color: var(--as-ink-faint);
  border: 1px solid var(--as-line);
  padding: 4px 9px;
  border-radius: 3px;
  font-family: ui-monospace, "SF Mono", Monaco, Consolas, monospace;
  align-self: center;
}
.adspirit-app .as-lede {
  font-size: 14px;
  color: var(--as-ink-faint);
  margin: 12px 0 0;
  max-width: 720px;
  font-weight: 300;
  letter-spacing: -0.005em;
}

/* ---------- Header bar (título + ações no topo) ---------- */
.adspirit-app .as-header-bar {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: 20px;
  flex-wrap: wrap;
}
.adspirit-app .as-header-actions {
  display: flex;
  gap: 8px;
  flex-wrap: wrap;
  align-items: center;
  padding-top: 8px;
}
.adspirit-app .as-header-actions form { margin: 0; padding: 0; }

/* ---------- Grupos de tabs (nível 1) — segmented pill ---------- */
.adspirit-app .as-groups {
  display: flex;
  flex-wrap: wrap;
  gap: 4px;
  background: var(--as-bg-subtle);
  border: 1px solid var(--as-line);
  border-radius: 10px;
  padding: 4px;
  margin: 22px 0 16px;
}
.adspirit-app .as-groups a {
  padding: 7px 16px;
  font-size: 13px;
  font-weight: 600;
  color: var(--as-ink-soft);
  text-decoration: none;
  border-radius: 7px;
  white-space: nowrap;
  transition: background 0.12s, color 0.12s;
}
.adspirit-app .as-groups a:hover { color: var(--as-ink); }
.adspirit-app .as-groups a:focus { outline: none; box-shadow: none; }
.adspirit-app .as-groups a.active {
  background: var(--as-bg);
  color: var(--as-accent);
  box-shadow: 0 1px 2px rgba(15,20,25,0.08);
}

/* ---------- Nav tabs (replace wp-admin nav-tab-wrapper) ---------- */
.adspirit-app .as-tabs {
  display: flex;
  gap: 0;
  border-bottom: 1px solid var(--as-line);
  margin: 18px 0 24px;
  flex-wrap: wrap;
}
/* sub-tabs (nível 2) — encostadas no grupo acima */
.adspirit-app .as-subtabs { margin-top: 0; margin-bottom: 22px; }
.adspirit-app .as-tabs a {
  padding: 10px 16px;
  font-size: 13px;
  color: var(--as-ink-soft);
  text-decoration: none;
  border-bottom: 2px solid transparent;
  margin-bottom: -1px;
  font-weight: 500;
  transition: color 0.12s, border-color 0.12s;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  white-space: nowrap;
}
.adspirit-app .as-tabs a:hover { color: var(--as-ink); }
.adspirit-app .as-tabs a:focus { outline: none; box-shadow: none; }
.adspirit-app .as-tabs a.active {
  color: var(--as-accent);
  border-bottom-color: var(--as-accent);
  font-weight: 600;
}

/* ---------- Section title (outside cards) ---------- */
.adspirit-app h2.as-section {
  font-size: 18px;
  font-weight: 600;
  letter-spacing: -0.015em;
  color: var(--as-ink);
  margin: 28px 0 12px;
  padding: 0;
  border: 0;
  display: flex;
  align-items: center;
  gap: 8px;
}
.adspirit-app h2.as-section .as-kicker-inline {
  font-size: 10px;
  letter-spacing: 0.22em;
  text-transform: uppercase;
  color: var(--as-accent);
  font-weight: 700;
  background: var(--as-accent-soft);
  padding: 3px 7px;
  border-radius: 3px;
}
.adspirit-app .as-section-help {
  font-size: 12.5px;
  color: var(--as-ink-faint);
  margin: -8px 0 14px;
  max-width: 720px;
}

/* ---------- Cards ---------- */
.adspirit-app .as-card {
  border: 1px solid var(--as-line);
  border-radius: 10px;
  background: var(--as-bg);
  margin: 0 0 16px;
  overflow: hidden;
}
.adspirit-app .as-card-header {
  padding: 14px 18px;
  border-bottom: 1px solid var(--as-line);
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: 12px;
  flex-wrap: wrap;
  background: linear-gradient(180deg, #FCFDFE 0%, var(--as-bg) 100%);
}
.adspirit-app .as-card-header h3 {
  font-size: 13.5px;
  font-weight: 600;
  color: var(--as-ink);
  margin: 0;
  padding: 0;
}
.adspirit-app .as-card-header .as-card-sub {
  font-size: 11.5px;
  color: var(--as-ink-faint);
  margin-top: 2px;
}
.adspirit-app .as-card-body { padding: 18px; }
.adspirit-app .as-card-body.tight { padding: 12px 18px; }

/* ---------- Buttons ---------- */
.adspirit-app .button,
.adspirit-app .button-secondary,
.adspirit-app input[type="submit"].button {
  font-family: inherit;
  font-size: 12.5px;
  font-weight: 600;
  padding: 7px 13px;
  border-radius: 6px;
  cursor: pointer;
  transition: background 0.12s, border-color 0.12s, color 0.12s;
  text-decoration: none;
  display: inline-flex;
  align-items: center;
  gap: 6px;
  line-height: 1.2;
  height: auto;
  min-height: 0;
  background: var(--as-bg);
  border: 1px solid var(--as-line);
  color: var(--as-ink-soft);
  text-shadow: none;
  box-shadow: none;
  vertical-align: middle;
}
.adspirit-app .button:hover,
.adspirit-app .button-secondary:hover {
  background: var(--as-bg-subtle);
  border-color: var(--as-ink-faint);
  color: var(--as-ink);
}
.adspirit-app .button.button-primary,
.adspirit-app .button-primary,
.adspirit-app input[type="submit"].button-primary {
  background: var(--as-accent-soft);
  border-color: var(--as-accent);
  color: var(--as-accent);
}
.adspirit-app .button.button-primary:hover,
.adspirit-app .button-primary:hover,
.adspirit-app input[type="submit"].button-primary:hover {
  background: var(--as-accent);
  border-color: var(--as-accent);
  color: white;
}
.adspirit-app .button.button-danger {
  background: var(--as-bg-danger);
  border-color: var(--as-danger);
  color: var(--as-danger);
}
.adspirit-app .button.button-danger:hover {
  background: var(--as-danger);
  color: white;
}
.adspirit-app .button:focus { outline: none; box-shadow: 0 0 0 3px rgba(0,183,183,0.25); }

/* ---------- Inputs ---------- */
.adspirit-app input[type="text"],
.adspirit-app input[type="url"],
.adspirit-app input[type="password"],
.adspirit-app input[type="email"],
.adspirit-app input[type="number"],
.adspirit-app textarea,
.adspirit-app select {
  font-family: inherit;
  font-size: 13px;
  padding: 7px 11px;
  border: 1px solid var(--as-line);
  border-radius: 6px;
  background: var(--as-bg);
  color: var(--as-ink);
  box-shadow: none;
  transition: border-color 0.12s, box-shadow 0.12s;
  min-height: 0;
  line-height: 1.4;
}
.adspirit-app input:focus,
.adspirit-app textarea:focus,
.adspirit-app select:focus {
  outline: none;
  border-color: var(--as-accent);
  box-shadow: 0 0 0 3px rgba(0,183,183,0.18);
}
.adspirit-app input[type="checkbox"],
.adspirit-app input[type="radio"] {
  accent-color: var(--as-accent);
  margin-right: 6px;
}
.adspirit-app textarea { padding: 10px 12px; line-height: 1.5; }
.adspirit-app input.regular-text, .adspirit-app input.large-text { width: 100%; max-width: 480px; }
.adspirit-app textarea.large-text { width: 100%; max-width: 720px; }

/* ---------- form-table override ---------- */
.adspirit-app .form-table { margin: 0; border-collapse: collapse; }
.adspirit-app .form-table th {
  font-size: 12.5px;
  font-weight: 600;
  color: var(--as-ink);
  padding: 14px 16px 14px 0;
  width: 240px;
  vertical-align: top;
}
.adspirit-app .form-table td {
  padding: 12px 0;
  vertical-align: top;
}
.adspirit-app .form-table p.description {
  font-size: 12px;
  color: var(--as-ink-faint);
  margin: 6px 0 0;
  line-height: 1.5;
}
.adspirit-app .form-table label { font-size: 13px; color: var(--as-ink-soft); }
.adspirit-app .form-table tr { border-bottom: 1px solid var(--as-line); }
.adspirit-app .form-table tr:last-child { border-bottom: 0; }

/* ---------- Badges (outlined per design system) ---------- */
.adspirit-app .as-badge {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  font-size: 10px;
  font-weight: 700;
  letter-spacing: 0.08em;
  text-transform: uppercase;
  padding: 3px 8px;
  border-radius: 3px;
  border: 1px solid;
  white-space: nowrap;
  vertical-align: middle;
}
.adspirit-app .as-badge.ok { background: var(--as-bg-success); color: var(--as-success); border-color: var(--as-success); }
.adspirit-app .as-badge.warn { background: var(--as-bg-warning); color: var(--as-warning); border-color: var(--as-warning); }
.adspirit-app .as-badge.danger { background: var(--as-bg-danger); color: var(--as-danger); border-color: var(--as-danger); }
.adspirit-app .as-badge.accent { background: var(--as-accent-soft); color: var(--as-accent); border-color: var(--as-accent); }
.adspirit-app .as-badge.muted { background: var(--as-bg-subtle); color: var(--as-ink-soft); border-color: var(--as-line); }

/* ---------- Code/mono ---------- */
.adspirit-app code {
  font-family: ui-monospace, "SF Mono", Monaco, Consolas, monospace;
  font-size: 11.5px;
  background: var(--as-bg-subtle);
  color: var(--as-ink);
  padding: 1px 6px;
  border-radius: 3px;
  border: 1px solid var(--as-line);
}
.adspirit-app pre {
  font-family: ui-monospace, "SF Mono", Monaco, Consolas, monospace;
  font-size: 12px;
  background: var(--as-bg-subtle);
  color: var(--as-ink);
  padding: 12px 14px;
  border-radius: 6px;
  border: 1px solid var(--as-line);
  line-height: 1.55;
  overflow-x: auto;
  max-width: 900px;
}

/* ---------- Notices (replace wp-admin .notice) ---------- */
.adspirit-app .as-notice {
  border-left: 3px solid;
  padding: 12px 16px;
  border-radius: 0 6px 6px 0;
  background: var(--as-bg-subtle);
  margin: 0 0 16px;
}
.adspirit-app .as-notice.info { border-left-color: var(--as-accent); background: var(--as-accent-soft); }
.adspirit-app .as-notice.info .as-notice-kicker { color: var(--as-accent); }
.adspirit-app .as-notice.warn { border-left-color: var(--as-warning); background: var(--as-bg-warning); }
.adspirit-app .as-notice.warn .as-notice-kicker { color: var(--as-warning); }
.adspirit-app .as-notice.danger { border-left-color: var(--as-danger); background: var(--as-bg-danger); }
.adspirit-app .as-notice.danger .as-notice-kicker { color: var(--as-danger); }
.adspirit-app .as-notice .as-notice-kicker {
  font-size: 10px;
  letter-spacing: 0.18em;
  text-transform: uppercase;
  font-weight: 700;
  margin-bottom: 4px;
}
.adspirit-app .as-notice .as-notice-title { font-weight: 600; color: var(--as-ink); margin: 0 0 4px; font-size: 13.5px; }
.adspirit-app .as-notice p { margin: 4px 0; font-size: 12.5px; color: var(--as-ink-soft); }
.adspirit-app .as-notice p:last-child { margin-bottom: 0; }

/* ---------- Metric grid ---------- */
.adspirit-app .as-metric-grid {
  display: grid;
  grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
  gap: 10px;
  margin: 0 0 18px;
}
.adspirit-app .as-metric {
  background: var(--as-bg);
  border: 1px solid var(--as-line);
  border-radius: 8px;
  padding: 14px 16px;
}
.adspirit-app .as-metric .label {
  font-size: 10px;
  color: var(--as-ink-faint);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  font-weight: 700;
}
.adspirit-app .as-metric .value {
  font-size: 26px;
  font-weight: 600;
  color: var(--as-ink);
  margin-top: 4px;
  letter-spacing: -0.02em;
  line-height: 1.1;
  font-family: ui-monospace, "SF Mono", Monaco, Consolas, monospace;
}
.adspirit-app .as-metric .value.text { font-family: inherit; font-size: 16px; }
.adspirit-app .as-metric .value.danger { color: var(--as-danger); }
.adspirit-app .as-metric .value.warn { color: var(--as-warning); }
.adspirit-app .as-metric .sub { font-size: 11.5px; color: var(--as-ink-faint); margin-top: 3px; }

/* ---------- Checklist ---------- */
.adspirit-app .as-checklist {
  list-style: none;
  margin: 0 0 16px;
  padding: 0;
}
.adspirit-app .as-checklist li {
  padding: 14px 16px;
  border: 1px solid var(--as-line);
  border-radius: 8px;
  margin-bottom: 8px;
  display: flex;
  gap: 12px;
  background: var(--as-bg);
}
.adspirit-app .as-checklist .icon {
  flex-shrink: 0;
  width: 22px;
  height: 22px;
  border-radius: 50%;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  margin-top: 1px;
}
.adspirit-app .as-checklist .icon.done {
  background: var(--as-accent-soft);
  color: var(--as-accent);
  border: 1.5px solid var(--as-accent);
}
.adspirit-app .as-checklist .icon.todo {
  background: var(--as-bg-subtle);
  color: var(--as-ink-faint);
  border: 1.5px dashed var(--as-ink-faint);
}
.adspirit-app .as-checklist .icon.fail {
  background: var(--as-bg-danger);
  color: var(--as-danger);
  border: 1.5px solid var(--as-danger);
}
.adspirit-app .as-checklist .body { flex: 1; min-width: 0; }
.adspirit-app .as-checklist .title { font-weight: 600; color: var(--as-ink); font-size: 13.5px; }
.adspirit-app .as-checklist .desc { font-size: 12.5px; color: var(--as-ink-soft); margin-top: 3px; line-height: 1.5; }
.adspirit-app .as-checklist .cta { margin-top: 10px; }

/* ---------- Tables ---------- */
.adspirit-app table.widefat,
.adspirit-app table.as-table {
  width: 100%;
  border-collapse: separate;
  border-spacing: 0;
  background: var(--as-bg);
  border: 1px solid var(--as-line);
  border-radius: 8px;
  overflow: hidden;
  box-shadow: none;
}
.adspirit-app table.widefat thead,
.adspirit-app table.as-table thead {
  background: var(--as-bg-subtle);
}
.adspirit-app table.widefat th,
.adspirit-app table.as-table th {
  text-align: left;
  font-size: 10.5px;
  text-transform: uppercase;
  letter-spacing: 0.08em;
  color: var(--as-ink-faint);
  padding: 10px 14px;
  font-weight: 700;
  border-bottom: 1px solid var(--as-line);
}
.adspirit-app table.widefat td,
.adspirit-app table.as-table td {
  padding: 11px 14px;
  border-top: 1px solid var(--as-line);
  font-size: 12.5px;
  color: var(--as-ink);
  vertical-align: top;
}
.adspirit-app table.widefat tbody tr:first-child td,
.adspirit-app table.as-table tbody tr:first-child td { border-top: 0; }
.adspirit-app table.widefat.striped tbody tr:nth-child(even) { background: rgba(228,234,240,0.20); }

/* ---------- Form scaffolding ---------- */
.adspirit-app .as-field { margin-bottom: 14px; }
.adspirit-app .as-field-label {
  display: block;
  font-size: 10.5px;
  font-weight: 700;
  color: var(--as-ink-faint);
  text-transform: uppercase;
  letter-spacing: 0.08em;
  margin-bottom: 6px;
}
.adspirit-app .as-field-help {
  font-size: 11.5px;
  color: var(--as-ink-faint);
  margin: 5px 0 0;
  line-height: 1.5;
}

/* ---------- Details (collapsible) ---------- */
.adspirit-app details {
  border: 1px solid var(--as-line);
  border-radius: 8px;
  padding: 12px 16px;
  background: var(--as-bg-card);
  margin: 12px 0;
}
.adspirit-app summary {
  cursor: pointer;
  font-weight: 600;
  font-size: 12.5px;
  color: var(--as-ink);
}

/* ---------- Misc wp-admin overrides ---------- */
.adspirit-app .wrap { padding: 0; }
.adspirit-app .notice {
  padding: 12px 16px;
  margin: 0 0 16px;
  border-radius: 0 6px 6px 0;
  border-left-width: 3px;
  background: var(--as-bg-subtle);
}
.adspirit-app .notice-info { border-left-color: var(--as-accent); background: var(--as-accent-soft); }
.adspirit-app .notice-warning { border-left-color: var(--as-warning); background: var(--as-bg-warning); }
.adspirit-app .notice-error { border-left-color: var(--as-danger); background: var(--as-bg-danger); }
.adspirit-app .notice-success { border-left-color: var(--as-success); background: var(--as-bg-success); }
.adspirit-app .description { color: var(--as-ink-faint); font-size: 12px; }
.adspirit-app .submit { padding: 16px 0 0; margin: 16px 0 0; border-top: 1px solid var(--as-line); }

/* ---------- Test result box ---------- */
.adspirit-app .as-test-result { margin-top: 12px; max-width: 720px; }

/* ---------- Field mapping grid ---------- */
.adspirit-app .as-field-map-row { padding: 10px 0; border-bottom: 1px solid var(--as-line); }
.adspirit-app .as-field-map-row:last-child { border-bottom: 0; }

/* ---------- Spacers ---------- */
.adspirit-app hr.as-hr { border: 0; border-top: 1px solid var(--as-line); margin: 24px 0; }
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

        // Agrupamento (nível 1) + descoberta do grupo da tab atual.
        $grouped = self::grouped_tabs();
        $current_group = null;
        foreach ($grouped as $gkey => $g) {
            if (isset($g['tabs'][$current_tab])) { $current_group = $gkey; break; }
        }
        if ($current_group === null && $grouped) {
            $current_group = array_key_first($grouped);
        }

        ?>
        <div class="wrap adspirit-app">
            <header class="as-header">
                <div class="as-header-bar">
                    <div>
                        <div class="as-kicker">Agência Digitals · Plataforma de operação</div>
                        <h1 class="as-title">
                            <span class="wordmark">
                                <span class="brand">AdSpirit<span class="reg">®</span></span>
                                <span class="product">Connector</span>
                            </span>
                            <span class="as-version">v<?php echo esc_html(ADSPIRIT_CONNECTOR_VERSION); ?></span>
                        </h1>
                    </div>
                    <div class="as-header-actions">
                        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                            <input type="hidden" name="action" value="adspirit_force_update_check">
                            <?php wp_nonce_field('adspirit_force_update_check'); ?>
                            <button type="submit" class="button" title="Limpa cache + checa o GitHub agora (não espera o ciclo de 6h)">Verificar atualizações</button>
                        </form>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=connection')); ?>" class="button">Conexão CRM</a>
                    </div>
                </div>
                <p class="as-lede">Conecta o WordPress ao CRM AdSpirit em real-time. Tudo configurado pelo painel — sem editar código nem env do servidor.</p>
            </header>

            <?php settings_errors(); ?>

            <?php // Nível 1 — grupos de temas ?>
            <nav class="as-groups">
                <?php foreach ($grouped as $gkey => $g):
                    $first_slug = array_key_first($g['tabs']); ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=' . $first_slug)); ?>"
                       class="<?php echo $current_group === $gkey ? 'active' : ''; ?>">
                        <?php echo esc_html($g['label']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php // Nível 2 — sub-tabs do grupo atual (só se o grupo tem mais de 1) ?>
            <?php $sub = isset($grouped[$current_group]['tabs']) ? $grouped[$current_group]['tabs'] : array(); ?>
            <?php if (count($sub) > 1): ?>
            <nav class="as-tabs as-subtabs">
                <?php foreach ($sub as $slug => $label): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=' . $slug)); ?>"
                       class="<?php echo $current_tab === $slug ? 'active' : ''; ?>">
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>

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
        <div class="as-notice danger">
            <div class="as-notice-kicker">Safe Mode ativo</div>
            <p class="as-notice-title">Features do plugin estão desligadas — site segue intocado</p>
            <p>
                <strong>Motivo:</strong> <code><?php echo esc_html($reason ?: 'manual'); ?></code>
                <?php if ($at): ?>· ativado <?php echo esc_html(human_time_diff($at, time()) . ' atrás'); ?><?php endif; ?>
            </p>
            <p>
                Enquanto Safe Mode estiver ligado, o plugin não envia leads pro CRM, não dispara Meta CAPI/GA4 e não injeta scripts. A aba <em>Logs</em> mostra exatamente o que aconteceu.
            </p>
            <p style="margin-top:12px;">
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
            <p class="submit">
                <button type="submit" class="button button-primary"><?php echo esc_html($submit_label); ?></button>
            </p>
        </form>
        <?php
    }

    /**
     * Helper pra abrir card AdSpirit. Uso:
     *   AdSpirit_Menu::card_open('Anti-spam', 'Subtítulo opcional');
     *   ... conteúdo
     *   AdSpirit_Menu::card_close();
     */
    public static function card_open($title, $subtitle = '', $right_html = '') {
        ?>
        <section class="as-card">
            <header class="as-card-header">
                <div>
                    <h3><?php echo esc_html($title); ?></h3>
                    <?php if ($subtitle): ?><div class="as-card-sub"><?php echo wp_kses_post($subtitle); ?></div><?php endif; ?>
                </div>
                <?php if ($right_html): echo $right_html; endif; ?>
            </header>
            <div class="as-card-body">
        <?php
    }

    public static function card_close() {
        ?>
            </div>
        </section>
        <?php
    }
}
