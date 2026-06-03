<?php
/**
 * AdSpirit Connector — Safe bootstrap + emergency Safe Mode.
 *
 * Garante que o plugin NUNCA derruba o site. Camadas:
 *   1. Version check (PHP + WP) na inicialização — se falhar, plugin
 *      degrada silenciosamente (não roda hooks).
 *   2. Safe Mode global — option `adspirit_connector_safe_mode=1`
 *      desativa TODAS as features (frontend + hooks). UI continua
 *      acessível pra admin sair do modo.
 *   3. can_run(): cada classe consulta antes de registrar hooks. Resultado
 *      cacheado num static pra não bater no DB em todo page load.
 *
 * Entrar em Safe Mode acontece em 3 caminhos:
 *   - Manual (botão na UI)
 *   - Auto (Crash_Tracker detecta ≥3 crashes em 5min)
 *   - Forçado por admin via wp-cli (futuro)
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Safe_Bootstrap {
    const MIN_PHP             = '7.4';
    const MIN_WP              = '6.0';
    const SAFE_MODE_OPTION    = 'adspirit_connector_safe_mode';
    const SAFE_REASON_OPTION  = 'adspirit_connector_safe_reason';
    const SAFE_AT_OPTION      = 'adspirit_connector_safe_at';

    private static $can_run_cache = null;

    /**
     * Pode rodar features? Consulta version check + safe mode.
     * Cacheado por request — não rebate em options várias vezes.
     */
    public static function can_run() {
        if (self::$can_run_cache !== null) return self::$can_run_cache;

        // PHP version
        if (version_compare(PHP_VERSION, self::MIN_PHP, '<')) {
            error_log(sprintf(
                '[AdSpirit Connector] PHP %s abaixo do mínimo %s — desativando features',
                PHP_VERSION, self::MIN_PHP
            ));
            self::$can_run_cache = false;
            return false;
        }

        // WP version
        if (function_exists('get_bloginfo')) {
            $wp = get_bloginfo('version');
            if ($wp && version_compare($wp, self::MIN_WP, '<')) {
                error_log(sprintf(
                    '[AdSpirit Connector] WP %s abaixo do mínimo %s — desativando features',
                    $wp, self::MIN_WP
                ));
                self::$can_run_cache = false;
                return false;
            }
        }

        // Safe Mode
        if (self::is_safe_mode()) {
            self::$can_run_cache = false;
            return false;
        }

        self::$can_run_cache = true;
        return true;
    }

    public static function is_safe_mode() {
        return get_option(self::SAFE_MODE_OPTION) === '1';
    }

    public static function safe_mode_reason() {
        return (string) get_option(self::SAFE_REASON_OPTION, '');
    }

    public static function safe_mode_at() {
        $v = get_option(self::SAFE_AT_OPTION);
        return $v ? (int) $v : null;
    }

    public static function enter_safe_mode($reason) {
        update_option(self::SAFE_MODE_OPTION, '1', false);
        update_option(self::SAFE_REASON_OPTION, (string) $reason, false);
        update_option(self::SAFE_AT_OPTION, time(), false);
        self::$can_run_cache = false;
        error_log('[AdSpirit Connector] SAFE MODE ATIVADO: ' . $reason);
    }

    public static function exit_safe_mode() {
        update_option(self::SAFE_MODE_OPTION, '0', false);
        delete_option(self::SAFE_REASON_OPTION);
        delete_option(self::SAFE_AT_OPTION);
        self::$can_run_cache = null;
        error_log('[AdSpirit Connector] Safe mode desativado por admin');
    }

    /**
     * Valida ambiente em activation. Se falhar, desativa o próprio plugin
     * antes de qualquer hook ser registrado — site fica intocado.
     */
    public static function validate_for_activation() {
        $errors = array();
        if (version_compare(PHP_VERSION, self::MIN_PHP, '<')) {
            $errors[] = sprintf('PHP %s+ requerido (você tem %s).', self::MIN_PHP, PHP_VERSION);
        }
        if (function_exists('get_bloginfo')) {
            $wp = get_bloginfo('version');
            if ($wp && version_compare($wp, self::MIN_WP, '<')) {
                $errors[] = sprintf('WordPress %s+ requerido (você tem %s).', self::MIN_WP, $wp);
            }
        }
        if (!empty($errors)) {
            deactivate_plugins(plugin_basename(ADSPIRIT_CONNECTOR_FILE));
            $msg = "AdSpirit Connector não pode ser ativado:\n\n" . implode("\n", $errors);
            $msg .= "\n\nPlugin desativado automaticamente. Atualize seu ambiente e tente de novo.";
            wp_die(esc_html($msg), 'AdSpirit Connector — incompatibilidade', array('back_link' => true));
        }
    }
}
