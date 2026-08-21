<?php
/**
 * AdSpirit Connector — páginas de conversão: sugerir e CONFIRMAR na conexão.
 *
 * Regra do Pedro (2026-08-20): "essa confirmação da página de obrigado deve
 * ser manual e deve ocorrer na hora que o dev conecta o plugin ao site. o
 * sistema só sugere e o dev confirma." E (08-20, revisão): são VÁRIAS —
 * cada funil pode ter a sua, e conversões diferentes significam coisas
 * diferentes ("virou inscrito" ≠ "virou lead comercial"). Chamamos de
 * PÁGINA DE CONVERSÃO, não "de obrigado": nem toda conversão é um
 * agradecimento.
 *
 * Por que aqui e não no CRM: o WordPress SABE quais páginas existem. Detectar
 * pelo conteúdo do site (slug, título, presença de shortcode de obrigado) é
 * mais preciso do que inferir do histórico de visitas — e funciona no dia
 * zero, antes de existir tráfego.
 *
 * O que a página de obrigado significa pro AdSpirit: a visita a ela é uma
 * CONVERSÃO medida (nunca um lead — lead exige os dados da pessoa). Ela
 * aparece no funil como camada sobreposta, atrás do toggle "conversões
 * avançadas", sem mexer na régua clique → lead.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_ThankYou_Setup {
    // Lista de páginas de conversão confirmadas: [{id, url, path, name}].
    // (Antes guardava UMA — migrado on-read pra não perder a escolha de quem
    // já confirmou na 2.31.)
    const OPTION = 'adspirit_thankyou_page';
    const NOTICE_DISMISSED = 'adspirit_thankyou_dismissed';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_post_adspirit_save_thankyou',
            AdSpirit_Safe_Hook::action(array($this, 'handle_save'), 'thankyou_save'));
        // Aviso logo depois de conectar — é o momento em que o dev está com
        // o site na cabeça (regra: confirmar na hora da conexão).
        add_action('admin_notices',
            AdSpirit_Safe_Hook::action(array($this, 'maybe_notice'), 'thankyou_notice'));
    }

    /** Páginas de conversão confirmadas (lista, possivelmente vazia). */
    public static function confirmed_all() {
        $v = get_option(self::OPTION, null);
        if (!is_array($v)) return array();
        // Formato antigo (uma página só) → normaliza pra lista.
        if (isset($v['url'])) return array($v);
        $out = array();
        foreach ($v as $item) {
            if (is_array($item) && !empty($item['path'])) $out[] = $item;
        }
        return $out;
    }

    /** Primeira confirmada (compat: a Visão geral mostra a principal). */
    public static function confirmed() {
        $all = self::confirmed_all();
        return $all ? $all[0] : null;
    }

    /**
     * Candidatas a página de obrigado NO SITE (não no histórico): páginas
     * publicadas cujo slug ou título tem cara de agradecimento. Ordena por
     * qualidade do sinal — slug exato primeiro.
     */
    public static function candidates($limit = 6) {
        $terms = array('obrigado', 'obrigada', 'thank-you', 'thankyou', 'thanks', 'sucesso', 'success', 'confirmacao', 'confirmado');
        $pages = get_posts(array(
            'post_type'      => 'page',
            'post_status'    => 'publish',
            'posts_per_page' => 200,
            'orderby'        => 'date',
            'order'          => 'DESC',
            'fields'         => 'ids',
        ));
        $out = array();
        foreach ($pages as $pid) {
            $slug  = (string) get_post_field('post_name', $pid);
            $title = (string) get_the_title($pid);
            $hay   = strtolower($slug . ' ' . remove_accents($title));
            $score = 0;
            foreach ($terms as $t) {
                if ($slug === $t) { $score = 100; break; }
                if (strpos($slug, $t) !== false) { $score = max($score, 60); }
                elseif (strpos($hay, $t) !== false) { $score = max($score, 30); }
            }
            // Sinal forte: a página usa o shortcode de obrigado do plugin.
            $content = (string) get_post_field('post_content', $pid);
            if (strpos($content, '[adspirit_thank_you') !== false) $score = 100;
            if ($score === 0) continue;
            $out[] = array(
                'id'    => (int) $pid,
                'title' => $title,
                'url'   => (string) get_permalink($pid),
                'path'  => (string) wp_make_link_relative(get_permalink($pid)),
                'score' => $score,
            );
        }
        usort($out, function ($a, $b) { return $b['score'] - $a['score']; });
        return array_slice($out, 0, $limit);
    }

    /** Aviso pós-conexão: sugere, mas quem decide é o dev. */
    public function maybe_notice() {
        if (!current_user_can('manage_options')) return;
        if (self::confirmed_all()) return;
        if (get_option(self::NOTICE_DISMISSED)) return;
        if (!class_exists('AdSpirit_Connect') || !AdSpirit_Connect::is_connected()) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos((string) $screen->id, AdSpirit_Menu::PAGE_SLUG) === false) return;

        $cands = self::candidates();
        ?>
        <div class="notice notice-info" style="padding:12px 14px;">
            <p style="margin:0 0 6px; font-weight:600;">Quais são as suas páginas de conversão?</p>
            <p style="margin:0 0 10px; color:#50575e;">
                A visita a elas conta como <strong>conversão</strong> no AdSpirit (não como lead —
                lead é quando temos os dados da pessoa). <strong>Marque quantas quiser</strong>:
                funis diferentes costumam ter páginas diferentes, e cada uma pode significar uma
                conversão distinta (inscrito, orçamento, agendamento…). Nada é enviado sem você escolher.
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                <input type="hidden" name="action" value="adspirit_save_thankyou">
                <?php wp_nonce_field('adspirit_thankyou', '_adspirit_ty_nonce'); ?>
                <?php if (!empty($cands)) : ?>
                    <div style="display:flex; flex-direction:column; gap:5px; width:100%; margin-bottom:6px;">
                        <?php foreach ($cands as $i => $c) : ?>
                            <label style="display:flex; align-items:center; gap:8px;">
                                <input type="checkbox" name="page_ids[]" value="<?php echo (int) $c['id']; ?>" <?php checked($i === 0); ?>>
                                <span><strong><?php echo esc_html($c['title']); ?></strong>
                                    <code style="font-size:11px; opacity:.75;"><?php echo esc_html($c['path']); ?></code></span>
                            </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                <input type="text" name="custom_path" placeholder="/outra-pagina (opcional)" style="width:220px;">
                <button type="submit" class="button button-primary">Confirmar</button>
                <button type="submit" name="skip" value="1" class="button-link" style="color:#646970;">Agora não</button>
            </form>
        </div>
        <?php
    }

    public function handle_save($post = null) {
        if (!current_user_can('manage_options')) wp_die('Sem permissão.');
        check_admin_referer('adspirit_thankyou', '_adspirit_ty_nonce');

        $back = add_query_arg(
            array('page' => AdSpirit_Menu::PAGE_SLUG, 'tab' => 'overview'),
            admin_url('admin.php')
        );

        if (!empty($_POST['skip'])) {
            update_option(self::NOTICE_DISMISSED, 1, false);
            wp_safe_redirect($back);
            exit;
        }

        // VÁRIAS páginas (revisão 08-20): funis diferentes têm páginas
        // diferentes, e cada uma pode significar uma conversão distinta.
        $ids    = isset($_POST['page_ids']) && is_array($_POST['page_ids']) ? array_map('intval', $_POST['page_ids']) : array();
        $custom = isset($_POST['custom_path']) ? sanitize_text_field((string) $_POST['custom_path']) : '';

        $chosen = array();
        foreach ($ids as $pid) {
            if ($pid <= 0) continue;
            $url = (string) get_permalink($pid);
            if ($url === '') continue;
            $chosen[] = array(
                'id'   => $pid,
                'url'  => $url,
                'path' => (string) wp_make_link_relative($url),
                'name' => (string) get_the_title($pid),
            );
        }
        if ($custom !== '') {
            $path = '/' . ltrim($custom, '/');
            $chosen[] = array('id' => 0, 'url' => home_url($path), 'path' => $path, 'name' => 'Conversão');
        }
        if (empty($chosen)) {
            wp_safe_redirect(add_query_arg('adspirit_ty', 'vazio', $back));
            exit;
        }

        update_option(self::OPTION, $chosen, false);
        // Manda cada uma pro CRM virar conversão medida. Fail-soft: se o CRM
        // estiver fora ou for antigo, ficam salvas aqui e sobem na próxima.
        $sent = 0;
        foreach ($chosen as $c) {
            if (self::push_to_crm($c['path'], $c['name'])) $sent++;
        }
        wp_safe_redirect(add_query_arg('adspirit_ty', $sent === count($chosen) ? 'ok' : ($sent > 0 ? 'parcial' : 'local'), $back));
        exit;
    }

    /** Envia UMA página confirmada pro CRM (vira conversion_definition). */
    public static function push_to_crm($path, $name = '') {
        if (!class_exists('AdSpirit_Settings')) return false;
        $core = AdSpirit_Settings::get_core();
        if (empty($core['endpoint_url']) || empty($core['brand_slug']) || empty($core['secret'])) return false;
        $resp = wp_remote_post(rtrim((string) $core['endpoint_url'], '/') . '/api/wp/conversions', array(
            'timeout' => 8,
            'headers' => array(
                'Content-Type' => 'application/json; charset=utf-8',
                'x-cf7-secret' => (string) $core['secret'],
                'User-Agent'   => 'AdSpirit-Connector/' . (defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : ''),
            ),
            'body' => wp_json_encode(array(
                'brand_slug' => (string) $core['brand_slug'],
                'kind'       => 'thank_you_page',
                'url_path'   => (string) $path,
                'name'       => $name !== '' ? (string) $name : 'Conversão',
            )),
        ));
        return !is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200;
    }
}
