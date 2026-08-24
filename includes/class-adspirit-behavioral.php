<?php
/**
 * AdSpirit Connector — Behavioral tracking (Frente 1).
 *
 * Coleta sinais comportamentais de página (scroll, tempo ativo/idle, rage
 * clicks, copy/paste, exit intent, typing, tab switches, viewport class)
 * e envia em um único evento `behavior_summary` por flush pro endpoint
 * `/api/track` do CRM.
 *
 * Decisões aprovadas:
 *   - type=custom + payload.kind='behavior_summary' (ZERO migration no CRM)
 *   - Tab admin separada da Clarity ("Behavioral")
 *   - Flush interval default 60s
 *   - Rate limit 120 behavior_summary por visitor/h (lado CRM)
 *   - LGPD: gate em consent('tracking') || consent('all')
 *
 * Sem deps no client (vanilla JS). Visitor_id vem do cookie `adspirit_vid`
 * que o pixel.js do CRM minta — se ausente, no-op (não dispara).
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Behavioral {
    const OPTION_BEHAVIORAL = 'adspirit_connector_behavioral';
    const EVENT_KIND        = 'behavior_summary';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Footer injection — gate por consent + enabled + frontend
        add_action(
            'wp_footer',
            AdSpirit_Safe_Hook::action(array($this, 'enqueue_tracker'), 'behavioral_enqueue'),
            10
        );
        // Tab registration
        add_filter(
            'adspirit_connector_tabs',
            AdSpirit_Safe_Hook::filter(array($this, 'register_tab'), 'behavioral_register_tab')
        );
        // Tab render
        add_action(
            'adspirit_connector_render_tab_behavioral',
            AdSpirit_Safe_Hook::action(array($this, 'render_tab'), 'behavioral_tab')
        );
        // Save handler (admin-post direto, fora do dispatcher universal,
        // pra usar o redirect ?updated=1&tab=behavioral conforme spec).
        add_action(
            'admin_post_adspirit_save_behavioral',
            AdSpirit_Safe_Hook::action(array($this, 'handle_save'), 'behavioral_save')
        );
    }

    // ─────────────────────────────────────────────────────────
    // SETTINGS
    // ─────────────────────────────────────────────────────────
    public static function defaults() {
        return array(
            'enabled'           => '0',
            'flush_interval_ms' => 60000,
            'track_rage_clicks' => '1',
            'track_copy'        => '1',
            'track_typing'      => '1',
            'track_idle'        => '1',
            'max_payload_bytes' => 16384,
        );
    }

    public static function get_settings() {
        $stored = get_option(self::OPTION_BEHAVIORAL, array());
        $merged = wp_parse_args(is_array($stored) ? $stored : array(), self::defaults());
        // Coerções defensivas
        $merged['flush_interval_ms'] = max(5000, (int) $merged['flush_interval_ms']);
        $merged['max_payload_bytes'] = max(1024, (int) $merged['max_payload_bytes']);
        return $merged;
    }

    public static function get_event_kind() {
        return self::EVENT_KIND;
    }

    public function register_tab($tabs) {
        if (!is_array($tabs)) return $tabs;
        $tabs['behavioral'] = 'Behavioral';
        return $tabs;
    }

    // ─────────────────────────────────────────────────────────
    // FRONTEND INJECTION
    // ─────────────────────────────────────────────────────────
    public function enqueue_tracker() {
        if (is_admin()) return;
        if (function_exists('is_login') && is_login()) return;
        // Fallback pra wp-login.php (is_login() não existe em todas versões)
        if (isset($GLOBALS['pagenow']) && in_array($GLOBALS['pagenow'], array('wp-login.php', 'wp-register.php'), true)) return;

        $s = self::get_settings();
        if ($s['enabled'] !== '1') return;

        // Consent gate
        $has_consent = false;
        if (class_exists('AdSpirit_Lgpd_Popup')) {
            $has_consent = AdSpirit_Lgpd_Popup::has_consent('tracking')
                || AdSpirit_Lgpd_Popup::has_consent('all');
        }
        if (!$has_consent) return;

        // Core config (endpoint + pixel token)
        $core = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();
        $endpoint = isset($core['endpoint_url']) ? (string) $core['endpoint_url'] : '';
        $pixel_token = isset($core['pixel_token']) ? (string) $core['pixel_token'] : '';
        if ($endpoint === '' || $pixel_token === '') return;

        $handle = 'adspirit-behavioral';
        $src    = ADSPIRIT_CONNECTOR_URL . 'assets/behavioral.js';
        wp_enqueue_script($handle, $src, array(), ADSPIRIT_CONNECTOR_VERSION, true);

        wp_localize_script($handle, 'AdspiritBehavioralCfg', array(
            'endpoint_url'      => esc_url_raw($endpoint),
            'pixel_token'       => $pixel_token,
            'flush_interval_ms' => (int) $s['flush_interval_ms'],
            'max_payload_bytes' => (int) $s['max_payload_bytes'],
            'track_rage_clicks' => $s['track_rage_clicks'] === '1',
            'track_copy'        => $s['track_copy'] === '1',
            'track_typing'      => $s['track_typing'] === '1',
            'track_idle'        => $s['track_idle'] === '1',
            'event_kind'        => self::EVENT_KIND,
            'plugin_version'    => defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : 'dev',
        ));
    }

    // ─────────────────────────────────────────────────────────
    // ADMIN TAB
    // ─────────────────────────────────────────────────────────
    public function render_tab() {
        $s = self::get_settings();
        $status_badge = $s['enabled'] === '1'
            ? '<span class="as-badge ok">Ativo</span>'
            : '<span class="as-badge muted">Desligado</span>';

        $updated = isset($_GET['updated']) && $_GET['updated'] === '1' && isset($_GET['tab']) && $_GET['tab'] === 'behavioral';
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Behavioral</span>Comportamento no site</h2>
        <p class="as-section-help">Registra como o visitante usa cada página — rolagem, tempo ativo, cliques de frustração — e anexa esse resumo ao lead no AdSpirit.</p>

        <?php if ($updated): ?>
            <div class="as-notice info">
                <div class="as-notice-kicker">Salvo</div>
                <p>Configurações de comportamento atualizadas.</p>
            </div>
        <?php endif; ?>

        <?php AdSpirit_Menu::card_open('Captura de comportamento', 'Escolha quais sinais coletar', $status_badge); ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="adspirit_save_behavioral">
            <?php wp_nonce_field('adspirit_save_behavioral', '_adspirit_nonce'); ?>

            <div class="as-toggle">
                <input type="checkbox" id="bhv_enabled" name="enabled" value="1" <?php checked($s['enabled'], '1'); ?>>
                <label class="t" for="bhv_enabled">Captura de comportamento ligada<small>Só roda pra visitantes que aceitaram cookies de rastreio no aviso de cookies.</small></label>
            </div>

            <div class="as-toggle">
                <input type="checkbox" id="bhv_rage" name="track_rage_clicks" value="1" <?php checked($s['track_rage_clicks'], '1'); ?>>
                <label class="t" for="bhv_rage">Cliques de frustração<small>3 ou mais cliques no mesmo ponto em menos de 1 segundo.</small></label>
            </div>

            <div class="as-toggle">
                <input type="checkbox" id="bhv_copy" name="track_copy" value="1" <?php checked($s['track_copy'], '1'); ?>>
                <label class="t" for="bhv_copy">Copiar e colar<small>Conta quantas vezes o visitante copiou texto — não guarda o conteúdo.</small></label>
            </div>

            <div class="as-toggle">
                <input type="checkbox" id="bhv_typing" name="track_typing" value="1" <?php checked($s['track_typing'], '1'); ?>>
                <label class="t" for="bhv_typing">Digitação em formulários<small>Conta teclas digitadas em campos — nunca registra qual tecla nem o que foi escrito.</small></label>
            </div>

            <div class="as-toggle">
                <input type="checkbox" id="bhv_idle" name="track_idle" value="1" <?php checked($s['track_idle'], '1'); ?>>
                <label class="t" for="bhv_idle">Tempo ativo na página<small>Separa o tempo de uso real do tempo com a aba parada (15s sem atividade).</small></label>
            </div>

            <div class="as-field">
                <label class="as-field-label" for="bhv_flush_interval">Intervalo de envio</label>
                <input type="number" id="bhv_flush_interval" name="flush_interval_ms" value="<?php echo esc_attr((int) $s['flush_interval_ms']); ?>" min="5000" step="1000" class="small-text"> ms
                <p class="description">A cada quanto tempo o resumo é enviado. Padrão 60000 (1 minuto); mínimo 5000.</p>
            </div>

            <div class="as-field">
                <label class="as-field-label" for="bhv_max_payload">Tamanho máximo do envio</label>
                <input type="number" id="bhv_max_payload" name="max_payload_bytes" value="<?php echo esc_attr((int) $s['max_payload_bytes']); ?>" min="1024" step="512" class="small-text"> bytes
                <p class="description">Padrão 16384 (16 KB). Acima disso, o resumo é encurtado antes de enviar.</p>
            </div>

            <details class="as-help">
                <summary>Detalhes técnicos (pra suporte)</summary>
                <ul>
                    <li>Cookie de visitante: <code>adspirit_vid</code> (criado pelo pixel do AdSpirit). Sem ele, nada dispara.</li>
                    <li>Endpoint: <code>POST <?php echo esc_html((class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core()['endpoint_url'] : '')); ?>/api/track?t=&lt;pixel_token&gt;</code>.</li>
                    <li>Evento: <code>type:"custom"</code>, <code>payload.kind:"<?php echo esc_html(self::EVENT_KIND); ?>"</code>.</li>
                    <li>Além do intervalo, também envia ao sair da página (<code>pagehide</code> / aba oculta).</li>
                    <li>Limite no AdSpirit: 120 resumos por visitante por hora.</li>
                    <li>O resumo também fica em <code>sessionStorage["adspirit_bhv_v1"]</code> pra ser anexado ao envio do formulário.</li>
                </ul>
            </details>

            <p class="submit">
                <button type="submit" class="button button-primary">Salvar comportamento</button>
            </p>
        </form>
        <?php AdSpirit_Menu::card_close(); ?>
        <?php
    }

    // ─────────────────────────────────────────────────────────
    // SAVE
    // ─────────────────────────────────────────────────────────
    public function handle_save() {
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) wp_die('forbidden', 403);
        check_admin_referer('adspirit_save_behavioral', '_adspirit_nonce');

        $patch = array();
        $patch['enabled']           = !empty($_POST['enabled']) ? '1' : '0';
        $patch['flush_interval_ms'] = isset($_POST['flush_interval_ms']) ? max(5000, (int) $_POST['flush_interval_ms']) : 60000;
        $patch['max_payload_bytes'] = isset($_POST['max_payload_bytes']) ? max(1024, (int) $_POST['max_payload_bytes']) : 16384;
        $patch['track_rage_clicks'] = !empty($_POST['track_rage_clicks']) ? '1' : '0';
        $patch['track_copy']        = !empty($_POST['track_copy']) ? '1' : '0';
        $patch['track_typing']      = !empty($_POST['track_typing']) ? '1' : '0';
        $patch['track_idle']        = !empty($_POST['track_idle']) ? '1' : '0';

        $current = self::get_settings();
        update_option(self::OPTION_BEHAVIORAL, array_merge($current, $patch), false);

        $redirect = admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=behavioral&updated=1');
        wp_safe_redirect($redirect);
        exit;
    }
}
