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

        return array(
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
}
