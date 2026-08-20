<?php
/**
 * AdSpirit Connector — pixel servido do próprio domínio (anti ad-blocker).
 *
 * Padrão Stape (lição dos pares): bloqueadores filtram por URL de terceiro;
 * servir o MESMO pixel.js de um endereço first-party derrota o filtro de
 * script. O código continua vindo do CRM — este módulo só faz cache e
 * entrega local. Opt-in por site (default OFF: nada muda até ligar).
 *
 * Nome de action neutro de propósito ("as_assets") — padrões tipo /pixel,
 * /track e /analytics são exatamente o que as listas bloqueiam.
 *
 * Fail-soft em camadas: cache fresco (1h) → última cópia boa (option) →
 * redirect 302 pro CRM. O rastreio nunca fica pior do que era sem o proxy.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Pixel_Proxy {

    const AJAX_ACTION      = 'as_assets';
    const TRANSIENT_CACHE  = 'adspirit_px_cache';
    const OPTION_LAST_GOOD = 'adspirit_px_last_good';
    const CACHE_TTL        = HOUR_IN_SECONDS;

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_ajax_' . self::AJAX_ACTION,
            AdSpirit_Safe_Hook::action(array($this, 'serve'), 'pixel_proxy'));
        add_action('wp_ajax_nopriv_' . self::AJAX_ACTION,
            AdSpirit_Safe_Hook::action(array($this, 'serve'), 'pixel_proxy'));
    }

    /** URL local do pixel (usada pelo injector quando o modo está ligado). */
    public static function local_src() {
        return add_query_arg(
            array('action' => self::AJAX_ACTION, 'v' => ADSPIRIT_CONNECTOR_VERSION),
            admin_url('admin-ajax.php')
        );
    }

    /** URL de origem no CRM (fonte real + fallback do 302). */
    private static function origin_url() {
        $core = AdSpirit_Settings::get_core();
        $base = trim((string) ($core['endpoint_url'] ?? ''));
        $token = trim((string) ($core['pixel_token'] ?? ''));
        if (!$base || !$token) return '';
        return rtrim($base, '/') . '/pixel.js?t=' . rawurlencode($token);
    }

    public function serve() {
        $origin = self::origin_url();
        if ($origin === '') {
            status_header(404);
            exit;
        }

        $js = get_transient(self::TRANSIENT_CACHE);
        if (!is_string($js) || $js === '') {
            $resp = wp_remote_get($origin, array('timeout' => 8));
            if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200) {
                $body = (string) wp_remote_retrieve_body($resp);
                if ($body !== '') {
                    $js = $body;
                    set_transient(self::TRANSIENT_CACHE, $js, self::CACHE_TTL);
                    update_option(self::OPTION_LAST_GOOD, $js, false);
                }
            }
            // Stale-if-error: CRM indisponível → serve a última cópia boa.
            if (!is_string($js) || $js === '') {
                $stale = get_option(self::OPTION_LAST_GOOD, '');
                if (is_string($stale) && $stale !== '') $js = $stale;
            }
            // Sem nada em mãos: redirect pro CRM — nunca quebra o tracking.
            if (!is_string($js) || $js === '') {
                wp_redirect($origin, 302);
                exit;
            }
        }

        nocache_headers(); // limpa headers do admin-ajax…
        header('Content-Type: application/javascript; charset=utf-8');
        header('Cache-Control: public, max-age=3600'); // …e libera cache do browser
        header('X-Content-Type-Options: nosniff');
        echo $js; // phpcs:ignore WordPress.Security.EscapeOutput -- JS íntegro vindo do CRM próprio
        exit;
    }
}
