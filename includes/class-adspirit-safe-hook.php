<?php
/**
 * AdSpirit Connector — Safe Hook wrapper.
 *
 * Encapsula callbacks de add_action / add_filter num try/catch que:
 *   1. Captura QUALQUER \Throwable (Exception, Error, TypeError, etc)
 *   2. Loga via error_log + registra em Crash_Tracker
 *   3. Pra filtros: retorna o valor original (não quebra a chain)
 *   4. Pra ações: silently swallow
 *
 * Uso:
 *   add_action('foo', AdSpirit_Safe_Hook::action(array($this, 'handler'), 'component_name'), 10, 1);
 *   add_filter('bar', AdSpirit_Safe_Hook::filter(array($this, 'mutate'), 'component_name'), 10, 2);
 *
 * Sem isso, uma exception num hook borbulha pro WordPress e pode
 * derrubar a página inteira.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Safe_Hook {
    /**
     * Wrapper pra add_action — silently swallow exceptions.
     */
    public static function action($callback, $component = 'unknown') {
        return function() use ($callback, $component) {
            $args = func_get_args();
            try {
                return call_user_func_array($callback, $args);
            } catch (\Throwable $e) {
                self::on_error($component, $e);
                return null;
            }
        };
    }

    /**
     * Wrapper pra add_filter — retorna o primeiro arg (valor original) em
     * caso de erro, preservando a chain.
     */
    public static function filter($callback, $component = 'unknown') {
        return function() use ($callback, $component) {
            $args = func_get_args();
            try {
                return call_user_func_array($callback, $args);
            } catch (\Throwable $e) {
                self::on_error($component, $e);
                return $args[0] ?? null; // passa o valor original adiante
            }
        };
    }

    /**
     * Wrapper genérico pra blocos de código que precisam falhar
     * silenciosamente (não-hook). Retorna $default em caso de erro.
     */
    public static function try_run(callable $callback, $default = null, $component = 'unknown') {
        try {
            return $callback();
        } catch (\Throwable $e) {
            self::on_error($component, $e);
            return $default;
        }
    }

    private static function on_error($component, \Throwable $e) {
        $msg = sprintf(
            '[AdSpirit Connector] %s threw %s: %s @ %s:%d',
            $component,
            get_class($e),
            $e->getMessage(),
            $e->getFile(),
            $e->getLine()
        );
        error_log($msg);

        if (class_exists('AdSpirit_Crash_Tracker')) {
            AdSpirit_Crash_Tracker::record(
                $component,
                get_class($e) . ': ' . $e->getMessage(),
                $e->getFile(),
                $e->getLine()
            );
        }
    }
}
