<?php
/**
 * AdSpirit Connector — Crash tracker + auto safe-mode.
 *
 * register_shutdown_function captura fatal errors. Se vierem do plugin
 * e acontecerem ≥3 vezes em 5min, entra em Safe Mode automaticamente.
 * Site fica intocado — plugin se desliga sozinho até admin investigar.
 *
 * Também registra crashes não-fatais (capturados pelo Safe_Hook wrapper)
 * pra UI mostrar histórico.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Crash_Tracker {
    const CRASH_LOG_OPTION    = 'adspirit_connector_crashes';
    const CRASH_LOG_MAX       = 50;
    const AUTO_SAFE_THRESHOLD = 3;
    const AUTO_SAFE_WINDOW_S  = 300; // 5 minutos

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        register_shutdown_function(array(__CLASS__, 'on_shutdown'));
    }

    /**
     * Registra um crash + verifica se deve entrar em Safe Mode.
     */
    public static function record($component, $message, $file = '', $line = 0) {
        $crashes = get_option(self::CRASH_LOG_OPTION, array());
        if (!is_array($crashes)) $crashes = array();

        $entry = array(
            'at'        => time(),
            'component' => (string) $component,
            'message'   => substr((string) $message, 0, 500),
            'file'      => substr((string) $file, 0, 200),
            'line'      => (int) $line,
        );
        array_unshift($crashes, $entry);
        if (count($crashes) > self::CRASH_LOG_MAX) {
            $crashes = array_slice($crashes, 0, self::CRASH_LOG_MAX);
        }
        update_option(self::CRASH_LOG_OPTION, $crashes, false);

        // Conta crashes recentes na janela
        $window = time() - self::AUTO_SAFE_WINDOW_S;
        $recent = 0;
        foreach ($crashes as $c) {
            if (($c['at'] ?? 0) >= $window) $recent++;
        }
        if ($recent >= self::AUTO_SAFE_THRESHOLD && !AdSpirit_Safe_Bootstrap::is_safe_mode()) {
            AdSpirit_Safe_Bootstrap::enter_safe_mode(sprintf(
                'Auto: %d crashes em %d min (último: %s @ %s)',
                $recent,
                self::AUTO_SAFE_WINDOW_S / 60,
                $entry['component'],
                date('Y-m-d H:i:s', $entry['at'])
            ));
        }
    }

    /**
     * Shutdown handler — captura fatal errors. Só registra se o erro
     * veio de dentro da pasta do plugin (não polui com erros do tema /
     * outros plugins).
     */
    public static function on_shutdown() {
        $error = error_get_last();
        if (!$error) return;
        if (!isset($error['type'])) return;

        $fatal_types = array(
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
            E_USER_ERROR,
        );
        if (!in_array($error['type'], $fatal_types, true)) return;

        // Só se o erro veio da nossa pasta — evita falso positivo de
        // outros plugins / theme.
        $our_dir = defined('ADSPIRIT_CONNECTOR_DIR') ? ADSPIRIT_CONNECTOR_DIR : '';
        $file = isset($error['file']) ? (string) $error['file'] : '';
        if (!$our_dir || strpos($file, $our_dir) !== 0) return;

        self::record(
            'fatal_shutdown',
            $error['message'] ?? '?',
            $file,
            isset($error['line']) ? (int) $error['line'] : 0
        );
    }

    public static function get_log() {
        $v = get_option(self::CRASH_LOG_OPTION, array());
        return is_array($v) ? $v : array();
    }

    public static function clear_log() {
        delete_option(self::CRASH_LOG_OPTION);
    }
}
