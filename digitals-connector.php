<?php
/**
 * Plugin Name:       AdSpirit Connector
 * Plugin URI:        https://crm.agenciadigitals.com.br
 * Description:       Conecta o site WordPress ao CRM AdSpirit (Digitals). CF7 real-time, anti-spam, field mapping, CAPI Meta, GA4 server-side, cross-domain decoration. Configurado via wp-admin.
 * Version:           2.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Tested up to:      6.7
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

define('ADSPIRIT_CONNECTOR_VERSION', '2.0.0');
define('ADSPIRIT_CONNECTOR_FILE', __FILE__);
define('ADSPIRIT_CONNECTOR_DIR', plugin_dir_path(__FILE__));
define('ADSPIRIT_CONNECTOR_URL', plugin_dir_url(__FILE__));

/**
 * Carrega arquivos com guarda. Se algum file estiver corrompido / faltando,
 * loga + impede o boot — não tenta usar classe inexistente.
 */
function adspirit_connector_safe_require($file) {
    $path = ADSPIRIT_CONNECTOR_DIR . $file;
    if (!is_readable($path)) {
        error_log('[AdSpirit Connector] arquivo ausente: ' . $file);
        return false;
    }
    try {
        require_once $path;
        return true;
    } catch (\Throwable $e) {
        error_log('[AdSpirit Connector] falha carregando ' . $file . ': ' . $e->getMessage());
        return false;
    }
}

// Safety net layer — carrega ANTES de qualquer feature
$_adspirit_safety_loaded =
    adspirit_connector_safe_require('includes/class-adspirit-safe-bootstrap.php') &&
    adspirit_connector_safe_require('includes/class-adspirit-crash-tracker.php') &&
    adspirit_connector_safe_require('includes/class-adspirit-safe-hook.php');

if (!$_adspirit_safety_loaded) {
    error_log('[AdSpirit Connector] safety layer não carregou — plugin não vai iniciar');
    return; // sai do file sem registrar nenhum hook
}

// Inicia crash tracker ANTES de tudo — captura fatal em qualquer file abaixo
AdSpirit_Crash_Tracker::instance();

// Carrega o resto. Cada require é independente — se um falhar, outros podem
// continuar.
adspirit_connector_safe_require('includes/class-adspirit-settings.php');
adspirit_connector_safe_require('includes/class-adspirit-menu.php');
adspirit_connector_safe_require('includes/class-adspirit-connect.php');
adspirit_connector_safe_require('includes/class-adspirit-status.php');
adspirit_connector_safe_require('includes/class-adspirit-health-checker.php');
adspirit_connector_safe_require('includes/class-adspirit-logs.php');
adspirit_connector_safe_require('includes/class-adspirit-telemetry.php');
adspirit_connector_safe_require('includes/class-adspirit-cf7-handler.php');
adspirit_connector_safe_require('includes/class-adspirit-anti-spam.php');
adspirit_connector_safe_require('includes/class-adspirit-field-mapping.php');
adspirit_connector_safe_require('includes/class-adspirit-pixel-injector.php');
adspirit_connector_safe_require('includes/class-adspirit-capi-meta.php');
adspirit_connector_safe_require('includes/class-adspirit-ga4.php');
adspirit_connector_safe_require('includes/class-adspirit-cross-domain.php');
adspirit_connector_safe_require('includes/class-adspirit-lgpd-popup.php');
adspirit_connector_safe_require('includes/class-adspirit-quickwins.php');
adspirit_connector_safe_require('includes/class-adspirit-form.php');
adspirit_connector_safe_require('includes/class-adspirit-form-adapters.php');
adspirit_connector_safe_require('includes/class-adspirit-integrations.php');

/**
 * Bootstrap on plugins_loaded.
 *
 * UI (menu/status/settings/logs) SEMPRE inicializa — admin precisa acessar
 * pra ver Safe Mode e sair dele se necessário.
 *
 * Features (CF7 dispatch, pixel injection, CAPI, GA4, etc) só sobem se
 * can_run() == true (version OK + Safe Mode off).
 */
function adspirit_connector_init() {
    // Sempre inicializa
    if (class_exists('AdSpirit_Settings')) AdSpirit_Settings::instance();
    if (class_exists('AdSpirit_Menu'))     AdSpirit_Menu::instance();
    if (class_exists('AdSpirit_Connect'))  AdSpirit_Connect::instance();
    if (class_exists('AdSpirit_Status'))   AdSpirit_Status::instance();
    if (class_exists('AdSpirit_Logs'))     AdSpirit_Logs::instance();
    if (class_exists('AdSpirit_Health_Checker')) AdSpirit_Health_Checker::instance();

    // Só inicializa features ativas se podemos rodar
    if (!AdSpirit_Safe_Bootstrap::can_run()) {
        return;
    }

    if (class_exists('AdSpirit_Anti_Spam'))      AdSpirit_Anti_Spam::instance();
    if (class_exists('AdSpirit_Telemetry'))      AdSpirit_Telemetry::instance();
    if (class_exists('AdSpirit_Field_Mapping'))  AdSpirit_Field_Mapping::instance();
    if (class_exists('AdSpirit_Cf7_Handler'))    AdSpirit_Cf7_Handler::instance();
    if (class_exists('AdSpirit_Pixel_Injector')) AdSpirit_Pixel_Injector::instance();
    if (class_exists('AdSpirit_Capi_Meta'))      AdSpirit_Capi_Meta::instance();
    if (class_exists('AdSpirit_Ga4'))            AdSpirit_Ga4::instance();
    if (class_exists('AdSpirit_Cross_Domain'))   AdSpirit_Cross_Domain::instance();
    if (class_exists('AdSpirit_Lgpd_Popup'))     AdSpirit_Lgpd_Popup::instance();
    if (class_exists('AdSpirit_Quickwins'))      AdSpirit_Quickwins::instance();
    if (class_exists('AdSpirit_Form'))           AdSpirit_Form::instance();
    if (class_exists('AdSpirit_Form_Adapters'))  AdSpirit_Form_Adapters::instance();
    if (class_exists('AdSpirit_Integrations'))   AdSpirit_Integrations::instance();
}
add_action('plugins_loaded', 'adspirit_connector_init');

/**
 * Activation: valida ambiente. Se PHP/WP for incompatível, plugin se
 * desativa sozinho — site fica intocado.
 */
function adspirit_connector_activate() {
    if (class_exists('AdSpirit_Safe_Bootstrap')) {
        AdSpirit_Safe_Bootstrap::validate_for_activation();
    }
    if (class_exists('AdSpirit_Settings')) {
        AdSpirit_Settings::seed_defaults();
    }
    // Reset de safe mode na ativação — nova instalação começa limpa
    if (class_exists('AdSpirit_Safe_Bootstrap')) {
        AdSpirit_Safe_Bootstrap::exit_safe_mode();
    }
    if (class_exists('AdSpirit_Crash_Tracker')) {
        AdSpirit_Crash_Tracker::clear_log();
    }
}
register_activation_hook(__FILE__, 'adspirit_connector_activate');

/**
 * Deactivation: preserva config. Reativar = pronto pra usar.
 */
function adspirit_connector_deactivate() {
    // No-op por design — settings preservadas
}
register_deactivation_hook(__FILE__, 'adspirit_connector_deactivate');
