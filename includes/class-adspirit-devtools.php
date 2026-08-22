<?php
/**
 * AdSpirit Connector — Ferramentas de desenvolvimento.
 *
 * Absorve utilitários que o time instalava avulso durante a construção do
 * site (Duplicate Page, Post Type Switcher, Download Plugin). Vantagem
 * dupla: o dev não instala mais nada, e no handover essas ferramentas saem
 * junto com o connector — o cliente fica com o WordPress limpo.
 *
 * Tudo aqui exige quem pode editar/instalar, e some da interface para quem
 * não tem essa permissão.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_DevTools {

    const OPTION_ATIVO = 'adspirit_devtools_ativo';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    public static function ativo() {
        // Segue o domínio, igual ao resto do plugin: são ferramentas de quem
        // CONSTRÓI o site. No painel do cliente, "Duplicar", "Baixar zip" e o
        // seletor de tipo de conteúdo são ações que ele não deveria precisar
        // entender — e uma delas move conteúdo de tipo ao salvar.
        //
        // Com isso o pacote passa a ser um só: o que separa estúdio de cliente
        // é o endereço, nunca qual zip alguém instalou.
        $padrao = '1';
        if (class_exists('AdSpirit_Ambiente') && !AdSpirit_Ambiente::e_estudio()) {
            $padrao = '0';
        }
        return (bool) apply_filters('adspirit_devtools_ativo', get_option(self::OPTION_ATIVO, $padrao) === '1');
    }

    private function __construct() {
        if (!self::ativo()) return;

        // 1) Duplicar página/post
        add_filter('post_row_actions', AdSpirit_Safe_Hook::filter(array($this, 'acao_duplicar'), 'devtools'), 10, 2);
        add_filter('page_row_actions', AdSpirit_Safe_Hook::filter(array($this, 'acao_duplicar'), 'devtools'), 10, 2);
        add_action('admin_post_adspirit_duplicar', AdSpirit_Safe_Hook::action(array($this, 'duplicar'), 'devtools'));

        // 2) Trocar o tipo de um conteúdo (página <-> case <-> post...)
        add_action('post_submitbox_misc_actions', AdSpirit_Safe_Hook::action(array($this, 'campo_tipo'), 'devtools'));
        add_action('save_post', AdSpirit_Safe_Hook::action(array($this, 'salvar_tipo'), 'devtools'), 10, 2);

        // 3) Baixar um plugin instalado como zip
        add_filter('plugin_action_links', AdSpirit_Safe_Hook::filter(array($this, 'acao_baixar'), 'devtools'), 10, 2);
        add_action('admin_post_adspirit_baixar_plugin', AdSpirit_Safe_Hook::action(array($this, 'baixar_plugin'), 'devtools'));
    }

    /* ---------------- 1. Duplicar ---------------- */

    public function acao_duplicar($acoes, $post) {
        if (!current_user_can('edit_posts')) return $acoes;
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=adspirit_duplicar&post=' . (int) $post->ID),
            'adspirit_duplicar_' . $post->ID
        );
        $acoes['adspirit_duplicar'] = '<a href="' . esc_url($url) . '">Duplicar</a>';
        return $acoes;
    }

    public function duplicar() {
        $id = isset($_GET['post']) ? (int) $_GET['post'] : 0;
        if (!$id || !current_user_can('edit_posts')) wp_die('sem permissão');
        check_admin_referer('adspirit_duplicar_' . $id);

        $original = get_post($id);
        if (!$original) wp_die('conteúdo não encontrado');

        $novo_id = wp_insert_post(array(
            'post_title'   => $original->post_title . ' (cópia)',
            'post_content' => $original->post_content,
            'post_excerpt' => $original->post_excerpt,
            'post_status'  => 'draft',
            'post_type'    => $original->post_type,
            'post_parent'  => $original->post_parent,
            'menu_order'   => $original->menu_order,
            'post_author'  => get_current_user_id(),
        ));
        if (is_wp_error($novo_id)) wp_die('falha ao duplicar');

        // taxonomias
        foreach (get_object_taxonomies($original->post_type) as $tax) {
            $termos = wp_get_object_terms($id, $tax, array('fields' => 'slugs'));
            if (!is_wp_error($termos) && $termos) wp_set_object_terms($novo_id, $termos, $tax);
        }
        // metas (inclusive as do builder — é o que faz a cópia valer)
        global $wpdb;
        $metas = $wpdb->get_results($wpdb->prepare(
            "SELECT meta_key, meta_value FROM {$wpdb->postmeta} WHERE post_id = %d", $id
        ));
        foreach ($metas as $meta) {
            if (in_array($meta->meta_key, array('_edit_lock', '_edit_last'), true)) continue;
            add_post_meta($novo_id, $meta->meta_key, wp_slash(maybe_unserialize($meta->meta_value)));
        }

        wp_safe_redirect(admin_url('post.php?action=edit&post=' . $novo_id));
        exit;
    }

    /* ---------------- 2. Trocar o tipo ---------------- */

    public function campo_tipo($post) {
        if (!current_user_can('edit_others_posts')) return;
        $tipos = get_post_types(array('show_ui' => true), 'objects');
        $ignorar = array('attachment', 'revision', 'nav_menu_item');
        echo '<div class="misc-pub-section adspirit-tipo">';
        echo '<label for="adspirit_post_type"><strong>Tipo de conteúdo</strong></label> ';
        wp_nonce_field('adspirit_tipo_' . $post->ID, 'adspirit_tipo_nonce');
        echo '<select name="adspirit_post_type" id="adspirit_post_type" style="max-width:160px">';
        foreach ($tipos as $slug => $tipo) {
            if (in_array($slug, $ignorar, true)) continue;
            echo '<option value="' . esc_attr($slug) . '" ' . selected($slug, $post->post_type, false) . '>'
                . esc_html($tipo->labels->singular_name) . '</option>';
        }
        echo '</select>';
        echo '<p class="description" style="margin:6px 0 0">Trocar aqui move o conteúdo de tipo ao salvar.</p>';
        echo '</div>';
    }

    public function salvar_tipo($post_id, $post) {
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
        if (empty($_POST['adspirit_post_type']) || empty($_POST['adspirit_tipo_nonce'])) return;
        if (!wp_verify_nonce($_POST['adspirit_tipo_nonce'], 'adspirit_tipo_' . $post_id)) return;
        if (!current_user_can('edit_others_posts')) return;

        $novo = sanitize_key($_POST['adspirit_post_type']);
        if (!$novo || $novo === $post->post_type) return;
        if (!post_type_exists($novo)) return;
        set_post_type($post_id, $novo);
    }

    /* ---------------- 3. Baixar plugin ---------------- */

    public function acao_baixar($acoes, $arquivo) {
        if (!current_user_can('install_plugins')) return $acoes;
        $url = wp_nonce_url(
            admin_url('admin-post.php?action=adspirit_baixar_plugin&plugin=' . urlencode($arquivo)),
            'adspirit_baixar_' . $arquivo
        );
        $acoes['adspirit_baixar'] = '<a href="' . esc_url($url) . '">Baixar zip</a>';
        return $acoes;
    }

    public function baixar_plugin() {
        $arquivo = isset($_GET['plugin']) ? wp_unslash($_GET['plugin']) : '';
        if (!$arquivo || !current_user_can('install_plugins')) wp_die('sem permissão');
        check_admin_referer('adspirit_baixar_' . $arquivo);

        $pasta = WP_PLUGIN_DIR . '/' . dirname($arquivo);
        // plugin de arquivo único não tem pasta própria
        $alvo = (dirname($arquivo) === '.') ? WP_PLUGIN_DIR . '/' . basename($arquivo) : $pasta;
        if (!file_exists($alvo)) wp_die('plugin não encontrado');
        if (!class_exists('ZipArchive')) wp_die('este servidor não tem suporte a zip');

        $nome = sanitize_file_name(dirname($arquivo) === '.' ? basename($arquivo, '.php') : dirname($arquivo));
        $destino = wp_tempnam($nome . '.zip');
        $zip = new ZipArchive();
        if ($zip->open($destino, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) wp_die('falha ao criar o zip');

        if (is_dir($alvo)) {
            $itens = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($alvo, FilesystemIterator::SKIP_DOTS));
            foreach ($itens as $item) {
                if (!$item->isFile()) continue;
                $relativo = $nome . '/' . ltrim(str_replace($alvo, '', $item->getPathname()), '/\\');
                $zip->addFile($item->getPathname(), $relativo);
            }
        } else {
            $zip->addFile($alvo, basename($alvo));
        }
        $zip->close();

        nocache_headers();
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $nome . '.zip"');
        header('Content-Length: ' . filesize($destino));
        readfile($destino);
        @unlink($destino);
        exit;
    }
}
