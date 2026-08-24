<?php
/**
 * AdSpirit Connector — agregação de métricas pra dashboard.
 *
 * Lê:
 *   - log circular do CF7 handler (sent/error/skipped)
 *   - log circular do anti-spam (blocked entries)
 * Computa janelas 24h / 7d / 30d + taxa de sucesso.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Health_Checker {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {}

    public static function summarize() {
        $cf7_log = get_option(AdSpirit_Cf7_Handler::LOG_KEY, array());
        if (!is_array($cf7_log)) $cf7_log = array();
        $as_log = get_option(AdSpirit_Settings::OPTION_ANTISPAM_LOG, array());
        if (!is_array($as_log)) $as_log = array();

        $now = time();
        $w24h = $now - DAY_IN_SECONDS;
        $w7d  = $now - 7 * DAY_IN_SECONDS;
        $w30d = $now - 30 * DAY_IN_SECONDS;

        $cf7 = array(
            'sent_24h' => 0, 'sent_7d' => 0, 'sent_30d' => 0,
            'failed_24h' => 0, 'failed_7d' => 0, 'failed_30d' => 0,
            'last_at' => null, 'last_error' => null,
        );

        foreach ($cf7_log as $entry) {
            if (empty($entry['at'])) continue;
            $ts = strtotime($entry['at']);
            if (!$ts) continue;
            $status = $entry['status'] ?? '';
            if ($status === 'sent') {
                if ($ts >= $w24h) $cf7['sent_24h']++;
                if ($ts >= $w7d)  $cf7['sent_7d']++;
                if ($ts >= $w30d) $cf7['sent_30d']++;
                if (!$cf7['last_at']) $cf7['last_at'] = $ts;
            } elseif ($status === 'error') {
                if ($ts >= $w24h) $cf7['failed_24h']++;
                if ($ts >= $w7d)  $cf7['failed_7d']++;
                if ($ts >= $w30d) $cf7['failed_30d']++;
                if (!$cf7['last_error']) $cf7['last_error'] = $entry['error'] ?? 'erro desconhecido';
            }
        }

        $as = array(
            'blocked_24h' => 0, 'blocked_7d' => 0, 'blocked_30d' => 0,
        );
        foreach ($as_log as $entry) {
            if (empty($entry['at'])) continue;
            $ts = strtotime($entry['at']);
            if (!$ts) continue;
            if ($ts >= $w24h) $as['blocked_24h']++;
            if ($ts >= $w7d)  $as['blocked_7d']++;
            if ($ts >= $w30d) $as['blocked_30d']++;
        }

        $total_attempts = $cf7['sent_30d'] + $cf7['failed_30d'];
        $success_rate = $total_attempts > 0
            ? round(($cf7['sent_30d'] / $total_attempts) * 100, 1)
            : 100;

        // P0-3: credenciais rejeitadas pelo CRM (401/403) — setado/limpo pelo
        // Lead Store a cada tentativa de envio. null = sem problema.
        $auth_error = get_option(
            class_exists('AdSpirit_Lead_Store')
                ? AdSpirit_Lead_Store::OPTION_AUTH_ERROR
                : 'adspirit_connector_crm_auth_error'
        );

        // A TABELA é a fonte quando existe. O log acima (wp_options, só CF7,
        // capado em 50, TTL 30d) não enxerga lead vindo do qualifier nem do
        // formulário nativo — e a lista logo abaixo destes números lê a
        // tabela. Dava contradição na mesma tela: "Último lead: 2 semanas
        // atrás" com leads de hoje na lista (visto pelo Pedro em 2026-08-24).
        $t = self::from_lead_store($w24h, $w7d, $w30d);
        if ($t !== null) {
            $cf7 = array_merge($cf7, $t);
            $tot = $cf7['sent_30d'] + $cf7['failed_30d'];
            $success_rate = $tot > 0 ? (int) round(($cf7['sent_30d'] / $tot) * 100) : 100;
        }

        return array(
            'crm_auth_error'       => (is_array($auth_error) && !empty($auth_error)) ? $auth_error : null,
            'cf7_sent_24h'         => $cf7['sent_24h'],
            'cf7_sent_7d'          => $cf7['sent_7d'],
            'cf7_sent_30d'         => $cf7['sent_30d'],
            'cf7_failed_24h'       => $cf7['failed_24h'],
            'cf7_failed_7d'        => $cf7['failed_7d'],
            'cf7_failed_30d'       => $cf7['failed_30d'],
            'success_rate'         => $success_rate,
            'last_cf7_at_iso'      => $cf7['last_at'] ? gmdate('c', $cf7['last_at']) : null,
            'last_cf7_at_human'    => $cf7['last_at'] ? human_time_diff($cf7['last_at'], $now) . ' atrás' : null,
            'last_error'           => $cf7['last_error'],
            'antispam_blocked_24h' => $as['blocked_24h'],
            'antispam_blocked_7d'  => $as['blocked_7d'],
            'antispam_blocked_30d' => $as['blocked_30d'],
        );
    }

    /**
     * Os mesmos números, lidos da tabela de submissões — que registra TODA
     * origem (CF7, qualifier, formulário nativo, adapters, coletor), sem
     * cap nem TTL.
     *
     * Parciais ficam de fora: o lead completo tem linha própria e contá-los
     * dobraria a mesma pessoa.
     *
     * @return array|null null quando a tabela não existe (aí vale o log).
     */
    private static function from_lead_store($w24h, $w7d, $w30d) {
        if (!class_exists('AdSpirit_Lead_Store') || !AdSpirit_Lead_Store::available()) return null;
        global $wpdb;
        $table = AdSpirit_Lead_Store::table_name();

        $conta = function ($status_sql, $desde) use ($wpdb, $table) {
            return (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table}
                 WHERE created_at >= %s AND source <> 'qualifier_partial' AND {$status_sql}",
                gmdate('Y-m-d H:i:s', $desde)
            ));
        };
        $enviado = "status = 'sent'";
        $falho   = "status IN ('failed','pending')";

        $ultimo = $wpdb->get_row(
            "SELECT created_at, last_error, status FROM {$table}
             WHERE source <> 'qualifier_partial'
             ORDER BY created_at DESC LIMIT 1", ARRAY_A
        );
        $last_at = (!empty($ultimo['created_at']))
            ? strtotime((string) $ultimo['created_at'] . ' UTC') : null;

        return array(
            'sent_24h'   => $conta($enviado, $w24h),
            'sent_7d'    => $conta($enviado, $w7d),
            'sent_30d'   => $conta($enviado, $w30d),
            'failed_24h' => $conta($falho, $w24h),
            'failed_7d'  => $conta($falho, $w7d),
            'failed_30d' => $conta($falho, $w30d),
            'last_at'    => $last_at ?: null,
            'last_error' => (!empty($ultimo['last_error']) && ($ultimo['status'] ?? '') !== 'sent')
                ? (string) $ultimo['last_error'] : null,
        );
    }
}
