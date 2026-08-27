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
                'style'         => in_array(($f['style'] ?? ''), array('multistep', 'single', 'chat', 'quiz'), true) ? $f['style'] : 'multistep',
                'steps'         => $steps,
                'destino'       => isset($f['destino']) && is_array($f['destino']) ? $f['destino'] : array(),
                'routing_rules' => isset($f['routing_rules']) && is_array($f['routing_rules']) ? $f['routing_rules'] : array(),
                // O AdSpirit diz qual formulário este site deve desenhar
                // quando o shortcode não nomeia nenhum. Só vale com roteiro
                // utilizável — marcar um form vazio não pode apagar o
                // formulário do site.
                'is_default'    => !empty($f['is_default']) && !empty($steps),
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

    /**
     * O formulário que o AdSpirit mandou este site desenhar, ou null.
     *
     * Existe pra trocar o formulário de um site sem editar página nenhuma —
     * a decisão de QUAL formulário o site usa é config de marca, e config de
     * marca mora no AdSpirit, não dentro do HTML de uma página.
     *
     * Precedência preservada: quem chama isto só consulta DEPOIS de não achar
     * roteiro salvo no próprio WordPress. Site que configurou o formulário na
     * mão não é sobrescrito de longe.
     *
     * O filtro `adspirit_central_form_padrao` devolve o controle ao site:
     * retornar null aqui congela o formulário local, aconteça o que
     * acontecer no CRM.
     */
    public static function default_form() {
        $encontrado = null;
        foreach (self::catalog() as $slug => $form) {
            if (!empty($form['is_default'])) { $encontrado = $slug; break; }
        }
        return apply_filters('adspirit_central_form_padrao', $encontrado);
    }

    private static function lastgood() {
        $lg = get_option(self::LASTGOOD, array());
        return is_array($lg) ? $lg : array();
    }

    /**
     * Sync bidirecional (2 vias): empurra um form deste site pra Central.
     * Fail-soft — CRM fora/antigo devolve false e o form segue 100%
     * funcional localmente. Sucesso derruba o cache do catálogo (o hub
     * mostra "sincronizado" na hora).
     *
     * $form = { slug, name, finalidade, style, steps (shape do roteiro),
     *           destino, routing_rules }
     */
    public static function push(array $form) {
        $core = AdSpirit_Settings::get_core();
        if (empty($core['endpoint_url']) || empty($core['brand_slug']) || empty($core['secret'])) {
            return false;
        }
        $body = array(
            'brand_slug'    => (string) $core['brand_slug'],
            'slug'          => sanitize_key((string) ($form['slug'] ?? '')),
            'name'          => sanitize_text_field((string) ($form['name'] ?? '')),
            'finalidade'    => (($form['finalidade'] ?? '') === 'nutricao') ? 'nutricao' : 'comercial',
            'style'         => in_array(($form['style'] ?? ''), array('multistep', 'single', 'chat', 'quiz'), true) ? $form['style'] : 'multistep',
            'steps'         => isset($form['steps']) && is_array($form['steps']) ? $form['steps'] : array(),
            'destino'       => isset($form['destino']) && is_array($form['destino']) ? $form['destino'] : new stdClass(),
            'routing_rules' => isset($form['routing_rules']) && is_array($form['routing_rules']) ? $form['routing_rules'] : array(),
        );
        if ($body['slug'] === '') return false;
        $resp = wp_remote_post(rtrim((string) $core['endpoint_url'], '/') . '/api/wp/forms', array(
            'timeout' => 8,
            'headers' => array(
                'Content-Type' => 'application/json; charset=utf-8',
                'x-cf7-secret' => (string) $core['secret'],
                'User-Agent'   => 'AdSpirit-Connector/' . (defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : ''),
            ),
            'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE),
        ));
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) {
            return false;
        }
        delete_transient(self::TRANSIENT);
        return true;
    }

    /** Converte um form do BUILDER (fields name/label) pro shape da Central
     *  (fields key/canonical, formato do roteiro). */
    public static function builder_to_central($fid, array $cfg) {
        $fields_out = array();
        $fields_in = isset($cfg['steps'][0]['fields']) && is_array($cfg['steps'][0]['fields'])
            ? $cfg['steps'][0]['fields'] : array();
        foreach ($fields_in as $f) {
            if (!is_array($f) || empty($f['name'])) continue;
            $fields_out[] = array(
                'key'         => sanitize_key((string) $f['name']),
                'type'        => in_array(($f['type'] ?? ''), array('text', 'email', 'tel', 'url', 'textarea'), true) ? $f['type'] : 'text',
                'placeholder' => sanitize_text_field((string) ($f['placeholder'] ?? ($f['label'] ?? ''))),
                'required'    => !empty($f['required']),
                'canonical'   => sanitize_text_field((string) $f['name']),
            );
        }
        $steps = array();
        if (!empty($fields_out)) {
            $steps[] = array('title' => (string) ($cfg['title'] ?? $fid), 'fields' => $fields_out);
        }
        return array(
            'slug'          => sanitize_key((string) $fid),
            'name'          => (string) ($cfg['title'] ?? $fid),
            'finalidade'    => (($cfg['finalidade'] ?? '') === 'nutricao') ? 'nutricao' : 'comercial',
            'style'         => 'single',
            'steps'         => $steps,
            'destino'       => array('success_message' => (string) ($cfg['success_message'] ?? '')),
            'routing_rules' => isset($cfg['routing_rules']) && is_array($cfg['routing_rules']) ? $cfg['routing_rules'] : array(),
        );
    }
}
