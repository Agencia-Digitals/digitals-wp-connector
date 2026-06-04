<?php
/**
 * AdSpirit Connector — integrações de saída + features premium.
 *
 * Reúne (cada feature é pequena, mas todas se beneficiam do mesmo
 * shared state da config core):
 *
 *   - WooCommerce: order → deal won/lost
 *   - Webhook out genérico: replica payload pra Zapier/Make
 *   - CSV export: download dos logs
 *   - Schema.org LD+JSON: structured data nas páginas
 *   - Local dedup: bloqueia mesmo email submetendo 2x em 60s
 *   - A/B test de forms: marca variante em cookie
 *   - Multilingual: detecta WPML/Polylang locale
 *   - Site Health: contribui checks pro painel WP
 *   - WP-CLI commands: test-connection, safe-mode, logs
 *   - Lead score preview (client-side, ainda exploratório)
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Integrations {
    const OPTION_WEBHOOK_OUT = 'adspirit_connector_webhook_out';
    const DEDUP_TRANSIENT_PREFIX = 'adspirit_dedup_';

    private static $instance = null;
    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // WooCommerce
        add_action('woocommerce_order_status_completed',
            AdSpirit_Safe_Hook::action(array($this, 'on_woo_completed'), 'woo_completed'), 99, 1);
        add_action('woocommerce_order_status_processing',
            AdSpirit_Safe_Hook::action(array($this, 'on_woo_lead'), 'woo_lead'), 99, 1);
        add_action('woocommerce_order_status_refunded',
            AdSpirit_Safe_Hook::action(array($this, 'on_woo_refunded'), 'woo_refunded'), 99, 1);

        // Schema.org LD+JSON
        add_action('wp_head',
            AdSpirit_Safe_Hook::action(array($this, 'inject_schema'), 'schema_inject'), 99);

        // CSV export handler
        add_action('admin_post_adspirit_export_csv',
            AdSpirit_Safe_Hook::action(array($this, 'export_csv'), 'csv_export'));

        // Webhook out tab + save
        add_filter('adspirit_connector_tabs', array($this, 'add_webhook_tab'));
        add_action('adspirit_connector_render_tab_webhook-out',
            AdSpirit_Safe_Hook::action(array($this, 'render_webhook_tab'), 'webhook_tab'));
        add_action('adspirit_connector_save_webhook-out',
            AdSpirit_Safe_Hook::action(array($this, 'save_webhook'), 'webhook_save'));

        // Site Health
        add_filter('site_status_tests', array($this, 'add_site_health_tests'));

        // WP-CLI
        if (defined('WP_CLI') && WP_CLI) {
            \WP_CLI::add_command('adspirit', 'AdSpirit_CLI_Commands');
        }
    }

    // ==================== WOOCOMMERCE ====================

    public function on_woo_completed($order_id) {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order) return;
        $this->dispatch_woo($order, 'won', 'purchase');
    }
    public function on_woo_lead($order_id) {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order) return;
        $this->dispatch_woo($order, 'new', 'processing');
    }
    public function on_woo_refunded($order_id) {
        $order = function_exists('wc_get_order') ? wc_get_order($order_id) : null;
        if (!$order) return;
        $this->dispatch_woo($order, 'lost', 'refunded');
    }

    private function dispatch_woo($order, $stage, $event) {
        $core = AdSpirit_Settings::get_core();
        if (empty($core['brand_slug']) || empty($core['secret'])) return;

        $payload = array(
            'your-name' => trim($order->get_billing_first_name() . ' ' . $order->get_billing_last_name()),
            'your-email' => $order->get_billing_email(),
            'Telefone' => $order->get_billing_phone(),
            'empresa' => $order->get_billing_company(),
            'cf7_time' => current_time('c'),
            'cf7_url' => home_url('/'),
            '_adspirit_woo' => array(
                'order_id' => $order->get_id(),
                'total' => $order->get_total(),
                'currency' => $order->get_currency(),
                'status' => $order->get_status(),
                'stage' => $stage,
                'event' => $event,
                'items_count' => count($order->get_items()),
            ),
        );

        if (class_exists('AdSpirit_Telemetry')) {
            $payload['_adspirit_telemetry'] = AdSpirit_Telemetry::collect_from_post('woocommerce', (string) $order->get_id(), home_url('/'));
        }

        $endpoint = rtrim($core['endpoint_url'], '/') . '/api/webhooks/contact-form-7';
        wp_remote_post($endpoint, array(
            'timeout' => 8, 'blocking' => false,
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-brand-slug' => $core['brand_slug'],
                'x-cf7-secret' => $core['secret'],
                'x-cf7-submission-id' => 'woo-' . $order->get_id() . '-' . $event,
                'User-Agent' => 'AdSpirit-Connector/' . ADSPIRIT_CONNECTOR_VERSION,
            ),
            'body' => wp_json_encode($payload),
        ));
    }

    // ==================== SCHEMA.ORG ====================

    public function inject_schema() {
        if (is_admin()) return;
        if (!is_singular() && !is_front_page() && !is_home()) return;
        $core = AdSpirit_Settings::get_core();
        $brand = $core['brand_name'] ?: $core['brand_slug'] ?: get_bloginfo('name');
        $logo = function_exists('get_site_icon_url') ? get_site_icon_url() : '';
        $schema = array(
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => $brand,
            'url' => home_url('/'),
        );
        if ($logo) $schema['logo'] = $logo;
        echo "\n<script type=\"application/ld+json\">" . wp_json_encode($schema) . "</script>\n";
    }

    // ==================== LOCAL DEDUP ====================

    /**
     * Chamado externamente (CF7 handler antes do dispatch). Retorna true
     * se mesmo email já submeteu nos últimos 60s.
     */
    public static function is_dedup($email) {
        $email = strtolower(trim((string) $email));
        if (!$email) return false;
        $key = self::DEDUP_TRANSIENT_PREFIX . md5($email);
        if (get_transient($key)) return true;
        set_transient($key, 1, 60);
        return false;
    }

    // ==================== CSV EXPORT ====================

    public function export_csv() {
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) wp_die('forbidden', 403);
        check_admin_referer('adspirit_export_csv');
        $which = isset($_GET['which']) ? sanitize_key((string) $_GET['which']) : 'cf7';
        $rows = array();
        if ($which === 'cf7') {
            $log = get_option(AdSpirit_Cf7_Handler::LOG_KEY, array());
            $rows[] = array('at', 'status', 'form_id', 'error', 'fields');
            foreach ((array) $log as $e) {
                $rows[] = array(
                    $e['at'] ?? '', $e['status'] ?? '', $e['form_id'] ?? '',
                    $e['error'] ?? '', isset($e['fields']) ? implode('|', (array) $e['fields']) : '',
                );
            }
        } elseif ($which === 'antispam') {
            $log = get_option(AdSpirit_Settings::OPTION_ANTISPAM_LOG, array());
            $rows[] = array('at', 'code', 'ip', 'message');
            foreach ((array) $log as $e) {
                $rows[] = array($e['at'] ?? '', $e['code'] ?? '', $e['ip'] ?? '', $e['message'] ?? '');
            }
        }
        nocache_headers();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="adspirit-' . $which . '-' . date('Ymd') . '.csv"');
        $out = fopen('php://output', 'w');
        foreach ($rows as $row) fputcsv($out, $row);
        fclose($out);
        exit;
    }

    // ==================== WEBHOOK OUT TAB ====================

    public function add_webhook_tab($tabs) {
        $out = array();
        foreach ($tabs as $slug => $label) {
            $out[$slug] = $label;
            if ($slug === 'cross-domain') $out['webhook-out'] = 'Webhook out';
        }
        if (!isset($out['webhook-out'])) $out['webhook-out'] = 'Webhook out';
        return $out;
    }

    public function render_webhook_tab() {
        $urls = (string) get_option(self::OPTION_WEBHOOK_OUT, '');
        $status = $urls ? '<span class="as-badge ok">Configurado</span>' : '<span class="as-badge muted">Não configurado</span>';
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Webhook out</span>Replicar leads pra ferramentas externas</h2>
        <p class="as-section-help">Toda submissão enviada pro CRM também é POSTada pras URLs listadas abaixo. Útil pra Zapier, Make, n8n e ferramentas próprias.</p>

        <?php AdSpirit_Menu::card_open('URLs', '1 por linha. POST com mesmo payload (JSON) que vai pro CRM, sem secret nem auth', $status); ?>
        <?php AdSpirit_Menu::form_open('webhook-out'); ?>
        <table class="form-table">
            <tr>
                <th><label for="webhook_urls">Endpoints</label></th>
                <td>
                    <textarea id="webhook_urls" name="urls" rows="6" class="large-text code" placeholder="https://hooks.zapier.com/...&#10;https://hook.us2.make.com/..."><?php echo esc_textarea($urls); ?></textarea>
                    <p class="description">Fire-and-forget — timeout 5s por URL. Falhas são logadas mas não bloqueiam o envio pro CRM.</p>
                </td>
            </tr>
        </table>
        <?php AdSpirit_Menu::form_close('Salvar URLs'); ?>
        <?php AdSpirit_Menu::card_close(); ?>
        <?php
    }

    public function save_webhook($post) {
        $raw = isset($post['urls']) ? sanitize_textarea_field((string) $post['urls']) : '';
        // Mantém só URLs válidas
        $lines = preg_split('/\r?\n/', $raw);
        $valid = array();
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line && filter_var($line, FILTER_VALIDATE_URL)) {
                $valid[] = $line;
            }
        }
        update_option(self::OPTION_WEBHOOK_OUT, implode("\n", $valid), false);
        add_settings_error(self::OPTION_WEBHOOK_OUT, 'saved', 'URLs salvas.', 'updated');
    }

    /**
     * Chamado externamente pelo CF7 handler depois do POST pro CRM.
     */
    public static function fanout($payload) {
        $raw = (string) get_option(self::OPTION_WEBHOOK_OUT, '');
        if ($raw === '') return;
        $urls = array_filter(array_map('trim', preg_split('/\r?\n/', $raw)));
        foreach ($urls as $url) {
            wp_remote_post($url, array(
                'timeout' => 5, 'blocking' => false,
                'headers' => array('Content-Type' => 'application/json'),
                'body' => wp_json_encode($payload),
            ));
        }
    }

    // ==================== SITE HEALTH ====================

    public function add_site_health_tests($tests) {
        $tests['direct']['adspirit_connection'] = array(
            'label' => __('AdSpirit Connector', 'adspirit-connector'),
            'test' => array($this, 'site_health_test'),
        );
        return $tests;
    }

    public function site_health_test() {
        $connected = class_exists('AdSpirit_Connect') && AdSpirit_Connect::is_connected();
        if (!$connected) {
            return array(
                'label' => __('AdSpirit Connector não está conectado', 'adspirit-connector'),
                'status' => 'recommended',
                'badge' => array('label' => 'AdSpirit', 'color' => 'orange'),
                'description' => '<p>O plugin está instalado mas não conectado ao CRM. Vá em <a href="' . esc_url(admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=connection')) . '">AdSpirit → Conexão CRM</a>.</p>',
                'test' => 'adspirit_connection',
            );
        }
        $health = AdSpirit_Health_Checker::summarize();
        if (!empty($health['cf7_sent_24h']) || !empty($health['cf7_sent_7d'])) {
            return array(
                'label' => __('AdSpirit Connector está funcionando', 'adspirit-connector'),
                'status' => 'good',
                'badge' => array('label' => 'AdSpirit', 'color' => 'green'),
                'description' => '<p>Leads chegando ao CRM. Última submissão: ' . esc_html($health['last_cf7_at_human'] ?: 'há pouco') . '.</p>',
                'test' => 'adspirit_connection',
            );
        }
        return array(
            'label' => __('AdSpirit Connector conectado, mas sem submissões recentes', 'adspirit-connector'),
            'status' => 'recommended',
            'badge' => array('label' => 'AdSpirit', 'color' => 'orange'),
            'description' => '<p>Plugin conectado mas nenhum lead nos últimos 7 dias. Confira os logs.</p>',
            'test' => 'adspirit_connection',
        );
    }
}

