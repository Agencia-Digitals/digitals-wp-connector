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
            'setup'        => 'Setup',
            'submissions'  => 'Submissões recentes',
            'logs'         => 'Logs',
        );
        $tabs = apply_filters('adspirit_connector_tabs', $tabs);
        // UX 3.0 (regra do Pedro 08-18): rótulo é pelo que a pessoa FAZ,
        // nunca pelo nome do módulo. Override central — os módulos seguem
        // registrando como sempre; a exibição (nav, submenu do WP, títulos)
        // usa o rótulo leigo. Slug/handler/URL não mudam.
        $meta = self::tab_meta();
        foreach ($tabs as $slug => $label) {
            if (isset($meta[$slug]['label'])) $tabs[$slug] = $meta[$slug]['label'];
        }
        return $tabs;
    }

    /**
     * Rótulos leigos + descrição de uma linha por tab (tooltip da nav e
     * legenda da tab ativa). Fonte única da linguagem do painel.
     */
    public static function tab_meta() {
        return apply_filters('adspirit_connector_tab_meta', array(
            'overview'     => array('label' => 'Visão geral',           'desc' => 'Status da conexão, checklist e os números dos últimos dias.'),
            'setup'        => array('label' => 'Primeiros passos',      'desc' => 'Checklist guiado pra deixar tudo configurado.'),
            'submissions'  => array('label' => 'Leads enviados',        'desc' => 'Todo lead capturado no site, com status de entrega ao AdSpirit, quarentena de spam e reenvio.'),
            'formularios'  => array('label' => 'Formulários',           'desc' => 'Todos os formulários do site num lugar só — crie, edite, visualize e configure cada um.'),
            'qualifier'    => array('label' => 'Form de avaliação',     'desc' => 'Configurações deste formulário: perguntas, qualificação e importação de roteiro.'),
            'builder'      => array('label' => 'Editar formulário',     'desc' => 'Campos, finalidade e regras deste formulário.'),
            'forms'        => array('label' => 'Mapear campos',         'desc' => 'Como cada campo deste formulário chega no AdSpirit.'),
            'cf7-scope'    => array('label' => 'Contact Form 7',        'desc' => 'Escolha quais formulários do CF7 enviam leads.'),
            'antispam'     => array('label' => 'Anti-spam',             'desc' => 'Bloqueio automático de bots; o que for barrado fica em quarentena revisável.'),
            'turnstile'    => array('label' => 'Verificação Cloudflare','desc' => 'Camada anti-bot invisível (opcional).'),
            'capi-meta'    => array('label' => 'Conversões Meta',       'desc' => 'Envia leads e eventos direto pros anúncios do Facebook/Instagram.'),
            'ga4'          => array('label' => 'Conversões Google',     'desc' => 'Envia leads e eventos pro Google Analytics 4.'),
            'behavioral'   => array('label' => 'Comportamento no site', 'desc' => 'Rolagem, cliques e engajamento anexados a cada lead.'),
            'clarity'      => array('label' => 'Gravações (Clarity)',   'desc' => 'Mapas de calor e gravações de sessão da Microsoft.'),
            'cross-domain' => array('label' => 'Rastreio entre sites',  'desc' => 'Mantém a jornada quando o visitante troca de domínio.'),
            'ab-tests'     => array('label' => 'Testes A/B',            'desc' => 'Compare versões de formulário e veja qual converte mais.'),
            'lgpd'         => array('label' => 'Aviso de cookies',      'desc' => 'Banner de consentimento exibido no site.'),
            'webhook-out'  => array('label' => 'Webhooks de saída',     'desc' => 'Manda cada lead também pra outros sistemas (n8n, Zapier…).'),
            'customerio'   => array('label' => 'Customer.io',           'desc' => 'Envia leads pra sua conta Customer.io.'),
            'mailchimp'    => array('label' => 'Mailchimp',             'desc' => 'Inscreve leads numa lista do Mailchimp.'),
            'connection'   => array('label' => 'Conexão com o AdSpirit','desc' => 'Endereço, marca e chave de acesso — o coração do plugin.'),
            'logs'         => array('label' => 'Diagnóstico',           'desc' => 'Registros de envio, bloqueios e erros — pra suporte sem SSH.'),
        ));
    }

    /**
     * Agrupamento das tabs em temas (nível 1) com sub-tabs (nível 2).
     * Features continuam registrando tabs flat via `adspirit_connector_tabs`;
     * aqui só mapeamos slug → grupo. Tabs registradas que não estão em nenhum
     * grupo caem num grupo "Mais" automático (nada some).
     */
    public static function tab_groups() {
        // UX 3.0 — grupos por TAREFA do usuário, não por módulo:
        // "Leads enviados" abre o grupo de leads (é o que se olha todo dia);
        // builder e cf7-scope, que caíam no "Mais", ganham casa; Testes A/B
        // e Aviso de cookies moram em "Medir campanhas" (são de medição/
        // consentimento, não de integração/captura).
        // Reestruturação 08-18 (Pedro): navegação pelo USO —
        // Início (ver como está) · Receber leads (formulários, form-first)
        // · Tracking (medição + conversões com suas configs) · Config.
        // avançadas (integrações, anti-spam, sistema). As telas de edição
        // (qualifier/builder/mapear) saem da nav: são DETALHE de um form,
        // alcançadas pelo hub Formulários — a lógica é escolher o form e
        // configurar DENTRO dele.
        return apply_filters('adspirit_connector_tab_groups', array(
            'inicio'   => array('label' => 'Início',                  'tabs' => array('overview', 'setup', 'connection')),
            'leads'    => array('label' => 'Receber leads',           'tabs' => array('formularios', 'submissions', 'ab-tests')),
            'tracking' => array('label' => 'Tracking',                'tabs' => array('capi-meta', 'ga4', 'behavioral', 'clarity', 'cross-domain')),
            'avancado' => array('label' => 'Configurações avançadas', 'tabs' => array('cf7-scope', 'antispam', 'turnstile', 'webhook-out', 'customerio', 'mailchimp', 'lgpd', 'logs')),
        ));
    }

    /**
     * Tabs que existem mas NÃO aparecem na navegação — são telas de
     * detalhe de um formulário (a porta é o hub Formulários). Deep link
     * continua funcionando; só a nav e o fallback "Mais" as escondem.
     */
    public static function hidden_tabs() {
        return apply_filters('adspirit_connector_hidden_tabs', array('qualifier', 'builder', 'forms'));
    }

    /**
     * Saúde por grupo — o ponto de status na navegação responde "tá tudo
     * ok?" antes de qualquer clique. Sinais BARATOS (options + 1 COUNT) e
     * fail-soft: 'ok' (verde) · 'warn' (âmbar) · 'off' (sem ponto).
     */
    public static function group_health() {
        return AdSpirit_Safe_Hook::try_run(function () {
            $core = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();
            $connected = !empty($core['brand_slug']) && !empty($core['secret']);

            $unsent = (class_exists('AdSpirit_Lead_Store') && $connected)
                ? AdSpirit_Lead_Store::count_unsent() : 0;

            $capi = get_option('adspirit_connector_capi_meta', array());
            $ga4  = get_option('adspirit_connector_ga4', array());
            $measuring = (!empty($core['pixel_enabled']) && $core['pixel_enabled'] === '1')
                || !empty($capi['pixel_id']) || !empty($ga4['measurement_id']);

            $wh = get_option('adspirit_connector_webhook_out', array());
            $cio = get_option('adspirit_connector_customerio', array());
            $mc = get_option('adspirit_connector_mailchimp', array());
            $integrating = !empty($wh['urls']) || !empty($wh['url'])
                || !empty($cio['enabled']) || !empty($mc['enabled']);

            $safe = class_exists('AdSpirit_Safe_Bootstrap') && AdSpirit_Safe_Bootstrap::is_safe_mode();
            $auth_err = (bool) get_option('adspirit_connector_crm_auth_error');

            return array(
                'inicio'   => array('state' => $connected ? 'ok' : 'warn', 'hint' => $connected ? 'Conectado ao AdSpirit' : 'Falta conectar ao AdSpirit'),
                'leads'    => array('state' => !$connected ? 'off' : ($unsent > 0 ? 'warn' : 'ok'), 'hint' => $unsent > 0 ? $unsent . ' lead(s) aguardando entrega' : 'Leads fluindo normalmente'),
                'tracking' => array('state' => $measuring ? 'ok' : 'off', 'hint' => $measuring ? 'Medição ativa' : 'Nenhuma medição configurada'),
                'avancado' => array('state' => ($safe || $auth_err) ? 'warn' : ($integrating ? 'ok' : 'off'), 'hint' => $safe ? 'Modo de segurança ativo' : ($auth_err ? 'Chave rejeitada pelo AdSpirit' : ($integrating ? 'Integrações ativas' : 'Tudo quieto'))),
            );
        }, array(), 'menu_group_health');
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
        $hidden = self::hidden_tabs();
        $orphans = array();
        foreach ($tabs as $slug => $label) {
            if (empty($seen[$slug]) && !in_array($slug, $hidden, true)) $orphans[$slug] = $label;
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
        // Mask no PRÓPRIO div do ícone (36×34): centrado pelos dois eixos
        // sem depender do ::before — o core aplica padding:7px 0 no ::before
        // dos ícones de menu e deslocava a caixa (bug das v2.22-2.24).
        // @supports: navegador sem mask cai no background-image do WP
        // (glifo escuro, mas presente) em vez de um quadrado pintado.
        echo '<style id="adspirit-menu-icon">'
            . '@supports ((-webkit-mask-image: url("")) or (mask-image: url(""))) {'
            . $sel . '{background-image:none!important;background-color:currentColor;'
            . '-webkit-mask:url(\'' . $mask . '\') no-repeat center/22px auto;'
            . 'mask:url(\'' . $mask . '\') no-repeat center/22px auto;}'
            . $sel . '::before{display:none!important;}'
            . '}'
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

        // Submenus = um por tab pra deep linking (telas de detalhe de form
        // ficam fora — a porta delas é o hub Formulários)
        $hidden = self::hidden_tabs();
        foreach (self::tabs() as $slug => $label) {
            if (in_array($slug, $hidden, true)) continue;
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
        $version = defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : '2.30.0';
        wp_enqueue_style(
            'adspirit-connector-admin',
            ADSPIRIT_CONNECTOR_URL . 'assets/admin.css',
            array(),
            $version
        );
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

            <?php // Nível 1 — grupos por tarefa, com ponto de saúde (a nav
                  // responde "tá tudo ok?" antes de qualquer clique). ?>
            <?php $health = self::group_health(); ?>
            <nav class="as-groups">
                <?php foreach ($grouped as $gkey => $g):
                    $first_slug = array_key_first($g['tabs']);
                    $h = isset($health[$gkey]) ? $health[$gkey] : null; ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=' . $first_slug)); ?>"
                       class="<?php echo $current_group === $gkey ? 'active' : ''; ?>"
                       <?php if ($h && !empty($h['hint'])): ?>title="<?php echo esc_attr($h['hint']); ?>"<?php endif; ?>>
                        <?php if ($h && $h['state'] !== 'off'): ?>
                            <span class="as-dot <?php echo esc_attr($h['state']); ?>" aria-hidden="true"></span>
                        <?php endif; ?>
                        <?php echo esc_html($g['label']); ?>
                    </a>
                <?php endforeach; ?>
            </nav>

            <?php // Nível 2 — sub-tabs do grupo atual (só se o grupo tem mais de 1) ?>
            <?php $sub = isset($grouped[$current_group]['tabs']) ? $grouped[$current_group]['tabs'] : array(); ?>
            <?php $meta = self::tab_meta(); ?>
            <?php if (count($sub) > 1): ?>
            <nav class="as-tabs as-subtabs">
                <?php foreach ($sub as $slug => $label): ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . self::PAGE_SLUG . '&tab=' . $slug)); ?>"
                       class="<?php echo $current_tab === $slug ? 'active' : ''; ?>"
                       <?php if (!empty($meta[$slug]['desc'])): ?>title="<?php echo esc_attr($meta[$slug]['desc']); ?>"<?php endif; ?>>
                        <?php echo esc_html($label); ?>
                    </a>
                <?php endforeach; ?>
            </nav>
            <?php endif; ?>
            <?php // Legenda da tab ativa — a pessoa sempre sabe onde está e pra quê serve. ?>
            <?php if (!empty($meta[$current_tab]['desc'])): ?>
                <p class="as-tab-desc"><?php echo esc_html($meta[$current_tab]['desc']); ?></p>
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
