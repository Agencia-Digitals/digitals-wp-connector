<?php
/**
 * AdSpirit Connector — pixel JS injection no <head>.
 *
 * Opt-in. Substitui colagem manual em theme/functions.php / Insert
 * Headers and Footers plugin.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Pixel_Injector {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action(
            'wp_head',
            AdSpirit_Safe_Hook::action(array($this, 'inject'), 'pixel_injector'),
            5
        );
    }

    public function inject() {
        $settings = AdSpirit_Settings::get_core();
        if (empty($settings['pixel_enabled']) || $settings['pixel_enabled'] !== '1') return;
        // AdSpirit_Pixel_Conflito segura a injeção quando a última varredura
        // viu o nosso script já presente por outra fonte — melhor não medir do
        // que medir em dobro.
        if (!apply_filters('adspirit_pixel_injector_deve_injetar', true)) return;
        $token = trim((string) ($settings['pixel_token'] ?? ''));
        $base  = trim((string) ($settings['endpoint_url'] ?? ''));
        if (!$token || !$base) return;
        // v2.29 opt-in: servir do próprio domínio (anti ad-blocker) via
        // proxy com cache — mesmo código, endereço first-party.
        $firstparty = ($settings['pixel_firstparty'] ?? '0') === '1'
            && class_exists('AdSpirit_Pixel_Proxy');
        $src = $firstparty
            ? esc_url(AdSpirit_Pixel_Proxy::local_src())
            : esc_url($base . '/pixel.js?t=' . urlencode($token));
        echo "\n<!-- AdSpirit Connector pixel -->\n";
        echo '<script src="' . $src . '" async></script>' . "\n";
    }
}
