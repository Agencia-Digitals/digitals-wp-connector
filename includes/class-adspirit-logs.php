<?php
/**
 * AdSpirit Connector — Logs tab.
 *
 * Mostra os 3 logs circulares lado a lado:
 *   - CF7 dispatches (sent/error/skipped)
 *   - Anti-spam blocks
 *
 * Pra debug user-facing. Sem filtros sofisticados — se precisar, exporta
 * pra CSV (futuro).
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Logs {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action(
            'adspirit_connector_render_tab_logs',
            AdSpirit_Safe_Hook::action(array($this, 'render_tab'), 'logs_tab')
        );
        add_action(
            'admin_post_adspirit_clear_logs',
            AdSpirit_Safe_Hook::action(array($this, 'clear_logs'), 'logs_clear')
        );
    }

    public function render_tab() {
        $cf7_log = get_option(AdSpirit_Cf7_Handler::LOG_KEY, array());
        if (!is_array($cf7_log)) $cf7_log = array();
        $as_log = get_option(AdSpirit_Settings::OPTION_ANTISPAM_LOG, array());
        if (!is_array($as_log)) $as_log = array();
        $crash_log = class_exists('AdSpirit_Crash_Tracker') ? AdSpirit_Crash_Tracker::get_log() : array();
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Logs</span>Registros do plugin</h2>
        <p class="as-section-help">Envios, bloqueios e erros — os 100 mais recentes de cada tipo. Registros antigos saem sozinhos.</p>

        <?php
        $crash_badge = empty($crash_log) ? '<span class="as-badge ok">0 erros</span>' : '<span class="as-badge danger">' . count($crash_log) . ' erros</span>';
        AdSpirit_Menu::card_open('Erros capturados', 'Erros que o plugin conteve pra não derrubar o site · 3 ou mais em 5 min ligam o Safe Mode sozinho', $crash_badge);
        ?>
        <?php if (empty($crash_log)): ?>
            <p class="description">Plugin rodando saudável — nenhum erro capturado.</p>
        <?php else: ?>
            <table class="as-table">
                <thead>
                    <tr>
                        <th>Quando</th>
                        <th>Componente</th>
                        <th>Mensagem</th>
                        <th>Arquivo:linha</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($crash_log as $entry): ?>
                        <tr>
                            <td><?php echo esc_html(date('Y-m-d H:i:s', $entry['at'] ?? 0)); ?></td>
                            <td><span class="as-badge danger"><?php echo esc_html($entry['component'] ?? '?'); ?></span></td>
                            <td><?php echo esc_html($entry['message'] ?? ''); ?></td>
                            <td><code><?php echo esc_html(basename((string) ($entry['file'] ?? '')) . ':' . ($entry['line'] ?? '')); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if (!empty($crash_log)): ?>
            <div class="as-actions">
                <span class="spacer"></span>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=adspirit_clear_logs&which=crashes'), 'adspirit_clear_logs_crashes')); ?>" class="button-link">Limpar este registro</a>
            </div>
        <?php endif; ?>
        <?php AdSpirit_Menu::card_close(); ?>

        <?php
        $cf7_badge = '<span class="as-badge muted">' . count($cf7_log) . ' entradas</span>';
        AdSpirit_Menu::card_open('Leads enviados pro AdSpirit', 'Histórico dos envios de formulário', $cf7_badge);
        ?>
        <?php if (empty($cf7_log)): ?>
            <p class="description">Nenhum envio registrado ainda. Quando o primeiro formulário for enviado, aparece aqui em segundos.</p>
        <?php else: ?>
            <table class="as-table">
                <thead>
                    <tr>
                        <th>Quando</th>
                        <th>Status</th>
                        <th>Form</th>
                        <th>Detalhes</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($cf7_log as $entry): ?>
                        <?php
                            $status = $entry['status'] ?? '?';
                            $badge = $status === 'sent' ? 'ok' : ($status === 'error' ? 'danger' : 'muted');
                        ?>
                        <tr>
                            <td><?php echo esc_html($entry['at'] ?? ''); ?></td>
                            <td><span class="as-badge <?php echo esc_attr($badge); ?>"><?php echo esc_html($status); ?></span></td>
                            <td><?php echo esc_html($entry['form_id'] ?? '—'); ?></td>
                            <td>
                                <?php if (!empty($entry['error'])): ?>
                                    <code><?php echo esc_html($entry['error']); ?></code>
                                <?php elseif (!empty($entry['fields'])): ?>
                                    <code><?php echo esc_html(implode(', ', (array) $entry['fields'])); ?></code>
                                <?php else: ?>
                                    <span class="as-field-help">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if (!empty($cf7_log)): ?>
            <div class="as-actions">
                <span class="spacer"></span>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=adspirit_clear_logs&which=cf7'), 'adspirit_clear_logs_cf7')); ?>" class="button-link">Limpar este registro</a>
            </div>
        <?php endif; ?>
        <?php AdSpirit_Menu::card_close(); ?>

        <?php
        $as_badge = '<span class="as-badge muted">' . count($as_log) . ' entradas</span>';
        AdSpirit_Menu::card_open('Bloqueios anti-spam', 'Envios rejeitados por alguma camada do anti-spam', $as_badge);
        ?>
        <?php if (empty($as_log)): ?>
            <p class="description">Nenhum bloqueio registrado ainda.</p>
        <?php else: ?>
            <table class="as-table">
                <thead>
                    <tr>
                        <th>Quando</th>
                        <th>Camada</th>
                        <th>IP</th>
                        <th>Detalhe</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($as_log as $entry): ?>
                        <tr>
                            <td><?php echo esc_html($entry['at'] ?? ''); ?></td>
                            <td><span class="as-badge muted"><?php echo esc_html($entry['code'] ?? '?'); ?></span></td>
                            <td><code><?php echo esc_html($entry['ip'] ?? '?'); ?></code></td>
                            <td><?php echo esc_html($entry['message'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php if (!empty($as_log)): ?>
            <div class="as-actions">
                <span class="spacer"></span>
                <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=adspirit_clear_logs&which=antispam'), 'adspirit_clear_logs_antispam')); ?>" class="button-link">Limpar este registro</a>
            </div>
        <?php endif; ?>
        <?php AdSpirit_Menu::card_close(); ?>
        <?php
    }

    public function clear_logs() {
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) wp_die('forbidden', 403);
        $which = isset($_GET['which']) ? sanitize_key((string) $_GET['which']) : '';
        check_admin_referer('adspirit_clear_logs_' . $which);

        if ($which === 'cf7') {
            delete_option(AdSpirit_Cf7_Handler::LOG_KEY);
        } elseif ($which === 'antispam') {
            delete_option(AdSpirit_Settings::OPTION_ANTISPAM_LOG);
        } elseif ($which === 'crashes') {
            if (class_exists('AdSpirit_Crash_Tracker')) {
                AdSpirit_Crash_Tracker::clear_log();
            }
        }
        wp_safe_redirect(admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=logs&cleared=1'));
        exit;
    }
}
