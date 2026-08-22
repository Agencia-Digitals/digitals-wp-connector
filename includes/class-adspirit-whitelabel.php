<?php
/**
 * AdSpirit Connector — Máscara do painel (white label).
 *
 * Faz o wp-admin parecer com o AdSpirit e tira o ruído que ninguém usa:
 * avisos de plugins de terceiros, rodapé do WordPress, itens de menu que
 * não fazem parte do trabalho. Substitui o White Label CMS.
 *
 * Dois níveis, porque os dois ambientes têm gente diferente na frente:
 *   - 'leve'  (padrão no estúdio): identidade visual + menos ruído; o dev
 *             continua com acesso a tudo.
 *   - 'forte' (padrão no site do cliente): além do visual, esconde o que é
 *             infraestrutura e deixa só o conteúdo que ele edita.
 *
 * Nada aqui remove permissão — é camada de interface. Quem precisa do menu
 * completo acrescenta ?adspirit_full=1 na URL do admin.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_WhiteLabel {

    const OPTION_NIVEL = 'adspirit_whitelabel_nivel';
    const OPTION_MARCA = 'adspirit_whitelabel_marca';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    /** leve | forte | off */
    public static function nivel() {
        // Quem decide é o DOMÍNIO, igual ao resto do plugin.
        //
        // Antes isso vinha do perfil do pacote (ADSPIRIT_PERFIL), e aí bastava
        // alguém instalar o zip de cliente num endereço nosso pra esconder
        // Ferramentas, Aparência e Comentários do painel — e engolir todos os
        // avisos de outros plugins — no site da própria agência. O endereço não
        // se confunde: é o mesmo critério que libera as ferramentas do Studio.
        $padrao = 'forte';
        if (class_exists('AdSpirit_Ambiente')) {
            $padrao = AdSpirit_Ambiente::e_estudio() ? 'leve' : 'forte';
        } elseif (defined('ADSPIRIT_PERFIL') && ADSPIRIT_PERFIL === 'estudio') {
            $padrao = 'leve';
        }
        $nivel = get_option(self::OPTION_NIVEL, $padrao);
        return (string) apply_filters('adspirit_whitelabel_nivel', $nivel);
    }

    private static function nome_marca() {
        return (string) apply_filters('adspirit_whitelabel_marca', get_option(self::OPTION_MARCA, 'AdSpirit'));
    }

    /** Escape hatch: ?adspirit_full=1 devolve o painel cru na sessão. */
    private static function modo_cru() {
        if (isset($_GET['adspirit_full'])) {
            set_transient('adspirit_wl_cru_' . get_current_user_id(), 1, 30 * MINUTE_IN_SECONDS);
        }
        return (bool) get_transient('adspirit_wl_cru_' . get_current_user_id());
    }

    private function __construct() {
        if (!is_admin() || self::nivel() === 'off') return;

        add_action('admin_enqueue_scripts', AdSpirit_Safe_Hook::action(array($this, 'estilo'), 'whitelabel'), 99);
        add_filter('admin_footer_text', AdSpirit_Safe_Hook::filter(array($this, 'rodape'), 'whitelabel'));
        add_filter('update_footer', AdSpirit_Safe_Hook::filter(array($this, 'versao_rodape'), 'whitelabel'), 99);
        add_action('admin_bar_menu', AdSpirit_Safe_Hook::action(array($this, 'barra'), 'whitelabel'), 99);
        add_action('admin_head', AdSpirit_Safe_Hook::action(array($this, 'limpar_avisos'), 'whitelabel'), 1);

        if (self::nivel() === 'forte') {
            add_action('admin_menu', AdSpirit_Safe_Hook::action(array($this, 'enxugar_menu'), 'whitelabel'), 999);
            add_action('wp_dashboard_setup', AdSpirit_Safe_Hook::action(array($this, 'enxugar_painel'), 'whitelabel'), 99);
        }
    }

    /** Identidade visual do AdSpirit aplicada ao painel inteiro. */
    public function estilo() {
        $accent = '#00B7B7';
        $css = "
        :root{--as-accent:{$accent};--as-ink:#0F1419;--as-line:#ECF0F3;}
        #adminmenu, #adminmenuback, #adminmenuwrap{background:#0F1419;}
        #adminmenu a{color:#C7D0D8;}
        #adminmenu li.menu-top:hover>a.menu-top, #adminmenu li.opensub>a.menu-top{background:#182028;color:#fff;}
        #adminmenu li.current>a.current, #adminmenu .wp-has-current-submenu>a.wp-has-current-submenu{
            background:{$accent};color:#08201F;font-weight:600;}
        #adminmenu .wp-submenu{background:#151C23;}
        #adminmenu .wp-submenu a{color:#A9B4BE;}
        #adminmenu .wp-submenu a:hover{color:{$accent};}
        #wpadminbar{background:#0F1419;}
        .wrap h1, .wrap h2{font-weight:600;letter-spacing:-.2px;}
        .wp-core-ui .button-primary{background:{$accent};border-color:{$accent};color:#08201F;text-shadow:none;box-shadow:none;font-weight:600;}
        .wp-core-ui .button-primary:hover{background:#009999;border-color:#009999;color:#fff;}
        a{color:#0E8F8F;}
        #wpfooter{color:#8A95A0;}
        ";
        wp_register_style('adspirit-whitelabel', false, array(), null);
        wp_enqueue_style('adspirit-whitelabel');
        wp_add_inline_style('adspirit-whitelabel', $css);
    }

    public function rodape($texto) {
        $marca = esc_html(self::nome_marca());
        return 'Painel operado por <strong>' . $marca . '</strong>.';
    }

    public function versao_rodape($texto) {
        // versão do WordPress não é informação útil pra quem usa o painel
        return '';
    }

    public function barra($barra) {
        $barra->remove_node('wp-logo');
        $barra->remove_node('comments');
        if (self::nivel() === 'forte') {
            $barra->remove_node('new-content');
            $barra->remove_node('updates');
        }
    }

    /**
     * Tira os avisos que plugins de terceiros pendurados no topo — eles
     * atrapalham quem trabalha e assustam quem não é técnico. Os avisos do
     * próprio connector continuam aparecendo.
     */
    public function limpar_avisos() {
        if (self::modo_cru()) return;
        $tela = function_exists('get_current_screen') ? get_current_screen() : null;
        $id = $tela ? $tela->id : '';
        // na tela de plugins e atualizações os avisos importam
        if (strpos($id, 'plugins') !== false || strpos($id, 'update') !== false) return;
        remove_all_actions('admin_notices');
        remove_all_actions('all_admin_notices');
        add_action('admin_notices', function () {
            if (class_exists('AdSpirit_Status') && method_exists('AdSpirit_Status', 'render_notices')) {
                AdSpirit_Status::render_notices();
            }
        });
    }

    /** No site do cliente: só o que ele edita fica à vista. */
    public function enxugar_menu() {
        if (self::modo_cru() || current_user_can('manage_network')) return;
        $esconder = apply_filters('adspirit_whitelabel_menus_ocultos', array(
            'tools.php', 'edit-comments.php', 'themes.php',
        ));
        foreach ($esconder as $slug) { remove_menu_page($slug); }
        // submenus de infraestrutura que confundem sem acrescentar
        remove_submenu_page('options-general.php', 'options-writing.php');
        remove_submenu_page('options-general.php', 'options-discussion.php');
    }

    public function enxugar_painel() {
        global $wp_meta_boxes;
        $manter = array('adspirit_dashboard_widget');
        foreach (array('normal', 'side', 'column3') as $contexto) {
            if (empty($wp_meta_boxes['dashboard'][$contexto]['core'])) continue;
            foreach (array_keys($wp_meta_boxes['dashboard'][$contexto]['core']) as $id) {
                if (in_array($id, $manter, true)) continue;
                unset($wp_meta_boxes['dashboard'][$contexto]['core'][$id]);
            }
        }
    }
}
