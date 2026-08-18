<?php
/**
 * AdSpirit Connector — catálogo da Central de Forms (Fase 1).
 *
 * O form vive no AdSpirit (/settings/formularios) e o site renderiza:
 * este módulo busca GET /api/wp/forms, cacheia 15 min e guarda a ÚLTIMA
 * CÓPIA BOA numa option — captura nunca depende do CRM estar de pé.
 *
 * Precedência (parede da migração): o shortcode SEM atributo continua
 * exatamente como sempre (roteiro local > embutido no JS). Um form da
 * Central só entra quando o shortcode pede explicitamente:
 * [adspirit_form_qualifier form="identificador"].
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Central_Forms {
    const TRANSIENT = 'adspirit_central_forms';
    const LASTGOOD = 'adspirit_central_forms_lastgood';

    /**
     * Catálogo de forms ativos da marca (array slug => form) ou array vazio.
     * Fail-soft: CRM antigo sem a rota (404) ou fora do ar → última cópia
     * boa; sem cópia → vazio (o shortcode cai no local/embutido).
     */
    public static function catalog() {
        $cached = get_transient(self::TRANSIENT);
        if (is_array($cached)) {
            return isset($cached['miss']) ? self::lastgood() : $cached;
        }
        $core = AdSpirit_Settings::get_core();
        if (empty($core['endpoint_url']) || empty($core['brand_slug']) || empty($core['secret'])) {
            return self::lastgood();
        }
        $url = rtrim((string) $core['endpoint_url'], '/')
            . '/api/wp/forms?brand_slug=' . rawurlencode((string) $core['brand_slug']);
        $resp = wp_remote_get($url, array(
            'timeout' => 6,
            'headers' => array(
                'x-cf7-secret' => (string) $core['secret'],
                'User-Agent'   => 'AdSpirit-Connector/' . (defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : ''),
            ),
        ));
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            set_transient(self::TRANSIENT, array('miss' => true), 300);
            return self::lastgood();
        }
        $body = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($body) || !isset($body['forms']) || !is_array($body['forms'])) {
            set_transient(self::TRANSIENT, array('miss' => true), 300);
            return self::lastgood();
        }
        $catalog = array();
        foreach ($body['forms'] as $f) {
            if (!is_array($f) || empty($f['slug'])) continue;
            $slug = sanitize_key((string) $f['slug']);
            // Re-sanitiza os steps com o MESMO validador do roteiro local
            // (defesa em profundidade — o runtime só recebe roteiro válido).
            $steps = array();
            if (isset($f['steps']) && is_array($f['steps']) && !empty($f['steps'])
                && class_exists('AdSpirit_Form_Qualifier')) {
                $res = AdSpirit_Form_Qualifier::sanitize_steps($f['steps']);
                if (is_array($res) && !empty($res['ok'])) $steps = $res['steps'];
            }
            $catalog[$slug] = array(
                'slug'          => $slug,
                'name'          => sanitize_text_field((string) ($f['name'] ?? $slug)),
                'finalidade'    => (($f['finalidade'] ?? '') === 'nutricao') ? 'nutricao' : 'comercial',
                'style'         => in_array(($f['style'] ?? ''), array('multistep', 'chat', 'quiz'), true) ? $f['style'] : 'multistep',
                'steps'         => $steps,
                'destino'       => isset($f['destino']) && is_array($f['destino']) ? $f['destino'] : array(),
                'routing_rules' => isset($f['routing_rules']) && is_array($f['routing_rules']) ? $f['routing_rules'] : array(),
            );
        }
        set_transient(self::TRANSIENT, $catalog, 900);
        update_option(self::LASTGOOD, $catalog, false);
        return $catalog;
    }

    /** Um form da Central pelo identificador ('' inválido → null). */
    public static function get($slug) {
        $slug = sanitize_key((string) $slug);
        if ($slug === '') return null;
        $catalog = self::catalog();
        return isset($catalog[$slug]) ? $catalog[$slug] : null;
    }

    private static function lastgood() {
        $lg = get_option(self::LASTGOOD, array());
        return is_array($lg) ? $lg : array();
    }
}
