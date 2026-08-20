<?php
/**
 * AdSpirit Connector — eventos automáticos nomeados (padrão PixelYourSite).
 *
 * Enfileira assets/auto-events.js no front: tel_click, email_click,
 * whatsapp_click (+generate_lead) e file_download pro dataLayer, com
 * parâmetros ricos. Arquivo versionado e cacheável (direção "inline →
 * arquivos" do roadmap), carregado só com o plugin conectado.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Auto_Events {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action(
            'wp_enqueue_scripts',
            AdSpirit_Safe_Hook::action(array($this, 'enqueue'), 'auto_events_enqueue')
        );
    }

    public function enqueue() {
        if (is_admin()) return;
        if (!class_exists('AdSpirit_Settings')) return;
        $core = AdSpirit_Settings::get_core();
        // Sem conexão = site ainda em setup; não injeta nada.
        if (empty($core['brand_slug']) || empty($core['secret'])) return;

        wp_enqueue_script(
            'adspirit-auto-events',
            ADSPIRIT_CONNECTOR_URL . 'assets/auto-events.js',
            array(),
            ADSPIRIT_CONNECTOR_VERSION,
            true // footer
        );
    }
}
