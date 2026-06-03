<?php
/**
 * Plugin Name:       AdSpirit Connector
 * Plugin URI:        https://crm.agenciadigitals.com.br
 * Description:       Conecta o site WordPress ao CRM AdSpirit (Digitals). CF7 real-time, anti-spam, field mapping, CAPI Meta, GA4 server-side, cross-domain decoration. Configurado via wp-admin.
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Agência Digitals
 * Author URI:        https://agenciadigitals.com.br
 * License:           Proprietary
 * Text Domain:       adspirit-connector
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) {
    exit;
}

define('ADSPIRIT_CONNECTOR_VERSION', '1.1.0');
define('ADSPIRIT_CONNECTOR_FILE', __FILE__);
define('ADSPIRIT_CONNECTOR_DIR', plugin_dir_path(__FILE__));
define('ADSPIRIT_CONNECTOR_URL', plugin_dir_url(__FILE__));

// Data layer + orquestrador
require_once ADSPIRIT_CONNECTOR_DIR . 'includes/class-adspirit-settings.php';
require_once ADSPIRIT_CONNECTOR_DIR . 'includes/class-adspirit-menu.php';
require_once ADSPIRIT_CONNECTOR_DIR . 'includes/class-adspirit-status.php';
require_once ADSPIRIT_CONNECTOR_DIR . 'includes/class-adspirit-health-checker.php';
require_once ADSPIRIT_CONNECTOR_DIR . 'includes/class-adspirit-logs.php';

// Features (cada feature = 1 classe com engine + tab UI)
require_once ADSPIRIT_CONNECTOR_DIR . 'includes/class-adspirit-cf7-handler.php';
require_once ADSPIRIT_CONNECTOR_DIR . 'includes/class-adspirit-anti-spam.php';
require_once ADSPIRIT_CONNECTOR_DIR . 'includes/class-adspirit-field-mapping.php';
require_once ADSPIRIT_CONNECTOR_DIR . 'includes/class-adspirit-pixel-injector.php';
require_once ADSPIRIT_CONNECTOR_DIR . 'includes/class-adspirit-capi-meta.php';
require_once ADSPIRIT_CONNECTOR_DIR . 'includes/class-adspirit-ga4.php';
require_once ADSPIRIT_CONNECTOR_DIR . 'includes/class-adspirit-cross-domain.php';

/**
 * Bootstrap on plugins_loaded — após CF7 carregar, garante hooks
 * registrados na ordem certa.
 */
function adspirit_connector_init() {
    AdSpirit_Settings::instance();
    AdSpirit_Menu::instance();
    AdSpirit_Status::instance();
    AdSpirit_Logs::instance();
    AdSpirit_Health_Checker::instance();

    AdSpirit_Anti_Spam::instance();
    AdSpirit_Field_Mapping::instance();
    AdSpirit_Cf7_Handler::instance();
    AdSpirit_Pixel_Injector::instance();
    AdSpirit_Capi_Meta::instance();
    AdSpirit_Ga4::instance();
    AdSpirit_Cross_Domain::instance();
}
add_action('plugins_loaded', 'adspirit_connector_init');

/**
 * Activation: garante row de options com defaults.
 */
function adspirit_connector_activate() {
    AdSpirit_Settings::seed_defaults();
}
register_activation_hook(__FILE__, 'adspirit_connector_activate');

/**
 * Deactivation: preserva config. Reativar = pronto pra usar.
 */
function adspirit_connector_deactivate() {
    // No-op por design.
}
register_deactivation_hook(__FILE__, 'adspirit_connector_deactivate');
