<?php
/**
 * Plugin Name:       AdSpirit Connector
 * Plugin URI:        https://crm.agenciadigitals.com.br
 * Description:       Conecta o site WordPress ao CRM AdSpirit (Digitals). CF7 real-time, anti-spam, field mapping, CAPI Meta, GA4 server-side, cross-domain decoration. Configurado via wp-admin.
 * Version:           2.70.0
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

define('ADSPIRIT_CONNECTOR_VERSION', '2.70.0');
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
adspirit_connector_safe_require('includes/class-adspirit-tags.php');
adspirit_connector_safe_require('includes/class-adspirit-capi-meta.php');
adspirit_connector_safe_require('includes/class-adspirit-ga4.php');
adspirit_connector_safe_require('includes/class-adspirit-cross-domain.php');
adspirit_connector_safe_require('includes/class-adspirit-lgpd-popup.php');
adspirit_connector_safe_require('includes/class-adspirit-quickwins.php');
adspirit_connector_safe_require('includes/class-adspirit-performance.php');
adspirit_connector_safe_require('includes/class-adspirit-form.php');
adspirit_connector_safe_require('includes/class-adspirit-form-adapters.php');
adspirit_connector_safe_require('includes/class-adspirit-integrations.php');
// v2.1 features
adspirit_connector_safe_require('includes/class-adspirit-ab-test.php');
adspirit_connector_safe_require('includes/class-adspirit-customerio.php');
adspirit_connector_safe_require('includes/class-adspirit-mailchimp.php');
adspirit_connector_safe_require('includes/class-adspirit-lead-score.php');
adspirit_connector_safe_require('includes/class-adspirit-field-mapping-sync.php');
adspirit_connector_safe_require('includes/class-adspirit-ambiente.php');
adspirit_connector_safe_require('includes/class-adspirit-agente.php');
adspirit_connector_safe_require('includes/class-adspirit-agente-conexao.php');
adspirit_connector_safe_require('includes/class-adspirit-fontes.php');
adspirit_connector_safe_require('includes/class-adspirit-medicao.php');
adspirit_connector_safe_require('includes/class-adspirit-deteccao.php');
adspirit_connector_safe_require('includes/class-adspirit-handshake.php');
adspirit_connector_safe_require('includes/class-adspirit-conexao-painel.php');
adspirit_connector_safe_require('includes/class-adspirit-config-sync.php');
adspirit_connector_safe_require('includes/class-adspirit-pixel-conflito.php');
adspirit_connector_safe_require('includes/class-adspirit-magic-install.php');
// v2.2 adapters
adspirit_connector_safe_require('includes/class-adspirit-behavioral.php');
adspirit_connector_safe_require('includes/class-adspirit-clarity.php');
// v2.3 form qualifier
adspirit_connector_safe_require('includes/class-adspirit-form-qualifier.php');
// v2.5 whatsapp popup + thank you redirect com tracking server-side
adspirit_connector_safe_require('includes/class-adspirit-whatsapp.php');
adspirit_connector_safe_require('includes/class-adspirit-thank-you.php');
// v2.6 submissions log (substituto local do TablePress)
adspirit_connector_safe_require('includes/class-adspirit-submissions-log.php');
// v2.11 lead store (persistência durável de submissões + reenvio)
adspirit_connector_safe_require('includes/class-adspirit-recursos.php');
adspirit_connector_safe_require('includes/class-adspirit-payload-view.php');
adspirit_connector_safe_require('includes/class-adspirit-lead-store.php');
// v2.8 setup wizard (checklist visual de configuração)
adspirit_connector_safe_require('includes/class-adspirit-setup-wizard.php');
// v2.10 cloudflare turnstile (anti-bot invisível)
adspirit_connector_safe_require('includes/class-adspirit-turnstile.php');
adspirit_connector_safe_require('includes/class-adspirit-generic-collector.php');
adspirit_connector_safe_require('includes/class-adspirit-lead-identity.php');
adspirit_connector_safe_require('includes/class-adspirit-central-forms.php');
adspirit_connector_safe_require('includes/class-adspirit-forms-hub.php');
adspirit_connector_safe_require('includes/class-adspirit-thankyou-setup.php');
// v2.28 widget de leads no dashboard do WP (presença diária + deep-link CRM)
adspirit_connector_safe_require('includes/class-adspirit-dashboard-widget.php');
// v2.29 eventos automáticos nomeados (tel/email/whatsapp/download → dataLayer)
adspirit_connector_safe_require('includes/class-adspirit-auto-events.php');
// v2.29 pixel first-party (proxy com cache, anti ad-blocker; opt-in)
adspirit_connector_safe_require('includes/class-adspirit-pixel-proxy.php');
// Absorvidos de plugins avulsos: log de e-mails (WP Mail Log) e utilitários de
// desenvolvimento (Duplicate Page, Post Type Switcher, Download Plugin).
adspirit_connector_safe_require('includes/class-adspirit-mail-log.php');
adspirit_connector_safe_require('includes/class-adspirit-devtools.php');
// Perfil do pacote (cliente|estudio), gravado pelo build-perfil.sh. Ausente
// significa instalação manual/desenvolvimento: assume estúdio.
if (file_exists(__DIR__ . '/includes/perfil.php')) { require_once __DIR__ . '/includes/perfil.php'; }
if (!defined('ADSPIRIT_PERFIL')) { define('ADSPIRIT_PERFIL', 'estudio'); }
adspirit_connector_safe_require('includes/class-adspirit-whitelabel.php');

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
    if (class_exists('AdSpirit_Performance'))    AdSpirit_Performance::instance();
    if (class_exists('AdSpirit_Mail_Log'))       AdSpirit_Mail_Log::instance();
    if (class_exists('AdSpirit_DevTools'))       AdSpirit_DevTools::instance();
    if (class_exists('AdSpirit_WhiteLabel'))     AdSpirit_WhiteLabel::instance();
    if (class_exists('AdSpirit_Form'))           AdSpirit_Form::instance();
    if (class_exists('AdSpirit_Form_Adapters'))  AdSpirit_Form_Adapters::instance();
    if (class_exists('AdSpirit_Integrations'))   AdSpirit_Integrations::instance();
    // v2.1 features
    if (class_exists('AdSpirit_Ab_Test'))            AdSpirit_Ab_Test::instance();
    if (class_exists('AdSpirit_Customerio'))         AdSpirit_Customerio::instance();
    if (class_exists('AdSpirit_Mailchimp'))          AdSpirit_Mailchimp::instance();
    if (class_exists('AdSpirit_Lead_Score'))         AdSpirit_Lead_Score::instance();
    if (class_exists('AdSpirit_Field_Mapping_Sync')) AdSpirit_Field_Mapping_Sync::instance();
    if (class_exists('AdSpirit_Config_Sync')) AdSpirit_Config_Sync::instance();
    if (class_exists('AdSpirit_Pixel_Conflito')) AdSpirit_Pixel_Conflito::instance();
    // Operações do agente: existem em todo site, trancadas por quem chama.
    if (class_exists('AdSpirit_Agente')) AdSpirit_Agente::instance();
    if (class_exists('AdSpirit_Agente_Conexao')) AdSpirit_Agente_Conexao::instance();
    if (class_exists('AdSpirit_Tags')) AdSpirit_Tags::instance();
    if (class_exists('AdSpirit_Fontes')) AdSpirit_Fontes::instance();
    if (class_exists('AdSpirit_Medicao')) AdSpirit_Medicao::instance();
    if (class_exists('AdSpirit_Conexao_Painel')) AdSpirit_Conexao_Painel::instance();

    // Ferramentas de manutenção e construção.
    //
    // Carregam em QUALQUER domínio, inclusive no site do cliente — é o que
    // permite corrigir coisa em produção pelo agente. O que protege não é a
    // ausência do código, é a tranca de quem chama: toda operação exige uma
    // pessoa da Digitals com permissão de administrar
    // (AdSpirit_Ambiente::pode_operar_pelo_agente).
    //
    // A ABA, essa sim, só aparece em endereço nosso: no painel do cliente ela
    // seria ruído sobre um trabalho que não é dele.
    adspirit_connector_safe_require('includes/estudio/class-digitals-studio-oxygen.php');
    if (class_exists('Digitals_Studio_Oxygen')) new Digitals_Studio_Oxygen();

    if (class_exists('AdSpirit_Ambiente') && AdSpirit_Ambiente::e_estudio()) {
        adspirit_connector_safe_require('includes/estudio/class-digitals-studio-aba.php');
        if (class_exists('Digitals_Studio_Aba')) Digitals_Studio_Aba::instance();
    }
    // v2.2 adapters
    if (class_exists('AdSpirit_Behavioral')) AdSpirit_Behavioral::instance();
    if (class_exists('AdSpirit_Clarity'))    AdSpirit_Clarity::instance();
    // v2.3 form qualifier
    if (class_exists('AdSpirit_Form_Qualifier')) AdSpirit_Form_Qualifier::instance();
    // v2.5 whatsapp + thank you shortcodes
    if (class_exists('AdSpirit_WhatsApp'))  AdSpirit_WhatsApp::instance();
    if (class_exists('AdSpirit_Thank_You')) AdSpirit_Thank_You::instance();
    // v2.6 submissions log
    if (class_exists('AdSpirit_Submissions_Log')) AdSpirit_Submissions_Log::instance();
    // v2.11 lead store (persistência durável — cria/migra tabela on-load)
    if (class_exists('AdSpirit_Lead_Store')) AdSpirit_Lead_Store::instance();
    // v2.8 setup wizard
    if (class_exists('AdSpirit_Setup_Wizard')) AdSpirit_Setup_Wizard::instance();
    // v2.10 cloudflare turnstile
    if (class_exists('AdSpirit_Turnstile')) AdSpirit_Turnstile::instance();
    if (class_exists('AdSpirit_Generic_Collector')) AdSpirit_Generic_Collector::instance();
    if (class_exists('AdSpirit_Lead_Identity')) AdSpirit_Lead_Identity::instance();
    if (class_exists('AdSpirit_Forms_Hub')) AdSpirit_Forms_Hub::instance();
    if (class_exists('AdSpirit_ThankYou_Setup')) AdSpirit_ThankYou_Setup::instance();
    // v2.28 widget de leads no dashboard
    if (class_exists('AdSpirit_Dashboard_Widget')) AdSpirit_Dashboard_Widget::instance();
    // v2.29 eventos automáticos nomeados
    if (class_exists('AdSpirit_Auto_Events')) AdSpirit_Auto_Events::instance();
    // v2.29 pixel first-party
    if (class_exists('AdSpirit_Pixel_Proxy')) AdSpirit_Pixel_Proxy::instance();
}

/**
 * SEMPRE-on: magic-link install precisa rodar mesmo em Safe Mode pra
 * completar onboarding. Registrado em hook separado a parte da feature
 * activation (que pode ser desligada por Safe Mode).
 */