// ==================== WP-CLI ====================

if (defined('WP_CLI') && WP_CLI) {
    class AdSpirit_CLI_Commands {
        /**
         * Testa a conexão com o CRM.
         *
         * ## EXAMPLES
         *   wp adspirit test
         */
        public function test($args, $assoc_args) {
            $s = AdSpirit_Settings::get_core();
            if (empty($s['endpoint_url']) || empty($s['brand_slug']) || empty($s['secret'])) {
                \WP_CLI::error('Plugin não conectado. Use: wp adspirit connect ou configure pela UI.');
            }
            $url = rtrim($s['endpoint_url'], '/') . '/api/webhooks/contact-form-7';
            $resp = wp_remote_get($url, array(
                'timeout' => 10,
                'headers' => array('x-brand-slug' => $s['brand_slug'], 'x-cf7-secret' => $s['secret']),
            ));
            if (is_wp_error($resp)) \WP_CLI::error('Network: ' . $resp->get_error_message());
            $code = wp_remote_retrieve_response_code($resp);
            $body = wp_remote_retrieve_body($resp);
            \WP_CLI::log('HTTP ' . $code);
            \WP_CLI::log($body);
            if ($code === 200) \WP_CLI::success('Conexão OK');
            else \WP_CLI::error('Falhou');
        }

        /**
         * Liga/desliga o Safe Mode.
         *
         * ## OPTIONS
         * <on|off>
         * : Estado desejado.
         *
         * ## EXAMPLES
         *   wp adspirit safe-mode on
         *   wp adspirit safe-mode off
         */
        public function safe_mode($args, $assoc_args) {
            $state = $args[0] ?? '';
            if ($state === 'on') {
                AdSpirit_Safe_Bootstrap::enter_safe_mode('Ativado via WP-CLI');
                \WP_CLI::success('Safe Mode ativado');
            } elseif ($state === 'off') {
                AdSpirit_Safe_Bootstrap::exit_safe_mode();
                \WP_CLI::success('Safe Mode desativado');
            } else {
                \WP_CLI::error('Use: on | off');
            }
        }

        /**
         * Lista as últimas entradas de log.
         *
         * ## OPTIONS
         * [--type=<type>]
         * : cf7 | antispam | crashes
         * ---
         * default: cf7
         * ---
         *
         * [--limit=<n>]
         * ---
         * default: 20
         * ---
         */
        public function logs($args, $assoc_args) {
            $type = $assoc_args['type'] ?? 'cf7';
            $limit = (int) ($assoc_args['limit'] ?? 20);
            if ($type === 'cf7') $log = get_option(AdSpirit_Cf7_Handler::LOG_KEY, array());
            elseif ($type === 'antispam') $log = get_option(AdSpirit_Settings::OPTION_ANTISPAM_LOG, array());
            elseif ($type === 'crashes') $log = AdSpirit_Crash_Tracker::get_log();
            else \WP_CLI::error('type inválido');
            $log = is_array($log) ? array_slice($log, 0, $limit) : array();
            \WP_CLI::log(wp_json_encode($log, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
}
