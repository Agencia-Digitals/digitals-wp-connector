<?php
/**
 * AdSpirit Connector — coleta de telemetria.
 *
 * Coleta dados server-side (PHP/$_SERVER/WP context) + client-side
 * (via JS no wp_footer que popula window.__adspirit_t com browser data
 * + cookies). No submit do form, plugin merge os dois e adiciona ao
 * payload sob a key `_adspirit_telemetry`.
 *
 * Integra com o pixel: lê cookie `adspirit_vid` e inclui como
 * visitor_id, garantindo que o CRM faça stitching automático e herde
 * toda a jornada multi-touch.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Telemetry {
    private static $instance = null;
    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // v2.30 "inline → arquivos": os dois scripts (atribuição + coletor)
        // viraram assets/telemetry.js — cacheável, versionado, UM observer.
        // Comportamento byte-equivalente (cookies, hidden _adspirit_t_*,
        // global __adspirit_t que o qualifier lê). Config mínima inline.
        add_action(
            'wp_enqueue_scripts',
            AdSpirit_Safe_Hook::action(array($this, 'enqueue_assets'), 'telemetry_enqueue')
        );
    }

    /** Enfileira o arquivo de telemetria no front (footer) + whitelist. */
    public function enqueue_assets() {
        if (is_admin()) return;
        wp_enqueue_script(
            'adspirit-telemetry',
            ADSPIRIT_CONNECTOR_URL . 'assets/telemetry.js',
            array(),
            ADSPIRIT_CONNECTOR_VERSION,
            true
        );
        wp_add_inline_script(
            'adspirit-telemetry',
            'window.__adspiritTelemetryCfg={wl:' . wp_json_encode(self::attribution_params()) . '};',
            'before'
        );
    }

    /** Parâmetros de URL capturados pra atribuição (whitelist fixa). */
    public static function attribution_params() {
        return array(
            'gclid', 'gbraid', 'wbraid', 'fbclid', 'ttclid', 'msclkid',
            'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content',
        );
    }


    /**
     * Lê do $_POST tudo que o JS injetou + adiciona server-side data.
     * Retorna o payload de telemetria pra incluir no POST do CRM.
     */
    public static function collect_from_post($form_kind, $form_id, $referrer_url) {
        $get = function($k) {
            return isset($_POST['_adspirit_t_' . $k])
                ? sanitize_text_field((string) $_POST['_adspirit_t_' . $k])
                : '';
        };
        // UTM first/last chegam como JSON string ({source,medium,...}). Decode
        // pra array (null se ausente/inválido). wp_unslash antes do json_decode.
        $get_json = function($k) {
            if (!isset($_POST['_adspirit_t_' . $k])) return null;
            $raw = (string) wp_unslash($_POST['_adspirit_t_' . $k]);
            if ($raw === '') return null;
            $decoded = json_decode($raw, true);
            return is_array($decoded) ? $decoded : null;
        };

        $ua = isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
        $ip = self::client_ip();

        // Parse UA básico (sem lib externa)
        $ua_parsed = self::parse_ua($ua);

        $current_post = get_post();
        $current_user = wp_get_current_user();

        // Behavior summary (escrito pelo tracker JS em sessionStorage; injetado no
        // submit via hidden input _adspirit_t_behavior). Cap em 16KB (alinhado com
        // BEHAVIOR_PAYLOAD_MAX_BYTES no /api/track e default no admin).
        // Em falha de decode, ignora silenciosamente (não derruba o submit).
        $behavior_v1 = null;
        if (isset($_POST['_adspirit_t_behavior'])) {
            $raw_bhv = (string) wp_unslash($_POST['_adspirit_t_behavior']);
            if ($raw_bhv !== '' && strlen($raw_bhv) <= 16384) {
                $decoded = json_decode($raw_bhv, true);
                if (is_array($decoded)) {
                    $behavior_v1 = $decoded;
                }
            }
        }

        // P0-1: atribuição first/last-touch — cookies adspirit_ft/adspirit_lt
        // (gravados ungated pelo inject_attribution; ver comentário lá) chegam
        // como JSON nos hidden _adspirit_t_ft/_adspirit_t_lt e viram chaves
        // flat ft_*/lt_*. Aditivo: nenhuma chave pré-existente muda.
        $ft = self::attribution_from_post('_adspirit_t_ft', 'ft_');
        $lt = self::attribution_from_post('_adspirit_t_lt', 'lt_');

        $telemetry = array(
            // Linkage pixel
            'visitor_id' => $get('visitor_id'),
            'session_id' => $get('session_id'),

            // Identidade
            'client_ip' => $ip,
            'user_agent' => $ua,
            'ua_browser' => $ua_parsed['browser'],
            'ua_os' => $ua_parsed['os'],
            'ua_device' => $ua_parsed['device'],
            'locale' => $get('locale'),
            'timezone' => $get('timezone'),
            'color_scheme' => $get('color_scheme'),

            // Atribuição (cookies de plataforma)
            'fbp' => $get('fbp'),
            'fbc' => $get('fbc'),
            'ga' => $get('ga'),
            'gid' => $get('gid'),
            'gcl_au' => $get('gcl_au'),

            // Identidade de navegação (do _dos_attr do pixel) — carrega a
            // jornada pro CRM mesmo quando o cf7_url (referer) chega vazio.
            'landing_page' => $get('landing_page'),
            'conversion_page' => $get('conversion_page'),
            'referrer' => $get('referrer'),
            'first_seen_at' => $get('first_seen_at'),
            'last_seen_at' => $get('last_seen_at'),
            'utm_first' => $get_json('utm_first'),
            'utm_last' => $get_json('utm_last'),

            // Click IDs crus de mídia paga (os 6). Antes só vinham fbp/fbc/
            // gcl_au (cookies derivados) — sem o click id cru o CRM jogava
            // lead pago em "direto".
            'fbclid' => $get('fbclid'),
            'gclid' => $get('gclid'),
            'gbraid' => $get('gbraid'),
            'wbraid' => $get('wbraid'),
            'li_fat_id' => $get('li_fat_id'),
            'ttclid' => $get('ttclid'),

            // WordPress
            'wp_post_id' => $current_post ? (int) $current_post->ID : null,
            'wp_post_type' => $current_post ? $current_post->post_type : null,
            'wp_post_title' => $current_post ? $current_post->post_title : null,
            'wp_logged_user_email' => ($current_user && $current_user->ID) ? $current_user->user_email : null,

            // Server
            'wp_version' => get_bloginfo('version'),
            'php_version' => PHP_VERSION,
            'plugin_version' => ADSPIRIT_CONNECTOR_VERSION,

            // Behavior
            'time_on_page_ms' => intval($get('time_on_page_ms')),
            'time_in_form_ms' => intval($get('time_in_form_ms')),
            'fields_visited' => intval($get('fields_visited')),
            'pages_in_session' => intval($get('pages_in_session')),

            // Form context
            'form_kind' => $form_kind,
            'form_id' => $form_id,
            'cf7_url' => $referrer_url,

            // Extras
            'screen' => $get('screen'),
            'viewport' => $get('viewport'),
            'connection_type' => $get('connection_type'),

            // Behavior summary (v1) — emitido pelo tracker JS em sessionStorage.
            // null se ausente, inválido ou >16KB. CRM trata como opcional.
            'behavior_v1' => $behavior_v1,
        );

        // Atribuição first/last-touch (ft_*/lt_*) + aliases planos que o
        // Customer.io já lê (utm_*, referrer, landing_page) — valores do
        // last-touch; referrer/landing_page caem pro first-touch quando não
        // houve last-touch (visitante só orgânico).
        $telemetry = array_merge($telemetry, $ft, $lt, array(
            'utm_source'   => $lt['lt_utm_source'],
            'utm_medium'   => $lt['lt_utm_medium'],
            'utm_campaign' => $lt['lt_utm_campaign'],
            'utm_term'     => $lt['lt_utm_term'],
            'utm_content'  => $lt['lt_utm_content'],
            'referrer'     => $lt['lt_referrer'] !== '' ? $lt['lt_referrer'] : $ft['ft_referrer'],
            'landing_page' => $lt['lt_landing_url'] !== '' ? $lt['lt_landing_url'] : $ft['ft_landing_url'],
        ));

        return $telemetry;
    }

    /**
     * P0-1: decodifica um hidden de atribuição (_adspirit_t_ft/_adspirit_t_lt,
     * JSON gravado pelo inject_attribution) em chaves flat com prefixo
     * (ft_/lt_). Re-sanitiza server-side: whitelist fixa de chaves,
     * sanitize_text_field + cap de 200 chars por valor, JSON cru capado em
     * 4KB. Ausente/inválido → todas as chaves presentes com '' (mesmo
     * contrato dos demais campos de atribuição, ex. fbp/fbc).
     */
    private static function attribution_from_post($post_key, $prefix) {
        $allowed = array_merge(
            self::attribution_params(),
            array('referrer', 'landing_url', 'ts')
        );
        $out = array();
        foreach ($allowed as $k) {
            $out[$prefix . $k] = '';
        }
        if (!isset($_POST[$post_key])) return $out;
        $raw = (string) wp_unslash($_POST[$post_key]);
        if ($raw === '' || strlen($raw) > 4096) return $out;
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) return $out;
        foreach ($allowed as $k) {
            if (isset($decoded[$k]) && is_scalar($decoded[$k])) {
                $out[$prefix . $k] = substr(sanitize_text_field((string) $decoded[$k]), 0, 200);
            }
        }
        return $out;
    }

    /**
     * IP real do cliente — helper CANÔNICO do plugin (v2.30 unifica as 6
     * derivações que existiam espalhadas). Cascata: CF-Connecting-IP >
     * X-Forwarded-For (primeiro) > REMOTE_ADDR; '' quando nada existe.
     * Fica na Telemetry por ser classe sempre carregada cedo (linha 68 do
     * bootstrap) — NÃO mover sem revisar os delegantes (anti-spam, form,
     * lead-score, qualifier).
     */
    public static function client_ip() {
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
    }

    /**
     * Parse UA simples — sem regex monster. Suficiente pra 90% dos casos.
     */
    public static function parse_ua($ua) {
        $browser = 'unknown';
        $os = 'unknown';
        $device = 'desktop';

        if (stripos($ua, 'Edg/') !== false) $browser = 'Edge';
        elseif (stripos($ua, 'Chrome/') !== false) $browser = 'Chrome';
        elseif (stripos($ua, 'Safari/') !== false) $browser = 'Safari';
        elseif (stripos($ua, 'Firefox/') !== false) $browser = 'Firefox';
        elseif (stripos($ua, 'OPR/') !== false) $browser = 'Opera';

        if (stripos($ua, 'Windows') !== false) $os = 'Windows';
        elseif (stripos($ua, 'Mac OS') !== false) $os = 'macOS';
        elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iOS') !== false) $os = 'iOS';
        elseif (stripos($ua, 'Android') !== false) $os = 'Android';
        elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';

        if (stripos($ua, 'Mobile') !== false || stripos($ua, 'Android') !== false || stripos($ua, 'iPhone') !== false) {
            $device = 'mobile';
        } elseif (stripos($ua, 'iPad') !== false || stripos($ua, 'Tablet') !== false) {
            $device = 'tablet';
        }

        return array('browser' => $browser, 'os' => $os, 'device' => $device);
    }

    /**
     * Classifica email type: free (gmail/yahoo etc), corporate, role-based.
     */
    public static function classify_email($email) {
        $email = strtolower(trim((string) $email));
        if (!$email || strpos($email, '@') === false) return 'unknown';
        list($local, $domain) = explode('@', $email, 2);
        $role_prefixes = array('contato', 'info', 'admin', 'sac', 'comercial', 'marketing', 'vendas', 'atendimento', 'suporte', 'no-reply', 'noreply');
        foreach ($role_prefixes as $r) {
            if ($local === $r || strpos($local, $r) === 0) return 'role';
        }
        $free_domains = array('gmail.com', 'yahoo.com', 'yahoo.com.br', 'hotmail.com', 'outlook.com', 'live.com', 'icloud.com', 'uol.com.br', 'bol.com.br', 'terra.com.br', 'proton.me', 'protonmail.com');
        if (in_array($domain, $free_domains, true)) return 'free';
        return 'corporate';
    }
}