function adspirit_connector_init_always() {
    if (class_exists('AdSpirit_Magic_Install')) AdSpirit_Magic_Install::instance();
}
add_action('plugins_loaded', 'adspirit_connector_init_always', 5);
add_action('plugins_loaded', 'adspirit_connector_init');

/**
 * Auto-purge de cache na troca de versao. Incidente 2026-08-15: update do
 * plugin trocou o PHP (?ver= novo no HTML) mas o LiteSpeed seguiu servindo o
 * CORPO antigo de qualifier-form.js (max-age=1000000, cache de estatico que
 * ignora query string). "Atualizei e nao mudou nada." Detecta a troca de
 * versao no boot (cobre update via GitHub, onde o activation hook NAO roda)
 * e purga os caches conhecidos — fail-soft.
 */
function adspirit_connector_maybe_purge_caches() {
    $stored = get_option('adspirit_connector_version', '');
    if ($stored === ADSPIRIT_CONNECTOR_VERSION) {
        return;
    }
    update_option('adspirit_connector_version', ADSPIRIT_CONNECTOR_VERSION, false);
    if ($stored === '') {
        return; // instalacao nova — nada do plugin cacheado ainda
    }
    try {
        if (has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all'); // LiteSpeed (o dos nossos sites)
        }
        if (function_exists('rocket_clean_domain')) {
            rocket_clean_domain(); // WP Rocket
        }
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all(); // W3 Total Cache
        }
        if (function_exists('wp_cache_clear_cache')) {
            wp_cache_clear_cache(); // WP Super Cache
        }
        if (class_exists('autoptimizeCache') && method_exists('autoptimizeCache', 'clearall')) {
            autoptimizeCache::clearall(); // Autoptimize (cache proprio de JS)
        }
    } catch (Throwable $e) {
        // purge e cortesia — nunca derruba o boot do plugin
    }
}
add_action('plugins_loaded', 'adspirit_connector_maybe_purge_caches', 20);

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
    // v2.11: cria a tabela de submissões duráveis. Em update via GitHub o
    // activation hook NÃO roda — o maybe_install() no boot cobre esse caso.
    if (class_exists('AdSpirit_Lead_Store')) {
        AdSpirit_Lead_Store::install();
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
    // Settings preservadas por design. Só desagenda o cron de retry do
    // Lead Store (P0-3) — reagendado automaticamente no próximo boot se
    // o plugin for reativado.
    if (class_exists('AdSpirit_Lead_Store')) {
        AdSpirit_Lead_Store::unschedule();
    }
    if (class_exists('AdSpirit_Fontes')) {
        AdSpirit_Fontes::unschedule();
    }
}
register_deactivation_hook(__FILE__, 'adspirit_connector_deactivate');
