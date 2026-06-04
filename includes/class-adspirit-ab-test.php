<?php
/**
 * AdSpirit Connector — A/B Test pra forms [adspirit_form].
 *
 * Tracking simples mas confiável de variantes (A/B/C…) num mesmo form_id.
 *
 *   [adspirit_form id="lead-comercial" variant="A"]
 *   [adspirit_form id="lead-comercial" variant="B"]
 *
 * Quando o user vê uma variante, o plugin:
 *   1. Grava cookie `adspirit_ab_variant_<form_id>` = letter (90d, sticky)
 *      pra mesma pessoa nunca ver duas variantes do mesmo form
 *   2. Incrementa view counter em options[form_id][views][variant]
 *
 * Quando o user submete, o handler do form:
 *   3. Inclui `_adspirit_ab_variant` no payload pro CRM (via filter)
 *   4. Incrementa conversion counter em options[form_id][conversions][variant]
 *
 * Stats: conversion_rate = conversions / views por variante. Winner =
 * variante com maior taxa (mínimo 30 views por variante pra evitar ruído).
 *
 * UI: tab dedicada com card por form, métricas grandes, badge no winner,
 * botão "Reset stats" por form.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Ab_Test {
    const OPTION_KEY  = 'adspirit_connector_ab_tests';
    const COOKIE_NAME = 'adspirit_ab_variant';
    const COOKIE_TTL  = 90 * DAY_IN_SECONDS; // 90 dias
    const MIN_SAMPLE  = 30; // mínimo de views pra declarar winner

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Registra tab no menu — FILTER (não action) pra preservar array em erro
        add_filter(
            'adspirit_connector_tabs',
            AdSpirit_Safe_Hook::filter(array($this, 'register_tab'), 'ab_test_tab_register')
        );
        add_action(
            'adspirit_connector_render_tab_ab-tests',
            AdSpirit_Safe_Hook::action(array($this, 'render_tab'), 'ab_test_tab_render')
        );

        // Captura ?variant=X via shortcode no [adspirit_form] (filtra atts)
        add_filter(
            'shortcode_atts_adspirit_form',
            AdSpirit_Safe_Hook::filter(array($this, 'handle_shortcode_atts'), 'ab_test_shortcode'),
            10, 4
        );

        // Quando o shortcode termina de renderizar, registra view + seta cookie.
        // CRÍTICO: tem que ser ::filter — em erro, retorna $content original.
        // Se fosse ::action, exception devolveria null → todo o site sumiria.
        add_filter(
            'the_content',
            AdSpirit_Safe_Hook::filter(array($this, 'track_view_after_render'), 'ab_test_view'),
            999
        );

        // Quando submit acontece, inclui variant no payload + incrementa conversion
        add_filter(
            'adspirit_form_submit_payload',
            AdSpirit_Safe_Hook::filter(array($this, 'inject_variant_payload'), 'ab_test_payload'),
            10, 2
        );

        // Reset stats handler
        add_action(
            'adspirit_connector_save_ab-tests',
            AdSpirit_Safe_Hook::action(array($this, 'handle_save'), 'ab_test_save')
        );
    }

    /* ===========================================================
       Tab register / render
       =========================================================== */

    public function register_tab($tabs) {
        if (!is_array($tabs)) $tabs = array();
        // Insere logo depois de 'forms'
        $new = array();
        foreach ($tabs as $slug => $label) {
            $new[$slug] = $label;
            if ($slug === 'forms') {
                $new['ab-tests'] = 'A/B Tests';
            }
        }
        if (!isset($new['ab-tests'])) {
            $new['ab-tests'] = 'A/B Tests';
        }
        return $new;
    }

    public function render_tab() {
        $stats = self::get_all();
        ?>
        <h2 class="as-section">
            <span class="as-kicker-inline">Experiments</span>
            A/B Tests dos forms
        </h2>
        <p class="as-section-help">
            Compare variantes do mesmo form e veja qual converte mais. Use
            <code>[adspirit_form id="seu-form" variant="A"]</code> numa página e
            <code>[adspirit_form id="seu-form" variant="B"]</code> em outra (ou via
            split-test do seu CMS). O plugin grava cookie sticky por 90 dias —
            mesma pessoa vê sempre a variante que cair primeiro.
        </p>

        <?php if (empty($stats)): ?>
            <div class="as-notice info">
                <div class="as-notice-kicker">Nenhum experimento ativo</div>
                <p class="as-notice-title">Comece adicionando <code>variant="A"</code> ou <code>variant="B"</code> no shortcode</p>
                <p>
                    Assim que uma página com <code>variant=</code> for renderizada, ela aparece aqui com contagem de views e conversões.
                    Variantes válidas: <code>A</code>, <code>B</code>, <code>C</code>, <code>D</code>.
                </p>
            </div>
        <?php else: ?>
            <?php foreach ($stats as $form_id => $data): ?>
                <?php $this->render_form_card($form_id, $data); ?>
            <?php endforeach; ?>
        <?php endif; ?>

        <?php AdSpirit_Menu::card_open('Como funciona', 'Cookie sticky 90d + filter no submit'); ?>
        <ul style="margin:0; padding-left:18px; color:var(--as-ink-soft); font-size:12.5px; line-height:1.7;">
            <li>Atributo <code>variant</code> aceita <code>A</code>, <code>B</code>, <code>C</code> ou <code>D</code> (case-insensitive).</li>
            <li>Cookie <code>adspirit_ab_variant_&lt;form_id&gt;</code> grava a primeira variante vista — mesma pessoa nunca vê duas.</li>
            <li>Submit inclui <code>_adspirit_ab_variant</code> no payload pro CRM — pra você cruzar com qualidade do lead lá.</li>
            <li>Winner só aparece quando ambas variantes ultrapassam <strong><?php echo self::MIN_SAMPLE; ?> views</strong> — evita ruído.</li>
        </ul>
        <?php AdSpirit_Menu::card_close(); ?>
        <?php
    }

    private function render_form_card($form_id, $data) {
        $views = isset($data['views']) ? (array) $data['views'] : array();
        $conversions = isset($data['conversions']) ? (array) $data['conversions'] : array();
        $variants = array_unique(array_merge(array_keys($views), array_keys($conversions)));
        sort($variants);

        $totals = array();
        $best_rate = 0;
        $best_variant = null;
        $eligible_for_winner = true;
        foreach ($variants as $v) {
            $vw = isset($views[$v]) ? (int) $views[$v] : 0;
            $cv = isset($conversions[$v]) ? (int) $conversions[$v] : 0;
            $rate = $vw > 0 ? ($cv / $vw) : 0;
            $totals[$v] = array('views' => $vw, 'conversions' => $cv, 'rate' => $rate);
            if ($vw < self::MIN_SAMPLE) $eligible_for_winner = false;
            if ($rate > $best_rate) {
                $best_rate = $rate;
                $best_variant = $v;
            }
        }

        $right_html = '';
        if (count($variants) >= 2 && $eligible_for_winner && $best_variant) {
            $right_html = '<span class="as-badge ok">Winner: ' . esc_html($best_variant) . '</span>';
        } elseif (count($variants) >= 2) {
            $right_html = '<span class="as-badge muted">Coletando dados</span>';
        }

        AdSpirit_Menu::card_open(
            'Form: ' . $form_id,
            'Shortcode <code>[adspirit_form id="' . esc_attr($form_id) . '" variant="A|B|C"]</code>',
            $right_html
        );
        ?>
        <div class="as-metric-grid">
            <?php foreach ($variants as $v):
                $t = $totals[$v];
                $rate_pct = $t['rate'] * 100;
                $is_winner = ($v === $best_variant) && $eligible_for_winner && count($variants) >= 2;
                ?>
                <div class="as-metric" style="<?php echo $is_winner ? 'border-color:var(--as-accent); background:var(--as-accent-soft);' : ''; ?>">
                    <div class="label">
                        Variante <?php echo esc_html($v); ?>
                        <?php if ($is_winner): ?>
                            <span class="as-badge ok" style="margin-left:6px;">Winner</span>
                        <?php endif; ?>
                    </div>
                    <div class="value"><?php echo number_format_i18n($rate_pct, 2); ?>%</div>
                    <div class="sub">
                        <?php echo number_format_i18n($t['conversions']); ?> conv /
                        <?php echo number_format_i18n($t['views']); ?> views
                        <?php if ($t['views'] < self::MIN_SAMPLE): ?>
                            · <span style="color:var(--as-warning);">faltam <?php echo (self::MIN_SAMPLE - $t['views']); ?> pra estatística</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:8px;">
            <input type="hidden" name="action" value="adspirit_save">
            <input type="hidden" name="adspirit_tab" value="ab-tests">
            <input type="hidden" name="ab_action" value="reset">
            <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">
            <?php wp_nonce_field('adspirit_ab-tests_save', '_adspirit_nonce'); ?>
            <button type="submit" class="button button-danger"
                    onclick="return confirm('Zerar stats do form <?php echo esc_js($form_id); ?>? Não dá pra desfazer.');">
                Reset stats
            </button>
        </form>
        <?php
        AdSpirit_Menu::card_close();
    }

    /* ===========================================================
       Shortcode integration
       =========================================================== */

    /**
     * Filtro `shortcode_atts_adspirit_form`. Adiciona suporte a `variant`
     * (caso o shortcode original não declare). Também normaliza pra letter
     * maiúscula e valida.
     *
     * @param array  $out     Atributos sanitizados.
     * @param array  $pairs   Defaults declarados em shortcode_atts.
     * @param array  $atts    Atributos brutos passados.
     * @param string $name    Nome do shortcode.
     * @return array
     */
    public function handle_shortcode_atts($out, $pairs, $atts, $name = '') {
        if (!is_array($out)) return $out;
        $raw = isset($atts['variant']) ? (string) $atts['variant'] : '';
        $variant = self::sanitize_variant($raw);
        $out['variant'] = $variant; // '' se inválido / não setado

        // Marca pra `track_view_after_render` registrar essa página
        if ($variant !== '' && isset($out['id'])) {
            self::$pending_views[$out['id']] = $variant;
        }
        return $out;
    }

    /**
     * Sticky cookie: se já existe pra esse form, força essa variante e
     * ignora a do shortcode. Caso contrário, seta o cookie agora.
     *
     * Chamado durante render via the_content (depois do shortcode resolver),
     * porque é o único momento seguro pra setcookie + a view ser "real".
     */
    private static $pending_views = array();

    public function track_view_after_render($content) {
        if (empty(self::$pending_views)) return $content;
        if (is_admin()) return $content;
        if (defined('DOING_AJAX') && DOING_AJAX) return $content;
        if (defined('REST_REQUEST') && REST_REQUEST) return $content;

        foreach (self::$pending_views as $form_id => $variant) {
            $existing = self::read_cookie($form_id);
            if ($existing !== '') {
                // Cookie sticky — sobrescreve a variante "anunciada" pra que o submit registre consistentemente
                $variant = $existing;
            } else {
                self::write_cookie($form_id, $variant);
            }
            self::increment($form_id, 'views', $variant);
        }
        self::$pending_views = array();
        return $content;
    }

    /* ===========================================================
       Submit hook — inclui variant + incrementa conversion
       =========================================================== */

    public function inject_variant_payload($payload, $form_id) {
        if (!is_array($payload)) return $payload;
        $form_id = sanitize_key((string) $form_id);
        $variant = self::read_cookie($form_id);
        // Fallback: olha no POST se o front mandou explicit
        if ($variant === '' && !empty($_POST['_adspirit_ab_variant'])) {
            $variant = self::sanitize_variant((string) $_POST['_adspirit_ab_variant']);
        }
        if ($variant === '') return $payload;

        $payload['_adspirit_ab_variant'] = $variant;
        self::increment($form_id, 'conversions', $variant);
        return $payload;
    }

    /* ===========================================================
       Save handler — reset
       =========================================================== */

    public function handle_save($post) {
        $action = isset($post['ab_action']) ? sanitize_key((string) $post['ab_action']) : '';
        if ($action !== 'reset') return;
        $form_id = isset($post['form_id']) ? sanitize_key((string) $post['form_id']) : '';
        if (!$form_id) {
            add_settings_error(self::OPTION_KEY, 'no_form_id', 'form_id inválido pra reset.', 'error');
            return;
        }
        $all = self::get_all();
        if (isset($all[$form_id])) {
            unset($all[$form_id]);
            update_option(self::OPTION_KEY, $all, false);
            add_settings_error(self::OPTION_KEY, 'reset_ok', 'Stats zeradas pra <code>' . esc_html($form_id) . '</code>.', 'updated');
        } else {
            add_settings_error(self::OPTION_KEY, 'not_found', 'Form não tinha stats.', 'info');
        }
    }

    /* ===========================================================
       Storage helpers
       =========================================================== */

    public static function get_all() {
        $v = get_option(self::OPTION_KEY, array());
        return is_array($v) ? $v : array();
    }

    private static function increment($form_id, $bucket, $variant) {
        $form_id = sanitize_key((string) $form_id);
        $variant = self::sanitize_variant((string) $variant);
        if ($form_id === '' || $variant === '') return;
        if ($bucket !== 'views' && $bucket !== 'conversions') return;

        $all = self::get_all();
        if (!isset($all[$form_id]) || !is_array($all[$form_id])) {
            $all[$form_id] = array('variants' => array(), 'views' => array(), 'conversions' => array());
        }
        foreach (array('variants', 'views', 'conversions') as $k) {
            if (!isset($all[$form_id][$k]) || !is_array($all[$form_id][$k])) {
                $all[$form_id][$k] = array();
            }
        }
        if (!in_array($variant, $all[$form_id]['variants'], true)) {
            $all[$form_id]['variants'][] = $variant;
        }
        $prev = isset($all[$form_id][$bucket][$variant]) ? (int) $all[$form_id][$bucket][$variant] : 0;
        $all[$form_id][$bucket][$variant] = $prev + 1;
        update_option(self::OPTION_KEY, $all, false);
    }

    /* ===========================================================
       Cookie helpers
       =========================================================== */

    public static function read_cookie($form_id) {
        $form_id = sanitize_key((string) $form_id);
        $key = self::COOKIE_NAME . '_' . $form_id;
        if (empty($_COOKIE[$key])) return '';
        return self::sanitize_variant((string) $_COOKIE[$key]);
    }

    private static function write_cookie($form_id, $variant) {
        if (headers_sent()) return;
        $form_id = sanitize_key((string) $form_id);
        $variant = self::sanitize_variant((string) $variant);
        if ($form_id === '' || $variant === '') return;
        $key = self::COOKIE_NAME . '_' . $form_id;
        $expires = time() + self::COOKIE_TTL;
        $secure = is_ssl();
        // Compat PHP 7.4 — usa array form
        setcookie($key, $variant, array(
            'expires'  => $expires,
            'path'     => COOKIEPATH ? COOKIEPATH : '/',
            'domain'   => COOKIE_DOMAIN ? COOKIE_DOMAIN : '',
            'secure'   => $secure,
            'httponly' => false, // precisa ler em JS pra fallback se quiser
            'samesite' => 'Lax',
        ));
        // Disponibiliza no request atual
        $_COOKIE[$key] = $variant;
    }

    /* ===========================================================
       Sanitize
       =========================================================== */

    public static function sanitize_variant($raw) {
        $raw = strtoupper(trim((string) $raw));
        if ($raw === '') return '';
        if (!preg_match('/^[A-D]$/', $raw)) return '';
        return $raw;
    }
}
