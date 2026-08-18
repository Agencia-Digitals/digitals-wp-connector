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
            <span class="as-kicker-inline">Experimentos</span>
            Testes A/B
        </h2>
        <p class="as-section-help">
            Use <code>[adspirit_form id="seu-form" variant="auto"]</code> — o plugin
            divide o tráfego pelos pesos abaixo, declara a vencedora e cada pessoa
            vê sempre a mesma versão.
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

        <details style="margin-top:4px; color:var(--as-ink-soft); font-size:12.5px;">
            <summary style="cursor:pointer;">Como funciona</summary>
            <ul style="margin:8px 0 0; padding-left:18px; line-height:1.7;">
                <li><code>variant="auto"</code> divide pelos pesos; <code>variant="A"</code> fixa a versão (modo manual, útil com split do CMS).</li>
                <li>Cookie de 90 dias garante que a mesma pessoa nunca vê duas versões.</li>
                <li>A variante vai no lead pro AdSpirit — dá pra cruzar com a qualidade (e receita) lá.</li>
                <li>Arquivar preserva os números; zerar apaga. Vencedora recebe 100% do tráfego automático.</li>
            </ul>
        </details>
        <?php
    }

    private function render_form_card($form_id, $data) {
        $views = isset($data['views']) ? (array) $data['views'] : array();
        $conversions = isset($data['conversions']) ? (array) $data['conversions'] : array();
        $cfg = self::get_config($form_id);
        $variants = array_unique(array_merge(
            array_keys($views), array_keys($conversions), array_keys($cfg['weights'])
        ));
        sort($variants);

        $totals = array();
        foreach ($variants as $v) {
            $vw = isset($views[$v]) ? (int) $views[$v] : 0;
            $cv = isset($conversions[$v]) ? (int) $conversions[$v] : 0;
            $totals[$v] = array('views' => $vw, 'conversions' => $cv, 'rate' => $vw > 0 ? $cv / $vw : 0);
        }

        $winner = $cfg['winner'];
        $state_badge = $winner !== ''
            ? '<span class="as-badge ok">Vencedora: ' . esc_html($winner) . '</span>'
            : (count($variants) >= 2 ? '<span class="as-badge muted">Testando</span>' : '');

        $fid = 'ab-cfg-' . $form_id;
        AdSpirit_Menu::card_open('Form: ' . $form_id, '', $state_badge);
        ?>
        <?php // Form único do card — inputs/botões nos tiles apontam pra ele
              // via atributo form="". Uma ação primária; o resto é link. ?>
        <form id="<?php echo esc_attr($fid); ?>" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="adspirit_save">
            <input type="hidden" name="adspirit_tab" value="ab-tests">
            <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">
            <?php wp_nonce_field('adspirit_ab-tests_save', '_adspirit_nonce'); ?>
        </form>

        <?php if ($winner !== ''): ?>
            <p style="margin:0 0 12px; font-size:12.5px; color:var(--as-ink-soft);">
                Vencedora <?php echo $cfg['winner_by'] === 'auto' ? 'automática' : 'manual'; ?> — recebe 100% do tráfego do <code>variant="auto"</code>.
                <button form="<?php echo esc_attr($fid); ?>" name="ab_action" value="clear_winner" class="button-link">Reabrir teste</button>
            </p>
        <?php endif; ?>

        <div class="as-metric-grid">
            <?php foreach ($variants as $v):
                $t = $totals[$v];
                $is_archived = !empty($cfg['archived'][$v]);
                $is_winner = ($v === $winner);
                $weight = isset($cfg['weights'][$v]) ? (int) $cfg['weights'][$v] : 50;
                ?>
                <div class="as-metric" style="<?php echo $is_winner ? 'border-color:var(--as-accent); background:var(--as-accent-soft);' : ($is_archived ? 'opacity:.55;' : ''); ?>">
                    <div class="label">
                        Variante <?php echo esc_html($v); ?>
                        <?php if ($is_winner): ?><span class="as-badge ok" style="margin-left:6px;">Vencedora</span><?php endif; ?>
                        <?php if ($is_archived): ?><span class="as-badge muted" style="margin-left:6px;">arquivada</span><?php endif; ?>
                    </div>
                    <div class="value"><?php echo number_format_i18n($t['rate'] * 100, 2); ?>%</div>
                    <div class="sub">
                        <?php echo number_format_i18n($t['conversions']); ?> conv / <?php echo number_format_i18n($t['views']); ?> views
                        <?php if (!$is_archived && $t['views'] < self::MIN_SAMPLE): ?>
                            · <span style="color:var(--as-warning);">faltam <?php echo (self::MIN_SAMPLE - $t['views']); ?> views</span>
                        <?php endif; ?>
                    </div>
                    <div class="sub" style="margin-top:8px; display:flex; align-items:center; gap:8px; flex-wrap:wrap;">
                        <?php if (!$is_archived): ?>
                            <label style="display:inline-flex; align-items:center; gap:4px;">
                                <input form="<?php echo esc_attr($fid); ?>" type="number" name="w[<?php echo esc_attr($v); ?>]" value="<?php echo (int) $weight; ?>" min="0" max="100" style="width:56px;">%
                            </label>
                            <label title="Travada: o Equilibrar não mexe neste percentual" style="display:inline-flex; align-items:center; gap:3px;">
                                <input form="<?php echo esc_attr($fid); ?>" type="checkbox" name="lock[<?php echo esc_attr($v); ?>]" value="1" <?php checked(!empty($cfg['locked'][$v])); ?>> travar
                            </label>
                            <?php if ($winner === ''): ?>
                                <button form="<?php echo esc_attr($fid); ?>" name="ab_action" value="winner-<?php echo esc_attr(strtolower($v)); ?>" class="button-link">vencedora</button>
                            <?php endif; ?>
                            <button form="<?php echo esc_attr($fid); ?>" name="ab_action" value="archive-<?php echo esc_attr(strtolower($v)); ?>" class="button-link" style="color:var(--as-ink-faint);">arquivar</button>
                        <?php else: ?>
                            <button form="<?php echo esc_attr($fid); ?>" name="ab_action" value="restore-<?php echo esc_attr(strtolower($v)); ?>" class="button-link">restaurar</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <div style="margin-top:12px; display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
            <button form="<?php echo esc_attr($fid); ?>" name="ab_action" value="config" class="button button-primary">Salvar divisão</button>
            <button form="<?php echo esc_attr($fid); ?>" name="ab_action" value="balance" class="button" title="Divide igualmente entre as variantes destravadas">Equilibrar</button>
            <label style="display:inline-flex; align-items:center; gap:5px; font-size:12.5px; color:var(--as-ink-soft);">
                <input form="<?php echo esc_attr($fid); ?>" type="checkbox" name="auto_winner" value="1" <?php checked($cfg['auto_winner'], '1'); ?>>
                declarar vencedora sozinho
                <span title="Critério simples: todas com <?php echo self::MIN_SAMPLE; ?>+ views e a líder convertendo 1,5× a segunda. Salve a divisão pra aplicar.">ⓘ</span>
            </label>
            <span style="flex:1;"></span>
            <button form="<?php echo esc_attr($fid); ?>" name="ab_action" value="reset" class="button-link" style="color:var(--as-danger);"
                    onclick="return confirm('Zerar os números de <?php echo esc_js($form_id); ?>? Não dá pra desfazer.');">zerar números</button>
        </div>
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
        $raw = isset($atts['variant']) ? trim((string) $atts['variant']) : '';

        // 3.0 (lição WPFunnels): variant="auto" — o PLUGIN divide o tráfego
        // por pesos configuráveis na aba Testes A/B (com vencedora e
        // arquivamento). Randomização server-side, sticky pelo mesmo cookie
        // de sempre. Um shortcode só, nenhuma página duplicada.
        if (strtolower($raw) === 'auto' && isset($out['id'])) {
            $picked = self::pick_variant(sanitize_key((string) $out['id']));
            $out['variant'] = $picked;
            if ($picked !== '') self::$pending_views[$out['id']] = $picked;
            return $out;
        }

        $variant = self::sanitize_variant($raw);
        $out['variant'] = $variant; // '' se inválido / não setado

        // Marca pra `track_view_after_render` registrar essa página
        if ($variant !== '' && isset($out['id'])) {
            self::$pending_views[$out['id']] = $variant;
        }
        return $out;
    }

    /**
     * Escolhe a variante pra variant="auto": cookie sticky > vencedora >
     * sorteio ponderado entre variantes não-arquivadas. Cache por request
     * (dois shortcodes do mesmo form na página = mesma escolha).
     */
    private static $picked_cache = array();

    public static function pick_variant($form_id) {
        if ($form_id === '') return '';
        if (isset(self::$picked_cache[$form_id])) return self::$picked_cache[$form_id];

        $sticky = self::read_cookie($form_id);
        if ($sticky !== '') return self::$picked_cache[$form_id] = $sticky;

        $cfg = self::get_config($form_id);
        if (!empty($cfg['winner']) && empty($cfg['archived'][$cfg['winner']])) {
            return self::$picked_cache[$form_id] = $cfg['winner'];
        }

        // Candidatas: pesos configurados; sem config = A/B em 50/50.
        $weights = !empty($cfg['weights']) && is_array($cfg['weights'])
            ? $cfg['weights'] : array('A' => 50, 'B' => 50);
        $pool = array();
        foreach ($weights as $v => $w) {
            $v = self::sanitize_variant($v);
            if ($v === '' || !empty($cfg['archived'][$v])) continue;
            $w = max(0, (int) $w);
            if ($w > 0) $pool[$v] = $w;
        }
        if (empty($pool)) return self::$picked_cache[$form_id] = 'A';

        $total = array_sum($pool);
        $roll = wp_rand(1, $total);
        foreach ($pool as $v => $w) {
            $roll -= $w;
            if ($roll <= 0) return self::$picked_cache[$form_id] = $v;
        }
        return self::$picked_cache[$form_id] = array_key_first($pool);
    }

    /** Config do experimento por form (pesos, travas, arquivadas, vencedora). */
    public static function get_config($form_id) {
        $all = self::get_all();
        $cfg = isset($all[$form_id]['config']) && is_array($all[$form_id]['config'])
            ? $all[$form_id]['config'] : array();
        return array(
            'weights'     => isset($cfg['weights']) && is_array($cfg['weights']) ? $cfg['weights'] : array(),
            'locked'      => isset($cfg['locked']) && is_array($cfg['locked']) ? $cfg['locked'] : array(),
            'archived'    => isset($cfg['archived']) && is_array($cfg['archived']) ? $cfg['archived'] : array(),
            'auto_winner' => isset($cfg['auto_winner']) ? (string) $cfg['auto_winner'] : '0',
            'winner'      => isset($cfg['winner']) ? self::sanitize_variant((string) $cfg['winner']) : '',
            'winner_by'   => isset($cfg['winner_by']) ? (string) $cfg['winner_by'] : '',
            'winner_at'   => isset($cfg['winner_at']) ? (string) $cfg['winner_at'] : '',
        );
    }

    private static function save_config($form_id, array $cfg) {
        $all = self::get_all();
        if (!isset($all[$form_id]) || !is_array($all[$form_id])) {
            $all[$form_id] = array('variants' => array(), 'views' => array(), 'conversions' => array());
        }
        $all[$form_id]['config'] = $cfg;
        update_option(self::OPTION_KEY, $all, false);
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
        $form_id = isset($post['form_id']) ? sanitize_key((string) $post['form_id']) : '';
        if ($form_id === '' || $action === '') return;
        $variant = isset($post['variant']) ? self::sanitize_variant((string) $post['variant']) : '';
        // Botões por variante num form só: value "archive-a" / "winner-b"...
        if (preg_match('/^(archive|restore|winner)-([a-d])$/', $action, $m)) {
            $action = $m[1];
            $variant = strtoupper($m[2]);
        }
        $cfg = self::get_config($form_id);

        switch ($action) {
            case 'reset':
                $all = self::get_all();
                if (isset($all[$form_id])) {
                    unset($all[$form_id]);
                    update_option(self::OPTION_KEY, $all, false);
                    add_settings_error(self::OPTION_KEY, 'reset_ok', 'Números zerados pra <code>' . esc_html($form_id) . '</code>.', 'updated');
                }
                return;

            case 'config':
                // Pesos + travas + vencedora automática, num submit só.
                $weights = isset($post['w']) && is_array($post['w']) ? $post['w'] : array();
                $locked  = isset($post['lock']) && is_array($post['lock']) ? $post['lock'] : array();
                $cfg['weights'] = array();
                $cfg['locked'] = array();
                foreach ($weights as $v => $w) {
                    $v = self::sanitize_variant((string) $v);
                    if ($v === '') continue;
                    $cfg['weights'][$v] = max(0, min(100, (int) $w));
                    if (!empty($locked[$v])) $cfg['locked'][$v] = true;
                }
                $cfg['auto_winner'] = !empty($post['auto_winner']) ? '1' : '0';
                self::save_config($form_id, $cfg);
                add_settings_error(self::OPTION_KEY, 'cfg_ok', 'Divisão de tráfego salva.', 'updated');
                return;

            case 'balance':
                // Reequilibra as DESTRAVADAS igualmente; travadas mantêm o %.
                $active = array();
                $locked_sum = 0;
                foreach ($cfg['weights'] as $v => $w) {
                    if (!empty($cfg['archived'][$v])) continue;
                    if (!empty($cfg['locked'][$v])) { $locked_sum += (int) $w; continue; }
                    $active[] = $v;
                }
                if ($active) {
                    $share = (int) floor(max(0, 100 - $locked_sum) / count($active));
                    foreach ($active as $v) $cfg['weights'][$v] = $share;
                    self::save_config($form_id, $cfg);
                    add_settings_error(self::OPTION_KEY, 'bal_ok', 'Tráfego reequilibrado entre as variantes destravadas.', 'updated');
                }
                return;

            case 'archive':
                if ($variant === '') return;
                // Arquivar nunca apaga: números ficam, variante sai do sorteio.
                $cfg['archived'][$variant] = true;
                if ($cfg['winner'] === $variant) { $cfg['winner'] = ''; $cfg['winner_by'] = ''; }
                self::save_config($form_id, $cfg);
                add_settings_error(self::OPTION_KEY, 'arch_ok', 'Variante ' . esc_html($variant) . ' arquivada (números preservados).', 'updated');
                return;

            case 'restore':
                if ($variant === '') return;
                unset($cfg['archived'][$variant]);
                self::save_config($form_id, $cfg);
                add_settings_error(self::OPTION_KEY, 'rest_ok', 'Variante ' . esc_html($variant) . ' de volta ao teste.', 'updated');
                return;

            case 'winner':
                if ($variant === '') return;
                $cfg['winner'] = $variant;
                $cfg['winner_by'] = 'manual';
                $cfg['winner_at'] = current_time('mysql');
                self::save_config($form_id, $cfg);
                add_settings_error(self::OPTION_KEY, 'win_ok', 'Variante ' . esc_html($variant) . ' declarada vencedora — recebe 100% do tráfego automático.', 'updated');
                return;

            case 'clear_winner':
                $cfg['winner'] = '';
                $cfg['winner_by'] = '';
                self::save_config($form_id, $cfg);
                add_settings_error(self::OPTION_KEY, 'clw_ok', 'Teste reaberto — tráfego volta a dividir pelos pesos.', 'updated');
                return;
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

        // Vencedora automática (opt-in): checa só quando entra conversão.
        if ($bucket === 'conversions') self::maybe_auto_winner($form_id);
    }

    /**
     * Vencedora automática — critério SIMPLES e declarado na UI: todas as
     * variantes ativas com ≥MIN_SAMPLE views e a líder convertendo pelo
     * menos 1,5× a taxa da segunda. Sem pretensão de p-valor; é um corte
     * prático pra parar de dividir tráfego quando a diferença é óbvia.
     * A vencedora passa a receber 100% do variant="auto"; nada é apagado.
     */
    private static function maybe_auto_winner($form_id) {
        $cfg = self::get_config($form_id);
        if ($cfg['auto_winner'] !== '1' || $cfg['winner'] !== '') return;

        $all = self::get_all();
        $views = isset($all[$form_id]['views']) ? (array) $all[$form_id]['views'] : array();
        $conv  = isset($all[$form_id]['conversions']) ? (array) $all[$form_id]['conversions'] : array();
        $rates = array();
        foreach ($views as $v => $n) {
            if (!empty($cfg['archived'][$v])) continue;
            $n = (int) $n;
            if ($n < self::MIN_SAMPLE) return; // amostra insuficiente em alguma ativa
            $rates[$v] = $n > 0 ? ((int) ($conv[$v] ?? 0)) / $n : 0;
        }
        if (count($rates) < 2) return;
        arsort($rates);
        $keys = array_keys($rates);
        $lead = $rates[$keys[0]];
        $second = $rates[$keys[1]];
        if ($lead <= 0) return;
        if ($second > 0 && ($lead / $second) < 1.5) return;
        if ($second <= 0 && ((int) ($conv[$keys[0]] ?? 0)) < 5) return;

        $cfg['winner'] = $keys[0];
        $cfg['winner_by'] = 'auto';
        $cfg['winner_at'] = current_time('mysql');
        self::save_config($form_id, $cfg);
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
