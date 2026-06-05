<?php
/**
 * AdSpirit Thank You — shortcode pra página de obrigado com conversão
 * server-side + redirect.
 *
 * Substitui o padrão atual (manual em cada brand): página duplicada de
 * formulário, deleta form, troca texto, adiciona <script>setTimeout +
 * window.location</script>. Esse padrão NÃO dispara conversão server-side
 * (apenas client-side via GA/Pixel browser), perdendo ~15-30% de eventos
 * por ad-blockers / iOS / privacy mode.
 *
 * Uso:
 *   [adspirit_thank_you redirect="https://wa.me/55..." delay="3000"]
 *
 * Atributos:
 *   redirect    — URL pra redirecionar (obrigatório)
 *   delay       — ms antes do redirect (default 3000)
 *   event_name  — nome do evento Meta CAPI (default "Lead")
 *   value       — valor numérico (opcional, pra ROAS de Meta)
 *   currency    — moeda (default BRL)
 *   message     — texto principal (default "Cadastro recebido")
 *   sub         — subtexto (default "Você será redirecionado em alguns segundos…")
 *
 * Server-side ao renderizar:
 *   - Dispara Meta CAPI event (se config habilitada)
 *   - Dispara GA4 server-side (se config habilitada)
 *   - event_id determinístico (session+url+day) deduplica F5 e
 *     deduplica com pixel browser-side se ambos ativos.
 *
 * Client-side:
 *   - dataLayer.push({event:'adspirit_conversion', ...}) pra GTM propagar
 *   - countdown visual + window.location após delay
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Thank_You {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('adspirit_thank_you', array($this, 'render_shortcode'));
    }

    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'redirect'   => '',
            'delay'      => '3000',
            'event_name' => 'Lead',
            'value'      => '',
            'currency'   => 'BRL',
            'message'    => 'Cadastro recebido',
            'sub'        => 'Você será redirecionado em alguns segundos…',
        ), $atts, 'adspirit_thank_you');

        $redirect = esc_url_raw(trim((string) $atts['redirect']));
        $delay = (int) $atts['delay'];
        if ($delay < 0) $delay = 0;
        if ($delay > 30000) $delay = 30000;
        $event_name = sanitize_text_field((string) $atts['event_name']);
        $value = is_numeric($atts['value']) ? (float) $atts['value'] : null;
        $currency = sanitize_text_field((string) $atts['currency']);

        // Event ID determinístico — dedup F5 do mesmo user na mesma página/dia
        // e dedup com pixel browser-side (Meta dedupa por event_id+event_name).
        $event_id = self::build_event_id($event_name);
        $source_url = self::current_url();

        // Server-side dispatches (silent fail se config off ou sem credentials)
        self::try_send_capi($event_name, $event_id, $source_url, $value, $currency);
        self::try_send_ga4($event_name, $event_id, $source_url, $value, $currency);

        // Enqueue assets só quando o shortcode renderiza
        $version = defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : '2.5.0';
        wp_enqueue_style(
            'adspirit-thank-you',
            ADSPIRIT_CONNECTOR_URL . 'assets/thank-you.css',
            array(),
            $version
        );

        // dataLayer push inline + countdown + redirect
        $datalayer_payload = wp_json_encode(array(
            'event' => 'adspirit_conversion',
            'adspirit_event_name' => $event_name,
            'adspirit_event_id' => $event_id,
            'adspirit_value' => $value,
            'adspirit_currency' => $currency,
        ));

        ob_start();
        ?>
        <div class="adspirit-thank-you" role="status" aria-live="polite">
            <div class="adspirit-thank-you-icon" aria-hidden="true">
                <svg viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="20 6 9 17 4 12"></polyline>
                </svg>
            </div>
            <h2 class="adspirit-thank-you-title"><?php echo esc_html($atts['message']); ?></h2>
            <p class="adspirit-thank-you-sub"><?php echo esc_html($atts['sub']); ?></p>
            <?php if ($redirect && $delay > 0) : ?>
            <div class="adspirit-thank-you-countdown" data-redirect="<?php echo esc_attr($redirect); ?>" data-delay="<?php echo esc_attr($delay); ?>">
                <span class="adspirit-thank-you-countdown-num">--</span>
                <span class="adspirit-thank-you-countdown-label">segundos</span>
            </div>
            <p class="adspirit-thank-you-fallback">
                Não foi redirecionado? <a href="<?php echo esc_url($redirect); ?>" rel="noopener">Clique aqui</a>.
            </p>
            <?php endif; ?>
        </div>
        <script>
        (function () {
            try { window.dataLayer = window.dataLayer || []; window.dataLayer.push(<?php echo $datalayer_payload; ?>); } catch (e) {}
            var box = document.currentScript && document.currentScript.previousElementSibling;
            if (!box) return;
            var cd = box.querySelector('.adspirit-thank-you-countdown');
            if (!cd) return;
            var redirect = cd.getAttribute('data-redirect');
            var delay = parseInt(cd.getAttribute('data-delay'), 10) || 0;
            if (!redirect || delay <= 0) return;
            var num = cd.querySelector('.adspirit-thank-you-countdown-num');
            var remaining = Math.ceil(delay / 1000);
            if (num) num.textContent = String(remaining);
            var tick = setInterval(function () {
                remaining -= 1;
                if (num) num.textContent = String(Math.max(0, remaining));
                if (remaining <= 0) {
                    clearInterval(tick);
                    window.location.href = redirect;
                }
            }, 1000);
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    private static function build_event_id($event_name) {
        // session id usa _fbp/adspirit_vid se disponíveis, senão hash de IP+UA.
        $sid = '';
        if (!empty($_COOKIE['_fbp'])) $sid = (string) $_COOKIE['_fbp'];
        elseif (!empty($_COOKIE['adspirit_vid'])) $sid = (string) $_COOKIE['adspirit_vid'];
        else {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 40) : '';
            $sid = md5($ip . '|' . $ua);
        }
        $day = gmdate('Ymd');
        $url_path = isset($_SERVER['REQUEST_URI']) ? parse_url((string) $_SERVER['REQUEST_URI'], PHP_URL_PATH) : '/';
        return 'ty-' . sanitize_key($event_name) . '-' . md5($sid . '|' . $url_path . '|' . $day);
    }

    private static function try_send_capi($event_name, $event_id, $source_url, $value, $currency) {
        if (!class_exists('AdSpirit_Capi_Meta') || !class_exists('AdSpirit_Settings')) return;
        $cfg = AdSpirit_Settings::get_capi_meta();
        if (empty($cfg['enabled']) || $cfg['enabled'] !== '1') return;
        if (empty($cfg['pixel_id']) || empty($cfg['access_token'])) return;

        // Throttle: 1 envio por session+page+day (mesmo event_id) — F5 não duplica
        $throttle_key = 'adspirit_ty_capi_' . md5($event_id);
        if (get_transient($throttle_key)) return;
        set_transient($throttle_key, 1, DAY_IN_SECONDS);

        // build_user_data é private no Capi_Meta. Replicamos minimal aqui:
        $user_data = array(
            'client_ip_address' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
            'client_user_agent' => isset($_SERVER['HTTP_USER_AGENT']) ? (string) $_SERVER['HTTP_USER_AGENT'] : '',
        );
        if (!empty($_COOKIE['_fbc'])) $user_data['fbc'] = (string) $_COOKIE['_fbc'];
        if (!empty($_COOKIE['_fbp'])) $user_data['fbp'] = (string) $_COOKIE['_fbp'];

        $custom = array('currency' => $currency);
        if ($value !== null) $custom['value'] = $value;

        $event = array(
            'event_name'       => $event_name,
            'event_time'       => time(),
            'event_id'         => $event_id,
            'action_source'    => 'website',
            'event_source_url' => $source_url,
            'user_data'        => $user_data,
            'custom_data'      => $custom,
        );
        $url = 'https://graph.facebook.com/v22.0/' . urlencode($cfg['pixel_id']) . '/events';
        $body = array('data' => array($event), 'access_token' => $cfg['access_token']);
        if (!empty($cfg['test_event_code'])) {
            $body['test_event_code'] = $cfg['test_event_code'];
        }
        wp_remote_post($url, array(
            'timeout'  => 6,
            'blocking' => false,
            'headers'  => array('Content-Type' => 'application/json'),
            'body'     => wp_json_encode($body),
        ));
    }

    private static function try_send_ga4($event_name, $event_id, $source_url, $value, $currency) {
        if (!class_exists('AdSpirit_Settings')) return;
        if (!method_exists('AdSpirit_Settings', 'get_ga4')) return;
        $cfg = AdSpirit_Settings::get_ga4();
        if (empty($cfg['enabled']) || $cfg['enabled'] !== '1') return;
        if (empty($cfg['measurement_id']) || empty($cfg['api_secret'])) return;

        $throttle_key = 'adspirit_ty_ga4_' . md5($event_id);
        if (get_transient($throttle_key)) return;
        set_transient($throttle_key, 1, DAY_IN_SECONDS);

        $client_id = !empty($_COOKIE['_ga']) ? preg_replace('/^GA1\.\d\./', '', (string) $_COOKIE['_ga']) : '';
        if ($client_id === '') {
            $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '';
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? substr((string) $_SERVER['HTTP_USER_AGENT'], 0, 40) : '';
            $client_id = substr(md5($ip . '|' . $ua), 0, 16);
        }

        $params = array(
            'event_id'   => $event_id,
            'page_location' => $source_url,
            'currency'   => $currency,
        );
        if ($value !== null) $params['value'] = $value;

        $payload = array(
            'client_id' => $client_id,
            'events' => array(array(
                'name'   => self::ga4_event_name($event_name),
                'params' => $params,
            )),
        );
        $url = 'https://www.google-analytics.com/mp/collect?measurement_id=' . urlencode($cfg['measurement_id']) . '&api_secret=' . urlencode($cfg['api_secret']);
        wp_remote_post($url, array(
            'timeout'  => 6,
            'blocking' => false,
            'headers'  => array('Content-Type' => 'application/json'),
            'body'     => wp_json_encode($payload),
        ));
    }

    private static function ga4_event_name($meta_event) {
        // Mapeia Meta event_name pra convenção GA4 (snake_case lowercase)
        $map = array(
            'Lead'         => 'generate_lead',
            'Purchase'     => 'purchase',
            'CompleteRegistration' => 'sign_up',
            'Contact'      => 'contact',
            'Schedule'     => 'schedule',
        );
        return isset($map[$meta_event]) ? $map[$meta_event] : strtolower(preg_replace('/[^a-z0-9]+/i', '_', $meta_event));
    }

    private static function current_url() {
        $proto = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host  = isset($_SERVER['HTTP_HOST']) ? (string) $_SERVER['HTTP_HOST'] : '';
        $uri   = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '/';
        return $proto . '://' . $host . $uri;
    }
}
