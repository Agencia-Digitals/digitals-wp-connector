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
    }

    /**
     * JS coleta browser data e popula campos hidden em todos os forms
     * relevantes (CF7, Gravity, etc) com prefix `_adspirit_t_*`.
     * Plugin server-side lê esses campos no submit.
     */
    public function inject_collector() {
        if (is_admin()) return;

        // Respeita consent LGPD se desabilitado
        if (class_exists('AdSpirit_Lgpd_Popup')
            && method_exists('AdSpirit_Lgpd_Popup', 'has_telemetry_consent')
            && !AdSpirit_Lgpd_Popup::has_telemetry_consent()) {
            return;
        }

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
            t.visitor_id = cookie('adspirit_vid') || '';
            t.session_id = cookie('adspirit_sid') || '';
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
                              'locale', 'timezone', 'color_scheme', 'screen', 'viewport', 'connection_type'];
                fields.forEach(function(name) {
                  var input = document.createElement('input');
                  input.type = 'hidden';
                  input.name = '_adspirit_t_' + name;
                  input.value = '';
                  form.appendChild(input);
                });
                // Time on page + time in form + fields visited preenchidos no submit
                form.addEventListener('submit', function() {
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

        return array(
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

            // Atribuição
            'fbp' => $get('fbp'),
            'fbc' => $get('fbc'),
            'ga' => $get('ga'),
            'gid' => $get('gid'),
            'gcl_au' => $get('gcl_au'),

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
