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
        add_action('adspirit_connector_render_tab_logs', array($this, 'render_tab'));
        add_action('admin_post_adspirit_clear_logs', array($this, 'clear_logs'));
    }

    public function render_tab() {
        $cf7_log = get_option(AdSpirit_Cf7_Handler::LOG_KEY, array());
        if (!is_array($cf7_log)) $cf7_log = array();
        $as_log = get_option(AdSpirit_Settings::OPTION_ANTISPAM_LOG, array());
        if (!is_array($as_log)) $as_log = array();
        ?>
        <h2>Logs</h2>
        <p class="description">Últimas 100 entradas de cada tipo. Logs circulares — entradas antigas são descartadas automaticamente.</p>

        <p>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=adspirit_clear_logs&which=cf7'), 'adspirit_clear_logs_cf7')); ?>" class="button">Limpar log CF7</a>
            <a href="<?php echo esc_url(wp_nonce_url(admin_url('admin-post.php?action=adspirit_clear_logs&which=antispam'), 'adspirit_clear_logs_antispam')); ?>" class="button">Limpar log Anti-spam</a>
        </p>

        <h3>CF7 → CRM (<?php echo count($cf7_log); ?>)</h3>
        <?php if (empty($cf7_log)): ?>
            <p>Nenhuma submissão registrada ainda.</p>
        <?php else: ?>
            <table class="widefat striped">
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
                            <td><span class="badge <?php echo esc_attr($badge); ?>"><?php echo esc_html($status); ?></span></td>
                            <td><?php echo esc_html($entry['form_id'] ?? '—'); ?></td>
                            <td>
                                <?php if (!empty($entry['error'])): ?>
                                    <code><?php echo esc_html($entry['error']); ?></code>
                                <?php elseif (!empty($entry['fields'])): ?>
                                    <code><?php echo esc_html(implode(', ', (array) $entry['fields'])); ?></code>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h3>Anti-spam blocks (<?php echo count($as_log); ?>)</h3>
        <?php if (empty($as_log)): ?>
            <p>Nenhum bloqueio registrado ainda.</p>
        <?php else: ?>
            <table class="widefat striped">
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
                            <td><code><?php echo esc_html($entry['code'] ?? '?'); ?></code></td>
                            <td><code><?php echo esc_html($entry['ip'] ?? '?'); ?></code></td>
                            <td><?php echo esc_html($entry['message'] ?? ''); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
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
        }
        wp_safe_redirect(admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=logs&cleared=1'));
        exit;
    }
}
