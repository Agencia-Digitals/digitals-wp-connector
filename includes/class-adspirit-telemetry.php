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
        add_action(
            'wp_footer',
            AdSpirit_Safe_Hook::action(array($this, 'inject_collector'), 'telemetry_collector'),
            99
        );
        // P0-1: captura de atribuição (gclid/UTM → cookies first/last-touch)
        // + emissão dos hidden _adspirit_t_ft/_adspirit_t_lt. Prioridade 98:
        // roda antes do collector. Gate de consent: ver inject_attribution().
        add_action(
            'wp_footer',
            AdSpirit_Safe_Hook::action(array($this, 'inject_attribution'), 'telemetry_attribution'),
            98
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
     * P0-1 — Captura de atribuição first/last-touch (client-side).
     *
     * Lê da URL apenas a whitelist de attribution_params(), sanitiza
     * (trim, 200 chars, sem <>"') e persiste em dois cookies first-party
     * (90 dias, path=/, SameSite=Lax):
     *   - adspirit_ft (first-touch): gravado UMA vez, nunca sobrescrito.
     *     Gravado já na primeira visita mesmo sem parâmetros (first-touch
     *     orgânico: referrer + landing_url + ts).
     *   - adspirit_lt (last-touch): sobrescrito sempre que a visita atual
     *     trouxer >=1 parâmetro da whitelist.
     * Em seguida injeta os hidden _adspirit_t_ft/_adspirit_t_lt (JSON) nos
     * mesmos forms que o collector cobre; o valor é setado na CRIAÇÃO do
     * input (cookie é estático no pageload), imune à ordem dos listeners de
     * submit (o form nativo constrói FormData no primeiro listener).
     *
     * CONSENT: intencionalmente SEM gate de has_telemetry_consent(), seguindo
     * o padrão do pixel injector (pixel.js/adspirit_vid também roda ungated).
     * Motivo: o cookie adspirit_consent só nasce client-side após interação,
     * então o gate PHP sempre falha no PRIMEIRO pageview — exatamente onde o
     * gclid chega; gated, a atribuição de tráfego pago se perderia. Só
     * parâmetros de URL + referrer (sem PII, sem fingerprinting). Decisão a
     * revisitar no trabalho de LGPD (consentimento com recusa real).
     */
    public function inject_attribution() {
        if (is_admin()) return;
        ?>
        <script>
        (function() {
          try {
            var WL = <?php echo wp_json_encode(self::attribution_params()); ?>;
            function clean(v) {
              return String(v || '').replace(/[<>"']/g, '').trim().slice(0, 200);
            }
            function readCookie(name) {
              var m = document.cookie.match('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\\/+^])/g, '\\$1') + '=([^;]*)');
              return m ? decodeURIComponent(m[1]) : '';
            }
            function writeCookie(name, value) {
              document.cookie = name + '=' + encodeURIComponent(value)
                + ';max-age=' + (90 * 86400) + ';path=/;samesite=lax';
            }
            var params = {}, has = false;
            try {
              var sp = new URLSearchParams(window.location.search);
              WL.forEach(function(k) {
                var v = sp.get(k);
                if (v) { params[k] = clean(v); has = true; }
              });
            } catch (e) {}

            function touch() {
              var t = {};
              for (var k in params) t[k] = params[k];
              t.referrer = clean(document.referrer);
              t.landing_url = clean(window.location.href.split('#')[0]);
              t.ts = new Date().toISOString();
              return JSON.stringify(t);
            }
            // first-touch: gravado uma vez, nunca sobrescrito
            if (!readCookie('adspirit_ft')) writeCookie('adspirit_ft', touch());
            // last-touch: só atualiza quando a visita traz parâmetro novo
            if (has) writeCookie('adspirit_lt', touch());

            // Emissão: hidden _adspirit_t_ft/_adspirit_t_lt (JSON) nos mesmos
            // forms do collector. Server-side re-sanitiza (whitelist + cap).
            function attach() {
              document.querySelectorAll('form.wpcf7-form, form.adspirit-form, .gform_wrapper form, form.wpforms-form').forEach(function(form) {
                if (form.dataset.adspiritAttrAttached) return;
                form.dataset.adspiritAttrAttached = '1';
                [['_adspirit_t_ft', 'adspirit_ft'], ['_adspirit_t_lt', 'adspirit_lt']].forEach(function(pair) {
                  var input = document.createElement('input');
                  input.type = 'hidden';
                  input.name = pair[0];
                  input.value = readCookie(pair[1]) || '';
                  form.appendChild(input);
                });
              });
            }
            if (document.readyState === 'loading') {
              document.addEventListener('DOMContentLoaded', attach);
            } else {
              attach();
            }
            if (typeof MutationObserver !== 'undefined') {
              new MutationObserver(attach).observe(document.body, { childList: true, subtree: true });
            }
          } catch (e) { /* silenciado */ }
        })();
        </script>
        <?php
    }

    /**
     * JS coleta browser data e popula campos hidden em todos os forms
     * relevantes (CF7, Gravity, etc) com prefix `_adspirit_t_*`.
     * Plugin server-side lê esses campos no submit.
     */
    public function inject_collector() {
        if (is_admin()) return;

        // Coletor injetado SEMPRE (base legal: legítimo interesse; consistente
        // com o pixel, que já carrega sem gate). Antes era travado em
        // has_telemetry_consent() NO RENDER, causando 2 bugs: (a) no 1º acesso
        // (sem cookie de consent) o coletor nem aparecia → CF7 ia sem
        // visitor_id; (b) pra ter consent precisava do reload do banner, que
        // apagava o formulário. Os dados só são ENVIADOS no submit (ação
        // deliberada do visitante). §126.

        ?>
        <script>
        (function() {
          try {
            var t = window.__adspirit_t = window.__adspirit_t || {};
            t.start_ts = t.start_ts || Date.now();
            t.locale = navigator.language || '';
            t.timezone = (Intl && Intl.DateTimeFormat && Intl.DateTimeFormat().resolvedOptions().timeZone) || '';
            t.color_scheme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
            t.screen = (window.screen ? (window.screen.width + 'x' + window.screen.height) : '');
            t.viewport = (window.innerWidth + 'x' + window.innerHeight);
            t.connection_type = (navigator.connection && navigator.connection.effectiveType) || '';

            function cookie(name) {
              var m = document.cookie.match('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\\/+^])/g, '\\$1') + '=([^;]*)');
              return m ? decodeURIComponent(m[1]) : '';
            }
            // O pixel do CRM grava _dosvi/_dossi (NÃO adspirit_vid). Ler o nome
            // errado deixava visitor_id vazio em 100% dos leads e quebrava todo
            // o stitching lead↔jornada↔canal. Fallback ao nome legado por
            // segurança (instalações antigas).
            t.visitor_id = cookie('_dosvi') || cookie('adspirit_vid') || '';
            t.session_id = cookie('_dossi') || cookie('adspirit_sid') || '';
            // Atribuição first-party mantida pelo pixel em localStorage
            // (_dos_attr): primeiro+último toque (UTM/landing/referrer) + os 6
            // click ids de mídia paga. Resolve o cf7_url vazio (~63% na prod).
            function readPixelAttr() {
              try {
                if (window.dos && typeof window.dos.getAttribution === 'function') {
                  return window.dos.getAttribution() || {};
                }
                return JSON.parse(localStorage.getItem('_dos_attr') || '{}') || {};
              } catch (e) { return {}; }
            }
            t.fbp = cookie('_fbp');
            t.fbc = cookie('_fbc');
            t.ga = cookie('_ga');
            t.gid = cookie('_gid');
            t.gcl_au = cookie('_gcl_au');

            // Tempo no form (focus no primeiro campo até submit)
            t.form_focus_ts = null;
            t.fields_visited = 0;
            var visited = {};
            document.addEventListener('focusin', function(e) {
              var el = e.target;
              if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT')) {
                if (!t.form_focus_ts) t.form_focus_ts = Date.now();
                if (el.name && !visited[el.name]) {
                  visited[el.name] = true;
                  t.fields_visited++;
                }
              }
            });

            // Last 5 pages visitadas nesta sessão (via sessionStorage)
            try {
              var history = JSON.parse(sessionStorage.getItem('adspirit_history') || '[]');
              t.pages_in_session = history.length;
            } catch(e) {}

            // Behavior summary (escrito pelo behavior tracker em sessionStorage)
            // Lido como JSON cru aqui e ressubmetido como string no submit.
            function readBehavior() {
              try {
                var raw = sessionStorage.getItem('adspirit_bhv_v1') || '';
                if (!raw) return '';
                // cap em 16KB (alinhado com server-side BEHAVIOR_PAYLOAD_MAX_BYTES)
                if (raw.length > 16384) return '';
                return raw;
              } catch(e) { return ''; }
            }

            // Hook em CF7 submit: popula campos hidden com a telemetria
            // antes de enviar, pra plugin server-side ler dos $_POST.
            function attachHidden() {
              document.querySelectorAll('form.wpcf7-form, form.adspirit-form, .gform_wrapper form, form.wpforms-form').forEach(function(form) {
                if (form.dataset.adspiritAttached) return;
                form.dataset.adspiritAttached = '1';
                var fields = ['visitor_id', 'session_id', 'fbp', 'fbc', 'ga', 'gid', 'gcl_au',
                              'locale', 'timezone', 'color_scheme', 'screen', 'viewport', 'connection_type',
                              // Identidade de navegação (lida do _dos_attr do pixel no submit).
                              'landing_page', 'conversion_page', 'referrer', 'first_seen_at', 'last_seen_at',
                              'utm_first', 'utm_last',
                              'fbclid', 'gclid', 'gbraid', 'wbraid', 'li_fat_id', 'ttclid'];
                fields.forEach(function(name) {
                  var input = document.createElement('input');
                  input.type = 'hidden';
                  input.name = '_adspirit_t_' + name;
                  input.value = '';
                  form.appendChild(input);
                });
                // Time on page + time in form + fields visited preenchidos no submit
                form.addEventListener('submit', function() {
                  // Snapshot da atribuição do pixel NO MOMENTO do submit (o pixel
                  // atualiza _dos_attr de forma assíncrona após o load).
                  var attr = readPixelAttr();
                  var ci = attr.click_ids || {};
                  t.landing_page = attr.landing_page || '';
                  t.conversion_page = location.href;
                  t.referrer = (attr.first_referrer != null ? attr.first_referrer : (document.referrer || ''));
                  t.first_seen_at = attr.first_seen_at || '';
                  t.last_seen_at = attr.last_seen_at || '';
                  t.utm_first = attr.utm_first ? JSON.stringify(attr.utm_first) : '';
                  t.utm_last = attr.utm_last ? JSON.stringify(attr.utm_last) : '';
                  t.fbclid = ci.fbclid || ''; t.gclid = ci.gclid || ''; t.gbraid = ci.gbraid || '';
                  t.wbraid = ci.wbraid || ''; t.li_fat_id = ci.li_fat_id || ''; t.ttclid = ci.ttclid || '';
                  // vid/sid: re-ler cookie no submit (pixel pode setar após o load).
                  if (!t.visitor_id) t.visitor_id = cookie('_dosvi') || cookie('adspirit_vid') || '';
                  if (!t.session_id) t.session_id = cookie('_dossi') || cookie('adspirit_sid') || '';
                  fields.forEach(function(name) {
                    var el = form.querySelector('input[name="_adspirit_t_' + name + '"]');
                    if (el) el.value = String(t[name] || '');
                  });
                  var i = document.createElement('input');
                  i.type = 'hidden'; i.name = '_adspirit_t_time_on_page_ms'; i.value = String(Date.now() - t.start_ts);
                  form.appendChild(i);
                  var j = document.createElement('input');
                  j.type = 'hidden'; j.name = '_adspirit_t_time_in_form_ms';
                  j.value = String(t.form_focus_ts ? (Date.now() - t.form_focus_ts) : 0);
                  form.appendChild(j);
                  var k = document.createElement('input');
                  k.type = 'hidden'; k.name = '_adspirit_t_fields_visited'; k.value = String(t.fields_visited);
                  form.appendChild(k);
                  var l = document.createElement('input');
                  l.type = 'hidden'; l.name = '_adspirit_t_pages_in_session'; l.value = String(t.pages_in_session || 1);
                  form.appendChild(l);
                  // Behavior summary (JSON string lido de sessionStorage no momento do submit).
                  var bhv = document.createElement('input');
                  bhv.type = 'hidden'; bhv.name = '_adspirit_t_behavior';
                  bhv.value = readBehavior();
                  form.appendChild(bhv);
                });
              });
            }
            if (document.readyState === 'loading') {
              document.addEventListener('DOMContentLoaded', attachHidden);
            } else {
              attachHidden();
            }
            // Re-attach pra forms dinâmicos
            if (typeof MutationObserver !== 'undefined') {
              new MutationObserver(attachHidden).observe(document.body, { childList: true, subtree: true });
            }
          } catch (e) { /* silenciado */ }
        })();
        </script>
        <?php
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
