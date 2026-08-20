<?php
/**
 * AdSpirit Connector — página de obrigado: sugerir e CONFIRMAR na conexão.
 *
 * Regra do Pedro (2026-08-20): "essa confirmação da página de obrigado deve
 * ser manual e deve ocorrer na hora que o dev conecta o plugin ao site. o
 * sistema só sugere e o dev confirma."
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

    /** Página confirmada (array com id/url) ou null. */
    public static function confirmed() {
        $v = get_option(self::OPTION, null);
        return is_array($v) && !empty($v['url']) ? $v : null;
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
        if (self::confirmed()) return;
        if (get_option(self::NOTICE_DISMISSED)) return;
        if (!class_exists('AdSpirit_Connect') || !AdSpirit_Connect::is_connected()) return;
        $screen = function_exists('get_current_screen') ? get_current_screen() : null;
        if (!$screen || strpos((string) $screen->id, AdSpirit_Menu::PAGE_SLUG) === false) return;

        $cands = self::candidates();
        ?>
        <div class="notice notice-info" style="padding:12px 14px;">
            <p style="margin:0 0 6px; font-weight:600;">Qual é a sua página de obrigado?</p>
            <p style="margin:0 0 10px; color:#50575e;">
                A visita a ela conta como <strong>conversão</strong> no AdSpirit (não como lead —
                lead é quando temos os dados da pessoa). Confirme abaixo; nada é enviado sem você escolher.
            </p>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:flex; flex-wrap:wrap; gap:8px; align-items:center;">
                <input type="hidden" name="action" value="adspirit_save_thankyou">
                <?php wp_nonce_field('adspirit_thankyou', '_adspirit_ty_nonce'); ?>
                <?php if (!empty($cands)) : ?>
                    <select name="page_id" style="max-width:420px;">
                        <?php foreach ($cands as $c) : ?>
                            <option value="<?php echo (int) $c['id']; ?>">
                                <?php echo esc_html($c['title']); ?> — <?php echo esc_html($c['path']); ?>
                            </option>
                        <?php endforeach; ?>
                        <option value="0">Outra página (informar endereço)</option>
                    </select>
                    <input type="text" name="custom_path" placeholder="/obrigado" style="width:180px;">
                <?php else : ?>
                    <input type="text" name="custom_path" placeholder="/obrigado" style="width:220px;" required>
                    <span style="color:#646970;">Não encontrei nenhuma candidata — informe o endereço.</span>
                <?php endif; ?>
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

        $page_id = isset($_POST['page_id']) ? (int) $_POST['page_id'] : 0;
        $custom  = isset($_POST['custom_path']) ? sanitize_text_field((string) $_POST['custom_path']) : '';

        $url = '';
        $path = '';
        if ($page_id > 0) {
            $url = (string) get_permalink($page_id);
            $path = (string) wp_make_link_relative($url);
        } elseif ($custom !== '') {
            $path = '/' . ltrim($custom, '/');
            $url = home_url($path);
        }
        if ($path === '') {
            wp_safe_redirect(add_query_arg('adspirit_ty', 'vazio', $back));
            exit;
        }

        update_option(self::OPTION, array('id' => $page_id, 'url' => $url, 'path' => $path), false);
        // Manda pro CRM virar conversão medida. Fail-soft: se o CRM estiver
        // fora ou for antigo, a escolha fica salva aqui e sobe na próxima.
        $sent = self::push_to_crm($path);
        wp_safe_redirect(add_query_arg('adspirit_ty', $sent ? 'ok' : 'local', $back));
        exit;
    }

    /** Envia a página confirmada pro CRM (vira conversion_definition). */
    public static function push_to_crm($path) {
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
            )),
        ));
        return !is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200;
    }
}
