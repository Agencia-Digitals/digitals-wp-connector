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
        // Servir o arquivo pelo domínio do site (anti ad-blocker). Ficou
        // suspenso de 2026-08-22 até 08-24: servido de outro endereço, o
        // pixel perdia token e destino e parava de medir. Resolvido com
        // config explícita — o script passa a receber os dois em vez de
        // deduzi-los da própria URL.
        //
        // A trava é automática: só liga quando o pixel.js DESTE CRM aceita
        // a config. Site apontando pra um CRM ainda não atualizado segue no
        // modo tradicional, que funciona. Ninguém quebra esperando deploy.
        $firstparty = ($settings['pixel_firstparty'] ?? '0') === '1'
            && class_exists('AdSpirit_Pixel_Proxy')
            && apply_filters('adspirit_pixel_firstparty_ok', AdSpirit_Pixel_Proxy::suporta_config());

        echo "\n<!-- AdSpirit Connector pixel -->\n";
        if ($firstparty) {
            // Config ANTES do script: sem ela o arquivo local não sabe pra
            // onde mandar. Os eventos seguem saindo do NAVEGADOR direto pro
            // CRM — só o .js muda de endereço. Nada passa pelo servidor
            // deste site, então IP real e header Origin ficam intactos.
            $cfg = wp_json_encode(array(
                'token'    => $token,
                'endpoint' => rtrim($base, '/') . '/api/track',
            ));
            echo '<script>window.AdSpiritPixel=' . $cfg . ';</script>' . "\n";
            echo '<script src="' . esc_url(AdSpirit_Pixel_Proxy::local_src()) . '" async></script>' . "\n";
            return;
        }
        echo '<script src="' . esc_url($base . '/pixel.js?t=' . urlencode($token)) . '" async></script>' . "\n";
    }
}
