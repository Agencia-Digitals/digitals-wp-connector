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
        $token = trim((string) ($settings['pixel_token'] ?? ''));
        $base  = trim((string) ($settings['endpoint_url'] ?? ''));
        if (!$token || !$base) return;
        $src = esc_url($base . '/pixel.js?t=' . urlencode($token));
        echo "\n<!-- AdSpirit Connector pixel -->\n";
        echo '<script src="' . $src . '" async></script>' . "\n";
    }
}
