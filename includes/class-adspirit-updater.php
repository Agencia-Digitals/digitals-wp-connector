<?php
/**
 * AdSpirit Connector — auto-update servido pelo próprio CRM.
 *
 * O CRM publica `/plugin/manifest.json` ({version, zip, updated_at}) e o zip
 * em `/plugin/adspirit-connector-latest.zip` (arquivos estáticos do Next,
 * escritos pelo build.sh do plugin). Este updater injeta a versão nova no
 * sistema NATIVO de updates do WordPress — o site mostra "Atualizar" na tela
 * de Plugins como qualquer plugin do diretório oficial.
 *
 * Por que o CRM e não o GitHub: o repo é privado, e token de leitura embutido
 * em plugin distribuído pra site de cliente é segredo vazado por design. Todo
 * site conectado já fala com o endpoint do CRM — é o servidor de update
 * natural, sem credencial nova.
 *
 * A URL do zip é montada a partir do endpoint_url salvo na conexão (não do
 * manifest), então ambientes de teste apontam pro próprio ambiente.
 *
 * Cache: manifest em transient de 12h (6h de backoff em falha). "Verificar
 * novamente" do wp-admin pode levar até esse prazo pra ver versão nova.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Updater {
    const CACHE_KEY = 'adspirit_connector_update_manifest';

    private static $instance = null;
    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_filter(
            'pre_set_site_transient_update_plugins',
            AdSpirit_Safe_Hook::filter(array($this, 'inject_update'), 'updater_inject')
        );
    }

    /** Manifest do CRM, com cache. null = indisponível (sem conexão/rede). */
    private function manifest() {
        $cached = get_transient(self::CACHE_KEY);
        if (is_array($cached)) {
            return empty($cached) ? null : $cached; // array vazio = backoff de falha
        }
        if (!class_exists('AdSpirit_Settings')) return null;
        $core = AdSpirit_Settings::get_core();
        if (empty($core['endpoint_url'])) return null;

        $resp = wp_remote_get(
            rtrim($core['endpoint_url'], '/') . '/plugin/manifest.json',
            array('timeout' => 8, 'headers' => array('Accept' => 'application/json'))
        );
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
            set_transient(self::CACHE_KEY, array(), 6 * HOUR_IN_SECONDS);
            return null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($data) || empty($data['version'])) {
            set_transient(self::CACHE_KEY, array(), 6 * HOUR_IN_SECONDS);
            return null;
        }
        set_transient(self::CACHE_KEY, $data, 12 * HOUR_IN_SECONDS);
        return $data;
    }

    /** Injeta a versão do CRM no transient de updates do WP. */
    public function inject_update($transient) {
        if (!is_object($transient)) return $transient;
        $m = $this->manifest();
        if (!$m || empty($m['version'])) return $transient;
        if (!version_compare((string) $m['version'], ADSPIRIT_CONNECTOR_VERSION, '>')) {
            return $transient;
        }

        $core = AdSpirit_Settings::get_core();
        if (empty($core['endpoint_url'])) return $transient;
        $zip = !empty($m['zip']) ? (string) $m['zip'] : 'adspirit-connector-latest.zip';
        $package = rtrim($core['endpoint_url'], '/') . '/plugin/' . rawurlencode($zip);

        $plugin_file = plugin_basename(ADSPIRIT_CONNECTOR_FILE);
        $transient->response[$plugin_file] = (object) array(
            'id'          => $plugin_file,
            'slug'        => 'adspirit-connector',
            'plugin'      => $plugin_file,
            'new_version' => (string) $m['version'],
            'url'         => rtrim($core['endpoint_url'], '/'),
            'package'     => $package,
        );
        return $transient;
    }
}
