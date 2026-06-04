<?php
/**
 * AdSpirit Connector — Lead score preview (Feature 35).
 *
 * Mostra um card AdSpirit dentro do [adspirit_form] no ÚLTIMO step,
 * em real-time, dizendo "Você qualifica como Perfil A — Excelente fit"
 * ANTES do submit. Aumenta perceived value + conversão (efeito gamification).
 *
 * Componentes:
 *   1. Settings toggle `show_lead_score_preview` (default off) — vive em
 *      adspirit_connector_settings (core options), salvo na tab Conexão CRM.
 *   2. Proxy AJAX wp_ajax_*_adspirit_preview_score → POST pro CRM
 *      /api/wp/preview-score com brand_slug + secret per-brand.
 *      Plugin nunca expõe o secret no JS — JS chama o admin-ajax e o PHP
 *      faz o request server-to-server.
 *   3. JS injetado em [adspirit_form]: ao ENTRAR no último step, faz POST
 *      do payload atual e renderiza card escuro + badge accent.
 *
 * Privacidade: payload do preview NÃO cria lead. CRM endpoint só calcula
 * score in-memory e devolve. Submit final continua sendo o webhook tradicional.
 *
 * Premium UI: card escuro (--as-ink), badge accent gigante do perfil
 * (A/B/C/D) + texto motivacional contextual + microcopy.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Lead_Score {

    /**
     * Key na option core (adspirit_connector_settings).
     * Default off — feature opt-in pra não surpreender quem instala.
     */
    const SETTING_KEY = 'show_lead_score_preview';

    /**
     * Tempo mínimo entre previews por sessão (ms) — cabeado no JS, mas
     * documentado aqui. Plugin debounces no client. Servidor rate-limita
     * 15/min (vide /api/wp/preview-score).
     */
    const PREVIEW_DEBOUNCE_MS = 600;

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Hook nos forms nativos pra injetar JS no final do render.
        add_action(
            'wp_footer',
            AdSpirit_Safe_Hook::action(array($this, 'maybe_inject_js'), 'lead_score_js'),
            99
        );

        // AJAX proxy — JS chama admin-ajax.php, PHP faz a chamada pro CRM
        // com o secret. Secret nunca vaza pro client.
        add_action(
            'wp_ajax_adspirit_preview_score',
            AdSpirit_Safe_Hook::action(array($this, 'handle_ajax'), 'preview_score_admin')
        );
        add_action(
            'wp_ajax_nopriv_adspirit_preview_score',
            AdSpirit_Safe_Hook::action(array($this, 'handle_ajax'), 'preview_score_anon')
        );

        // Registra hook na tab Conexão CRM pra renderizar o toggle.
        // Filtro de save é genérico — connection_save existing já lê
        // $post['show_lead_score_preview']; mas pra não acoplar duro,
        // expomos um filter próprio que o handler lê.
        add_filter(
            'adspirit_connector_connection_render_extra',
            array($this, 'render_settings_row'),
            10,
            1
        );
        add_filter(
            'adspirit_connector_connection_save_extra',
            array($this, 'save_setting'),
            10,
            2
        );
    }

    /**
     * Devolve true se a feature está habilitada nas settings.
     */
    public static function is_enabled() {
        $core = AdSpirit_Settings::get_core();
        $val = isset($core[self::SETTING_KEY]) ? $core[self::SETTING_KEY] : '0';
        return $val === '1';
    }

    /**
     * AJAX: proxy pro CRM. Body do POST tem `payload` (FormData →
     * coletado pelo JS do form). Devolve JSON com score/profile/etc.
     */
    public function handle_ajax() {
        // Rate limit: 10 calls/min/IP. Plugin debounces 600ms client-side
        // mas precisamos proteger admin-ajax de flood externo (atacante
        // anônimo poderia spammar /api/wp/preview-score do CRM via cá).
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) $ip = (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
        $rl_key = 'adspirit_score_rl_' . md5($ip);
        $count = (int) get_transient($rl_key);
        if ($count >= 10) {
            wp_send_json(array('ok' => false, 'error' => 'rate_limit'), 429);
        }
        set_transient($rl_key, $count + 1, 60);

        if (!self::is_enabled()) {
            wp_send_json(array('ok' => false, 'error' => 'feature_disabled'));
        }

        $core = AdSpirit_Settings::get_core();
        if (empty($core['brand_slug']) || empty($core['secret']) || empty($core['endpoint_url'])) {
            wp_send_json(array('ok' => false, 'error' => 'plugin_nao_conectado'));
        }

        $payload_raw = isset($_POST['payload']) ? wp_unslash((string) $_POST['payload']) : '';
        $payload = json_decode($payload_raw, true);
        if (!is_array($payload)) {
            wp_send_json(array('ok' => false, 'error' => 'payload_invalido'));
        }

        // Sanitiza values (textos curtos). Não permite arrays aninhados.
        $clean = array();
        foreach ($payload as $k => $v) {
            $key = sanitize_text_field((string) $k);
            if ($key === '') continue;
            if (is_array($v)) {
                $v = implode(', ', array_filter(array_map('strval', $v)));
            }
            $clean[$key] = sanitize_text_field((string) $v);
        }
        if (empty($clean)) {
            wp_send_json(array('ok' => false, 'error' => 'payload_vazio'));
        }

        $endpoint = rtrim($core['endpoint_url'], '/') . '/api/wp/preview-score';
        $response = wp_remote_post($endpoint, array(
            'timeout' => 5,
            'redirection' => 2,
            'headers' => array(
                'Content-Type' => 'application/json; charset=utf-8',
                'x-brand-slug' => $core['brand_slug'],
                'x-cf7-secret' => $core['secret'],
                'User-Agent'   => 'AdSpirit-Connector/' . ADSPIRIT_CONNECTOR_VERSION,
            ),
            'body' => wp_json_encode(array('payload' => $clean), JSON_UNESCAPED_UNICODE),
        ));

        if (is_wp_error($response)) {
            wp_send_json(array('ok' => false, 'error' => 'rede:' . $response->get_error_message()));
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200 || !is_array($data) || empty($data['ok'])) {
            $err = is_array($data) && !empty($data['error']) ? $data['error'] : ('HTTP ' . $code);
            wp_send_json(array('ok' => false, 'error' => $err));
        }

        // Sucesso — re-emite só os campos que o JS usa, com mensagem
        // motivacional contextualizada pelo profile.
        $profile = isset($data['profile']) ? (string) $data['profile'] : 'D';
        $band = isset($data['band']) ? (string) $data['band'] : '';
        wp_send_json(array(
            'ok' => true,
            'score' => isset($data['score']) ? (float) $data['score'] : 0,
            'profile' => $profile,
            'band' => $band,
            'qualified' => !empty($data['qualified']),
            'headline' => self::headline_for_profile($profile),
            'subline' => self::subline_for_profile($profile, $band),
        ));
    }

    /**
     * Microcopy motivacional por perfil. Tom premium, sem hype barato.
     * Cliente final do form vê isso, então tem que soar como AdSpirit:
     * elegante, direto, decisivo.
     */
    public static function headline_for_profile($profile) {
        switch ($profile) {
            case 'A': return 'Você é exatamente quem buscamos.';
            case 'B': return 'Forte fit com nosso método.';
            case 'C': return 'Vamos avaliar com cuidado.';
            case 'D':
            default: return 'Recebemos suas respostas.';
        }
    }

    public static function subline_for_profile($profile, $band) {
        switch ($profile) {
            case 'A':
                return 'Perfil A — encaixe excelente. Um especialista entra em contato em até 24h pra alinhar o plano.';
            case 'B':
                return 'Perfil B — bom fit. Vamos validar 2 ou 3 pontos numa conversa rápida e seguir.';
            case 'C':
                return 'Perfil C — alguns sinais ainda em aberto. Te chamamos pra entender se faz sentido pros dois lados.';
            case 'D':
            default:
                return 'Vamos analisar e retornar com a melhor recomendação pro seu momento.';
        }
    }

    /**
     * Renderiza linha de toggle dentro do form da Conexão CRM. É chamado
     * pelo filter adspirit_connector_connection_render_extra (atualmente
     * só este consumidor — outras features podem se plugar depois).
     */
    public function render_settings_row($_unused) {
        $enabled = self::is_enabled();
        ob_start();
        ?>
        <tr>
            <th>Preview de score no form</th>
            <td>
                <label>
                    <input type="checkbox" name="<?php echo esc_attr(self::SETTING_KEY); ?>" value="1" <?php checked($enabled); ?>>
                    Mostrar card "Perfil A/B/C/D" no último step do <code>[adspirit_form]</code>
                </label>
                <p class="description">Aumenta perceived value e conversão. Calcula em real-time via CRM <strong>sem criar lead</strong>. Desligado por padrão.</p>
            </td>
        </tr>
        <?php
        return ob_get_clean();
    }

    /**
     * Salva o toggle. Recebido via filter pra o handler de connection
     * não precisar conhecer essa feature.
     */
    public function save_setting($patch, $post) {
        $patch[self::SETTING_KEY] = !empty($post[self::SETTING_KEY]) ? '1' : '0';
        return $patch;
    }

    /**
     * Injeta JS uma vez por página, só se:
     *   - feature enabled
     *   - página tem ao menos 1 [adspirit_form] renderizado
     *
     * O JS aplica-se a TODOS forms na página. Detecta último step
     * automaticamente (last .step do form).
     */
    public function maybe_inject_js() {
        if (is_admin()) return;
        if (!self::is_enabled()) return;
        // Sniff barato: se nenhum form .adspirit-form está no DOM, sai. A
        // checagem fina acontece no JS — se errar, é só CSS sobrando.
        global $post;
        if (is_singular() && $post && is_a($post, 'WP_Post')) {
            if (strpos((string) $post->post_content, 'adspirit_form') === false) return;
        }

        $ajax_url = admin_url('admin-ajax.php');
        $debounce = (int) self::PREVIEW_DEBOUNCE_MS;
        ?>
        <style>
            .adspirit-score-card {
                margin: 20px 0 0;
                padding: 22px 22px 20px;
                background: #0F1419;
                border: 1px solid #1F2933;
                border-radius: 12px;
                color: #FFFFFF;
                font-family: -apple-system,BlinkMacSystemFont,'SF Pro Text','Segoe UI',sans-serif;
                opacity: 0;
                transform: translateY(8px);
                transition: opacity 0.32s ease, transform 0.32s ease;
                position: relative;
                overflow: hidden;
            }
            .adspirit-score-card.visible { opacity: 1; transform: translateY(0); }
            .adspirit-score-card.loading { opacity: 0.6; }
            .adspirit-score-card .as-glow {
                position: absolute; top: -40%; right: -10%;
                width: 220px; height: 220px;
                background: radial-gradient(circle, rgba(0,183,183,0.32) 0%, rgba(0,183,183,0) 70%);
                pointer-events: none;
            }
            .adspirit-score-card .as-kicker {
                position: relative;
                font-size: 10px;
                letter-spacing: 0.28em;
                text-transform: uppercase;
                color: #00B7B7;
                font-weight: 600;
                margin-bottom: 10px;
            }
            .adspirit-score-card .as-row {
                position: relative;
                display: flex;
                align-items: center;
                gap: 14px;
                margin-bottom: 10px;
            }
            .adspirit-score-card .as-badge {
                display: inline-flex;
                align-items: center;
                justify-content: center;
                min-width: 48px;
                height: 48px;
                padding: 0 12px;
                border-radius: 10px;
                font-family: -apple-system,BlinkMacSystemFont,'SF Pro Display','Inter',sans-serif;
                font-size: 26px;
                font-weight: 600;
                letter-spacing: -0.02em;
                background: rgba(0,183,183,0.16);
                color: #00B7B7;
                border: 1px solid rgba(0,183,183,0.55);
            }
            .adspirit-score-card.profile-A .as-badge { background: rgba(0,183,183,0.20); color: #4FE5E5; border-color: #00B7B7; }
            .adspirit-score-card.profile-B .as-badge { background: rgba(0,183,183,0.12); color: #00B7B7; border-color: rgba(0,183,183,0.7); }
            .adspirit-score-card.profile-C .as-badge { background: rgba(176,121,0,0.16); color: #F0C24A; border-color: rgba(240,194,74,0.7); }
            .adspirit-score-card.profile-D .as-badge { background: rgba(199,56,56,0.16); color: #FF8585; border-color: rgba(255,133,133,0.7); }
            .adspirit-score-card .as-headline {
                position: relative;
                font-size: 18px;
                font-weight: 500;
                letter-spacing: -0.018em;
                color: #FFFFFF;
                margin: 0;
                line-height: 1.25;
            }
            .adspirit-score-card .as-subline {
                position: relative;
                font-size: 13px;
                color: #A8B1BC;
                margin: 0;
                line-height: 1.55;
                max-width: 520px;
            }
            .adspirit-score-card .as-foot {
                position: relative;
                margin-top: 14px;
                padding-top: 12px;
                border-top: 1px solid rgba(255,255,255,0.06);
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 8px;
                font-size: 11px;
                letter-spacing: 0.08em;
                text-transform: uppercase;
                color: #6F7A86;
            }
            .adspirit-score-card .as-foot .band { color: #00B7B7; font-weight: 600; }
            .adspirit-score-card .as-spinner {
                position: relative;
                display: inline-block;
                width: 14px; height: 14px;
                border: 2px solid rgba(255,255,255,0.18);
                border-top-color: #00B7B7;
                border-radius: 50%;
                animation: adspirit-spin 0.8s linear infinite;
            }
            @keyframes adspirit-spin { to { transform: rotate(360deg); } }
        </style>
        <script>
        (function() {
            var AJAX = '<?php echo esc_js($ajax_url); ?>';
            var DEBOUNCE = <?php echo (int) $debounce; ?>;

            function bootForm(form) {
                if (form.dataset.adspiritScoreBound === '1') return;
                form.dataset.adspiritScoreBound = '1';
                var steps = form.querySelectorAll('.step');
                if (!steps.length) return;
                var lastIdx = steps.length - 1;
                var lastStep = steps[lastIdx];

                // Card container — append depois do conteúdo do último step,
                // antes do .nav (botões).
                var card = document.createElement('div');
                card.className = 'adspirit-score-card';
                card.innerHTML = ''
                    + '<div class="as-glow"></div>'
                    + '<div class="as-kicker">AdSpirit · qualificação em tempo real</div>'
                    + '<div class="as-row">'
                    +    '<div class="as-badge" data-role="badge">·</div>'
                    +    '<div>'
                    +       '<p class="as-headline" data-role="headline">Avaliando suas respostas…</p>'
                    +       '<p class="as-subline" data-role="subline">Calculando seu encaixe com nosso método.</p>'
                    +    '</div>'
                    + '</div>'
                    + '<div class="as-foot">'
                    +    '<span><span class="as-spinner" data-role="spinner"></span> Score em tempo real</span>'
                    +    '<span class="band" data-role="band"></span>'
                    + '</div>';
                var nav = lastStep.querySelector('.nav');
                if (nav) lastStep.insertBefore(card, nav);
                else lastStep.appendChild(card);

                var debounceTimer = null;
                var lastSerialized = '';
                var inflight = null;

                function serialize() {
                    var data = {};
                    var inputs = form.querySelectorAll('input[name], select[name], textarea[name]');
                    inputs.forEach(function(el) {
                        if (!el.name) return;
                        if (el.name.indexOf('_adspirit_') === 0) return;
                        if (el.type === 'checkbox' || el.type === 'radio') {
                            if (el.checked) {
                                if (data[el.name]) data[el.name] += ', ' + el.value;
                                else data[el.name] = el.value;
                            }
                        } else {
                            if (el.value) data[el.name] = el.value;
                        }
                    });
                    return data;
                }

                function render(d) {
                    card.classList.remove('loading');
                    card.classList.add('visible');
                    card.classList.remove('profile-A','profile-B','profile-C','profile-D');
                    if (d.profile) card.classList.add('profile-' + d.profile);
                    var badge = card.querySelector('[data-role="badge"]');
                    var head  = card.querySelector('[data-role="headline"]');
                    var sub   = card.querySelector('[data-role="subline"]');
                    var band  = card.querySelector('[data-role="band"]');
                    var spin  = card.querySelector('[data-role="spinner"]');
                    if (badge) badge.textContent = d.profile || '·';
                    if (head)  head.textContent = d.headline || 'Recebemos suas respostas.';
                    if (sub)   sub.textContent = d.subline || '';
                    if (band)  band.textContent = d.band || '';
                    if (spin)  spin.style.display = 'none';
                }

                function renderError() {
                    // Falha silenciosa: esconde o card. Não polui UX do form.
                    card.classList.remove('visible');
                }

                function fetchScore() {
                    var payload = serialize();
                    var ser = JSON.stringify(payload);
                    if (ser === lastSerialized) return;
                    lastSerialized = ser;
                    if (Object.keys(payload).length < 2) return;

                    card.classList.add('loading');
                    var fd = new FormData();
                    fd.append('action', 'adspirit_preview_score');
                    fd.append('payload', ser);

                    if (inflight && typeof inflight.abort === 'function') {
                        try { inflight.abort(); } catch(e) {}
                    }
                    var ctrl = (typeof AbortController !== 'undefined') ? new AbortController() : null;
                    inflight = ctrl;

                    fetch(AJAX, {
                        method: 'POST',
                        body: fd,
                        credentials: 'same-origin',
                        signal: ctrl ? ctrl.signal : undefined
                    })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (d && d.ok) render(d);
                        else renderError();
                    })
                    .catch(function() { renderError(); });
                }

                function scheduleFetch() {
                    if (debounceTimer) clearTimeout(debounceTimer);
                    debounceTimer = setTimeout(fetchScore, DEBOUNCE);
                }

                // Disparo: quando user entra no último step OR muda algum
                // input ANTES de chegar nele (pra ter dado quente quando
                // chegar). Monitoramos via MutationObserver no display do
                // .step pra detectar visibilidade.
                var obs = new MutationObserver(function() {
                    if (lastStep.style.display !== 'none') scheduleFetch();
                });
                obs.observe(lastStep, { attributes: true, attributeFilter: ['style'] });

                form.addEventListener('input',  scheduleFetch);
                form.addEventListener('change', scheduleFetch);

                // Caso o form já comece no último step (form 1-step), dispara.
                if (lastStep.style.display !== 'none') scheduleFetch();
            }

            function boot() {
                var forms = document.querySelectorAll('form.adspirit-form');
                forms.forEach(bootForm);
            }
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', boot);
            } else {
                boot();
            }
        })();
        </script>
        <?php
    }
}
