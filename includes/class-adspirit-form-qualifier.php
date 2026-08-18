<?php
/**
 * AdSpirit Form Qualifier — shortcode multi-step BANT + popup overlay.
 *
 * Uso: [adspirit_form_qualifier] (popup com botão "Iniciar avaliação")
 *      [adspirit_form_qualifier mode="inline"] (renderiza inline)
 *
 * Fluxo:
 *   1. Cliente clica botão (mode=popup) ou form já aparece (mode=inline)
 *   2. Form multi-step: 11 etapas BANT (alinhadas com config do CRM)
 *   3. Submit AJAX → POST /api/webhooks/contact-form-7 (CRM)
 *   4. CRM retorna { redirect_url, profile }
 *   5. Tela "Cadastro recebido" + countdown 5s → window.location
 *
 * Privacidade: profile é retornado pelo CRM e fica visível no DevTools
 * (decisão produto 2026-06-04). Cliente final não vê feedback explícito
 * sobre a qualificação na UI — apenas o redirect varia.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Form_Qualifier {
    const OPTION_KEY = 'adspirit_qualifier';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Shortcode
        add_shortcode('adspirit_form_qualifier', array($this, 'render_shortcode'));

        // AJAX submit (priv + nopriv: form é público no site)
        add_action('wp_ajax_adspirit_qualifier_submit',
            AdSpirit_Safe_Hook::action(array($this, 'handle_submit'), 'qualifier_submit'));
        add_action('wp_ajax_nopriv_adspirit_qualifier_submit',
            AdSpirit_Safe_Hook::action(array($this, 'handle_submit'), 'qualifier_submit'));

        // Nonce fresco sob demanda. O HTML da página fica atrás de page cache
        // (LiteSpeed servia max-age de 7 DIAS), mas o nonce embutido vive
        // 12-24h — visitante com cópia cacheada levava bad_nonce e o form
        // mostrava "Falha de conexão" (incidente 2026-07-14). admin-ajax
        // nunca é cacheado, então o front busca o nonce daqui na hora do
        // submit em vez de confiar no que veio no HTML.
        add_action('wp_ajax_adspirit_qualifier_nonce',
            AdSpirit_Safe_Hook::action(array($this, 'handle_nonce'), 'qualifier_nonce'));
        add_action('wp_ajax_nopriv_adspirit_qualifier_nonce',
            AdSpirit_Safe_Hook::action(array($this, 'handle_nonce'), 'qualifier_nonce'));

        // Aba de ajuda no admin: qual shortcode usar + classe do botão.
        add_filter('adspirit_connector_tabs',
            AdSpirit_Safe_Hook::filter(array($this, 'register_tab'), 'qualifier_tab_register'));
        add_action('adspirit_connector_render_tab_qualifier',
            AdSpirit_Safe_Hook::action(array($this, 'render_tab'), 'qualifier_tab'));
        add_action('adspirit_connector_save_qualifier',
            AdSpirit_Safe_Hook::action(array($this, 'handle_save'), 'qualifier_save'));

        // v2.10.3: hooks de "site todo" REGISTRADOS APENAS quando o toggle está
        // explicitamente ligado (option === '1'). Antes ficavam sempre registrados
        // e davam early-return dentro do handler — funcional, mas em sites com
        // LiteSpeed JS combine + muitos plugins isso introduzia interferência
        // no submit AJAX do CF7 (form principal parava de chegar nos hooks).
        // Sem opt-in, ZERO modificação do front-end (sem extra wp_footer hook,
        // sem extra enqueue check).
        $qs = get_option(self::OPTION_KEY, array());
        if (isset($qs['sitewide']) && $qs['sitewide'] === '1') {
            add_action('wp_enqueue_scripts',
                AdSpirit_Safe_Hook::action(array($this, 'maybe_enqueue_sitewide'), 'qualifier_sitewide_enqueue'));
            add_action('wp_footer',
                AdSpirit_Safe_Hook::action(array($this, 'maybe_inject_sitewide_root'), 'qualifier_sitewide_root'));
        }
    }

    public static function defaults() {
        return array('sitewide' => '0', 'steps' => array());
    }

    public static function get_settings() {
        return wp_parse_args(get_option(self::OPTION_KEY, array()), self::defaults());
    }

    // ─────────────────────────────────────────────────────────
    // Roteiro de perguntas (multi-tenant)
    // ─────────────────────────────────────────────────────────
    //
    // O runtime (assets/qualifier-form.js) é genérico: ele desenha o que
    // estiver em STEPS. O roteiro da Digitals mora no DEFAULT_STEPS do JS;
    // um tenant com outras perguntas salva o roteiro dele aqui e ganha o
    // MESMO form — mesma UI, mesmo lead parcial, mesma telemetria — só com
    // outro conteúdo. Sem roteiro salvo, o JS cai no default e o site da
    // Digitals não muda em nada.

    const MAX_STEPS = 40;

    /** Tipos de input aceitos numa etapa de campo aberto. */
    public static function allowed_field_types() {
        return array('text', 'email', 'tel', 'url', 'textarea');
    }

    /** Roteiro custom salvo (array vazio = usa o default do JS). */
    public static function get_steps() {
        $s = self::get_settings();
        return (isset($s['steps']) && is_array($s['steps'])) ? $s['steps'] : array();
    }

    /**
     * Valida/normaliza um roteiro vindo de JSON.
     *
     * Formato (mesmo shape do DEFAULT_STEPS do JS):
     *   [
     *     { "isIntro": true, "eyebrow": "...", "title": "...", "sub": "..." },
     *     { "eyebrow": "...", "title": "...", "capturePartial": true,
     *       "fields": [ {"key":"phone","type":"tel","placeholder":"WhatsApp com DDD",
     *                    "required":true,"canonical":"Telefone"} ] },
     *     { "eyebrow": "...", "title": "...", "fieldKey": "role", "canonical": "cargo",
     *       "choices": [ {"label":"Sócio(a)","meta":"decisor direto"} ] },
     *     { "isSuccess": true }
     *   ]
     *
     * `canonical` é a chave com que a resposta chega no CRM (your-name,
     * your-email, Telefone, empresa, cargo, revenue, Investimento, urgencia,
     * pain…). Sem ele, usa a própria key — e o CRM guarda mas não exibe.
     *
     * @return array{ok:bool, steps?:array, error?:string}
     */
    /**
     * v2.30 — condicional por etapa (modelo Gravity: rules + all/any).
     * showIf = { match: 'all'|'any', rules: [{field, op: is|not|contains, value}] }
     * Avaliado no JS contra respostas anteriores; etapa cuja condição não
     * bate é PULADA e a resposta dela não viaja no submit. Etapa sem showIf
     * é sempre visível — roteiro antigo se comporta exatamente como antes.
     * Retorna array limpo ou null (sem condicional válida).
     */
    private static function sanitize_show_if($step) {
        if (!isset($step['showIf']) || !is_array($step['showIf'])) return null;
        $cond = $step['showIf'];
        $rules_in = isset($cond['rules']) && is_array($cond['rules']) ? $cond['rules'] : array();
        $rules = array();
        foreach ($rules_in as $r) {
            if (!is_array($r)) continue;
            $field = self::sanitize_key_name(isset($r['field']) ? $r['field'] : '');
            if ($field === '') continue;
            $op = isset($r['op']) && in_array($r['op'], array('is', 'not', 'contains'), true) ? $r['op'] : 'is';
            $rules[] = array(
                'field' => $field,
                'op'    => $op,
                'value' => sanitize_text_field((string) (isset($r['value']) ? $r['value'] : '')),
            );
        }
        if (empty($rules)) return null;
        return array(
            'match' => (isset($cond['match']) && $cond['match'] === 'any') ? 'any' : 'all',
            'rules' => $rules,
        );
    }

    public static function sanitize_steps($raw) {
        if (!is_array($raw) || empty($raw)) {
            return array('ok' => false, 'error' => 'O JSON precisa ser uma lista de etapas não-vazia.');
        }
        if (count($raw) > self::MAX_STEPS) {
            return array('ok' => false, 'error' => sprintf('Roteiro grande demais (%d etapas, máximo %d).', count($raw), self::MAX_STEPS));
        }

        $first = is_array($raw[0]) ? $raw[0] : array();
        if (empty($first['isIntro'])) {
            return array('ok' => false, 'error' => 'A primeira etapa precisa ser a de abertura — o objeto com <code>"isIntro": true</code>, que é a tela do botão "Iniciar avaliação".');
        }

        $types = self::allowed_field_types();
        $out = array();
        $seen_keys = array();
        $canonicals = array();
        $questions = 0;

        foreach (array_values($raw) as $i => $step) {
            if (!is_array($step)) continue;

            // Tela final: só um marcador, o texto vive no JS.
            if (!empty($step['isSuccess'])) continue; // re-adicionada no fim

            $clean = array();
            if (!empty($step['isIntro'])) $clean['isIntro'] = true;
            foreach (array('eyebrow', 'title', 'sub') as $k) {
                if (isset($step[$k]) && $step[$k] !== '') {
                    $clean[$k] = sanitize_text_field((string) $step[$k]);
                }
            }
            if (!empty($step['isIntro'])) {
                $out[] = $clean;
                continue;
            }

            if (empty($clean['title'])) {
                return array('ok' => false, 'error' => sprintf('Etapa %d está sem <code>title</code> — é a pergunta que aparece grande na tela.', $i + 1));
            }
            if (!empty($step['optional'])) $clean['optional'] = true;
            if (!empty($step['capturePartial'])) $clean['capturePartial'] = true;

            // Etapa de escolha (cards) — o formato que dá a experiência do
            // qualifier. Ou etapa de campo aberto (input/textarea).
            if (isset($step['choices']) && is_array($step['choices']) && !empty($step['choices'])) {
                $field_key = self::sanitize_key_name(isset($step['fieldKey']) ? $step['fieldKey'] : '');
                if ($field_key === '') {
                    return array('ok' => false, 'error' => sprintf('Etapa %d tem opções mas está sem <code>fieldKey</code> (o nome do campo onde a resposta é guardada).', $i + 1));
                }
                if (isset($seen_keys[$field_key])) {
                    return array('ok' => false, 'error' => sprintf('Campo repetido: <code>%s</code> aparece em mais de uma etapa. Cada resposta precisa de uma chave própria.', esc_html($field_key)));
                }
                $seen_keys[$field_key] = true;

                $choices = array();
                $letters = range('A', 'Z');
                foreach (array_values($step['choices']) as $ci => $choice) {
                    if (is_string($choice)) $choice = array('label' => $choice);
                    if (!is_array($choice) || !isset($choice['label']) || trim((string) $choice['label']) === '') continue;
                    $c = array('label' => sanitize_text_field((string) $choice['label']));
                    if (isset($choice['meta']) && $choice['meta'] !== '') {
                        $c['meta'] = sanitize_text_field((string) $choice['meta']);
                    }
                    // kbd = atalho de teclado do card. Auto A, B, C… quando omitido.
                    $c['kbd'] = isset($choice['kbd']) && $choice['kbd'] !== ''
                        ? strtoupper(substr(sanitize_text_field((string) $choice['kbd']), 0, 1))
                        : (isset($letters[$ci]) ? $letters[$ci] : '');
                    $choices[] = $c;
                }
                if (empty($choices)) {
                    return array('ok' => false, 'error' => sprintf('Etapa %d ficou sem opção válida — cada opção precisa de <code>label</code>.', $i + 1));
                }
                $clean['fieldKey'] = $field_key;
                $clean['canonical'] = self::sanitize_key_name(isset($step['canonical']) ? $step['canonical'] : $field_key);
                $canonicals[] = $clean['canonical'];
                $clean['choices'] = $choices;
                $show_if = self::sanitize_show_if($step);
                if ($show_if !== null) $clean['showIf'] = $show_if;
                $out[] = $clean;
                $questions++;
                continue;
            }

            $fields_in = isset($step['fields']) && is_array($step['fields']) ? $step['fields'] : array();
            $fields_out = array();
            foreach ($fields_in as $f) {
                if (!is_array($f)) continue;
                $key = self::sanitize_key_name(isset($f['key']) ? $f['key'] : '');
                if ($key === '') continue;
                if (isset($seen_keys[$key])) {
                    return array('ok' => false, 'error' => sprintf('Campo repetido: <code>%s</code> aparece em mais de uma etapa. Cada resposta precisa de uma chave própria.', esc_html($key)));
                }
                $seen_keys[$key] = true;
                $type = (isset($f['type']) && in_array($f['type'], $types, true)) ? $f['type'] : 'text';
                $canonical = self::sanitize_key_name(isset($f['canonical']) ? $f['canonical'] : $key);
                $canonicals[] = $canonical;
                $fields_out[] = array(
                    'key'         => $key,
                    'type'        => $type,
                    'placeholder' => sanitize_text_field((string) (isset($f['placeholder']) ? $f['placeholder'] : '')),
                    // Campo nasce obrigatório; só é opcional se disser.
                    'required'    => !(isset($f['required']) && $f['required'] === false),
                    'canonical'   => $canonical,
                );
            }
            if (empty($fields_out)) {
                return array('ok' => false, 'error' => sprintf('Etapa %d não tem nem <code>choices</code> nem <code>fields</code> com <code>key</code>.', $i + 1));
            }
            $clean['fields'] = $fields_out;
            $show_if = self::sanitize_show_if($step);
            if ($show_if !== null) $clean['showIf'] = $show_if;
            // requireOneOf: "pelo menos um destes campos" — o JS já valida
            // (validateCurrent); sem preservar aqui o import perdia a regra.
            // Só aceita chaves de campos DESTA etapa (é onde o JS foca o erro).
            if (isset($step['requireOneOf']) && is_array($step['requireOneOf'])) {
                $step_keys = array_column($fields_out, 'key');
                $req = array();
                foreach ($step['requireOneOf'] as $rk) {
                    $rk = self::sanitize_key_name((string) $rk);
                    if ($rk !== '' && in_array($rk, $step_keys, true)) $req[] = $rk;
                }
                if (!empty($req)) {
                    $clean['requireOneOf'] = $req;
                    if (isset($step['requireOneOfMsg']) && $step['requireOneOfMsg'] !== '') {
                        $clean['requireOneOfMsg'] = sanitize_text_field((string) $step['requireOneOfMsg']);
                    }
                }
            }
            $out[] = $clean;
            $questions++;
        }

        if ($questions === 0) {
            return array('ok' => false, 'error' => 'O roteiro não tem nenhuma pergunta — só abertura e final.');
        }

        // O CRM recusa lead sem e-mail E sem telefone. Barrar aqui evita
        // descobrir isso só quando o primeiro visitante terminar o form.
        if (!in_array('your-email', $canonicals, true) && !in_array('Telefone', $canonicals, true)) {
            return array('ok' => false, 'error' => 'O roteiro precisa de pelo menos uma pergunta com <code>"canonical": "your-email"</code> ou <code>"canonical": "Telefone"</code> — sem e-mail nem telefone o CRM recusa o lead.');
        }

        // Tela de sucesso é sempre a última (o JS conta a partir dela pra
        // saber qual etapa é a de envio).
        $out[] = array('isSuccess' => true);

        return array('ok' => true, 'steps' => $out);
    }

    /** Chave de campo/canônico: sem espaço, acento ou char estranho. */
    private static function sanitize_key_name($raw) {
        $s = trim((string) $raw);
        if ($s === '') return '';
        if (function_exists('remove_accents')) $s = remove_accents($s);
        $s = preg_replace('/[^A-Za-z0-9_-]+/', '-', $s);
        $s = preg_replace('/-+/', '-', $s);
        return trim($s, '-');
    }

    /**
     * Mapa key-da-resposta → chave canônica do CRM, derivado do roteiro
     * custom. Vazio quando o site roda o roteiro default (aí vale o
     * mapeamento fixo da Digitals em handle_submit).
     */
    public static function canonical_map() {
        $map = array();
        foreach (self::get_steps() as $step) {
            if (!is_array($step)) continue;
            if (!empty($step['fieldKey'])) {
                $map[$step['fieldKey']] = !empty($step['canonical']) ? $step['canonical'] : $step['fieldKey'];
            }
            foreach ((array) (isset($step['fields']) ? $step['fields'] : array()) as $f) {
                if (empty($f['key'])) continue;
                $map[$f['key']] = !empty($f['canonical']) ? $f['canonical'] : $f['key'];
            }
        }
        return $map;
    }

    /** Enqueue do CSS/JS/fonte + config. Idempotente (WP dedupa por handle). */
    public static function enqueue_assets($mode = 'popup', $button_label = 'Iniciar avaliação', $central_slug = '') {
        $version = defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : '2.3.0';
        // Fontes do mockup (Inter + Open Sans), dep do CSS.
        wp_enqueue_style(
            'adspirit-qualifier-fonts',
            'https://fonts.googleapis.com/css2?family=Inter:wght@200;300;400;500&family=Open+Sans:wght@400;500;600;700&display=swap',
            array(),
            null
        );
        wp_enqueue_style(
            'adspirit-qualifier-form',
            ADSPIRIT_CONNECTOR_URL . 'assets/qualifier-form.css',
            array('adspirit-qualifier-fonts'),
            $version
        );
        wp_enqueue_script(
            'adspirit-qualifier-form',
            ADSPIRIT_CONNECTOR_URL . 'assets/qualifier-form.js',
            array(),
            $version,
            true
        );
        $core = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();
        // Central de Forms: resolve o form pedido no shortcode (fail-soft).
        $central_form = null;
        $central_steps = null;
        if ($central_slug !== '' && class_exists('AdSpirit_Central_Forms')) {
            $central_form = AdSpirit_Central_Forms::get($central_slug);
            if (is_array($central_form) && !empty($central_form['steps'])) {
                $central_steps = $central_form['steps'];
            } else {
                $central_form = null; // sem roteiro utilizável → precedência normal
            }
        }
        // v2.10: Turnstile config (apenas se Turnstile ativo + aplica ao qualifier)
        $turnstile_cfg = array('enabled' => false, 'site_key' => '');
        if (class_exists('AdSpirit_Turnstile') && AdSpirit_Turnstile::applies_to_qualifier()) {
            $ts = AdSpirit_Turnstile::get_settings();
            $turnstile_cfg = array('enabled' => true, 'site_key' => (string) $ts['site_key']);
        }
        wp_localize_script('adspirit-qualifier-form', 'AdSpiritQualifierCfg', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('adspirit_qualifier_submit'),
            'brand_slug' => (string) ($core['brand_slug'] ?? ''),
            'mode' => $mode,
            'button_label' => esc_html($button_label),
            'turnstile' => $turnstile_cfg,
            // Roteiro custom do tenant. Array vazio → o JS usa DEFAULT_STEPS
            // (Digitals) e nada muda pra quem nunca importou roteiro.
            // Central de Forms (Fase 1): shortcode com form="slug" usa o
            // roteiro da Central; indisponível → cai no local/embutido.
            'steps' => $central_steps !== null ? $central_steps : self::get_steps(),
            'central_form' => $central_form !== null ? $central_slug : '',
            // Pré-visualização (hub Formulários): avança sem preencher e
            // nunca envia — revisão sem gerar dados.
            'preview' => defined('ADSPIRIT_QF_PREVIEW'),
            // Habilita o gatilho extra a.lead/button.lead no front (JS só o
            // ativa quando o "site todo" está ligado).
            'sitewide' => (string) (self::get_settings()['sitewide'] ?? '0'),
        ));
    }

    /** Site todo: enqueue dos assets (no head) quando o toggle está ligado. */
    public function maybe_enqueue_sitewide() {
        if (is_admin()) return;
        $s = self::get_settings();
        if (($s['sitewide'] ?? '0') !== '1') return;
        self::enqueue_assets('trigger');
    }

    /** Site todo: injeta o root (trigger) no rodapé. Idempotente por página. */
    public function maybe_inject_sitewide_root() {
        if (is_admin()) return;
        $s = self::get_settings();
        if (($s['sitewide'] ?? '0') !== '1') return;
        echo '<div class="adspirit-qualifier-root" data-mode="popup" hidden data-adspirit-sitewide="1"></div>';
    }

    public function handle_save($post) {
        $section = isset($post['qualifier_section']) ? sanitize_key((string) $post['qualifier_section']) : 'sitewide';
        $settings = self::get_settings();

        if ($section === 'steps') {
            $this->handle_steps_save($post, $settings);
            return;
        }

        if ($section === 'template') {
            $this->handle_template_save($post, $settings);
            return;
        }

        // Card "site todo". O checkbox só existe nesse form — por isso o
        // patch é escopado: sem o marcador de seção, salvar o roteiro
        // desligaria o sitewide sem ninguém pedir.
        $settings['sitewide'] = !empty($post['sitewide']) ? '1' : '0';
        update_option(self::OPTION_KEY, $settings, false);
        add_settings_error(self::OPTION_KEY, 'saved', 'Configurações do form salvas.', 'updated');
    }

    /** Importa/remove o roteiro custom de perguntas. */
    private function handle_steps_save($post, $settings) {
        if (!empty($post['reset_steps'])) {
            $settings['steps'] = array();
            update_option(self::OPTION_KEY, $settings, false);
            add_settings_error(self::OPTION_KEY, 'steps_reset', 'Roteiro custom removido — o form voltou pras perguntas padrão.', 'updated');
            return;
        }

        // wp_unslash antes do decode: o WP escapa aspas no $_POST e o JSON
        // chega com \" — sem isso, json_decode devolve null sempre.
        $raw = isset($post['steps_json']) ? (string) wp_unslash($post['steps_json']) : '';
        if (trim($raw) === '') {
            add_settings_error(self::OPTION_KEY, 'steps_empty', 'Cole o JSON do roteiro pra importar. Nada foi alterado.');
            return;
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            add_settings_error(self::OPTION_KEY, 'steps_json', 'JSON inválido: ' . esc_html(json_last_error_msg()) . '. Nada foi alterado.');
            return;
        }
        // Aceita a lista crua ou embrulhada em { "steps": [...] }.
        if (isset($decoded['steps']) && is_array($decoded['steps'])) {
            $decoded = $decoded['steps'];
        }

        $res = self::sanitize_steps($decoded);
        if (empty($res['ok'])) {
            add_settings_error(self::OPTION_KEY, 'steps_invalid', $res['error']);
            return;
        }

        $settings['steps'] = $res['steps'];
        update_option(self::OPTION_KEY, $settings, false);

        // F3: roteiro feito à mão ganha uma régua inicial derivada das
        // próprias perguntas no CRM (origin auto:roteiro — nunca sobrescreve
        // régua manual). Falha aqui não desfaz o import.
        $regua = $this->notify_roteiro_imported($res['steps']);
        $base = sprintf(
            'Roteiro importado — %d telas no total (abertura + perguntas + tela final). Confira numa página publicada.',
            count($res['steps'])
        );
        if ($regua === 'applied') {
            $base .= ' Uma régua inicial foi derivada das suas perguntas no AdSpirit — revise em Configurações → Lead scoring antes de confiar.';
        } elseif ($regua === 'manual_config') {
            $base .= ' A marca já tem régua própria no AdSpirit; ela foi mantida.';
        } else {
            $base .= ' Não consegui gerar a régua automaticamente — configure em Configurações → Lead scoring.';
        }
        add_settings_error(self::OPTION_KEY, 'steps_ok', $base, 'updated');
    }

    /** POSTa o roteiro recém-importado pro CRM derivar a régua inicial.
     *  Retorna 'applied' | 'manual_config' | 'failed'. */
    private function notify_roteiro_imported($steps) {
        if (!class_exists('AdSpirit_Connect') || !AdSpirit_Connect::is_connected()) return 'failed';
        $core = AdSpirit_Settings::get_core();
        $resp = wp_remote_post(rtrim($core['endpoint_url'], '/') . '/api/wp/qualifier-roteiro', array(
            'timeout' => 10,
            'headers' => array('Content-Type' => 'application/json', 'x-cf7-secret' => $core['secret']),
            'body' => wp_json_encode(array('brand_slug' => $core['brand_slug'], 'steps' => $steps)),
        ));
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) return 'failed';
        $body = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (is_array($body) && !empty($body['applied'])) return 'applied';
        if (is_array($body) && isset($body['reason']) && $body['reason'] === 'manual_config') return 'manual_config';
        return 'failed';
    }

    /** Galeria: busca os modelos prontos no CRM. Cache 1h em option;
     *  fail-soft pro cache anterior quando a rede falha. */
    private function fetch_templates($force = false) {
        $cached = get_option('adspirit_qualifier_templates', null);
        $at = (int) get_option('adspirit_qualifier_templates_at', 0);
        if (!$force && is_array($cached) && (time() - $at) < 3600) {
            return $cached;
        }
        if (!class_exists('AdSpirit_Connect') || !AdSpirit_Connect::is_connected()) {
            return is_array($cached) ? $cached : null;
        }
        $core = AdSpirit_Settings::get_core();
        $url = rtrim($core['endpoint_url'], '/')
            . '/api/wp/qualifier-templates?brand_slug=' . rawurlencode($core['brand_slug']);
        $resp = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array('x-cf7-secret' => $core['secret'], 'Accept' => 'application/json'),
        ));
        if (is_wp_error($resp) || wp_remote_retrieve_response_code($resp) !== 200) {
            return is_array($cached) ? $cached : null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($data) || empty($data['templates']) || !is_array($data['templates'])) {
            return is_array($cached) ? $cached : null;
        }
        update_option('adspirit_qualifier_templates', $data['templates'], false);
        update_option('adspirit_qualifier_templates_at', time(), false);
        return $data['templates'];
    }

    /** Aplica um modelo da galeria: importa o roteiro (mesma validação do
     *  import por JSON) e avisa o CRM pra aplicar a régua correspondente.
     *  O CRM NUNCA sobrescreve régua manual — responde reason=manual_config. */
    private function handle_template_save($post, $settings) {
        $tid = isset($post['template_id']) ? sanitize_text_field((string) $post['template_id']) : '';
        if ($tid === '') {
            add_settings_error(self::OPTION_KEY, 'tpl_missing', 'Escolha um modelo antes de aplicar.');
            return;
        }
        $templates = $this->fetch_templates(true);
        if (!is_array($templates)) {
            add_settings_error(self::OPTION_KEY, 'tpl_fetch', 'Não consegui buscar os modelos no AdSpirit. Confira a aba Conexão.');
            return;
        }
        $tpl = null;
        foreach ($templates as $t) {
            if (isset($t['id']) && $t['id'] === $tid) { $tpl = $t; break; }
        }
        if (!$tpl || empty($tpl['steps']) || !is_array($tpl['steps'])) {
            add_settings_error(self::OPTION_KEY, 'tpl_notfound', 'Modelo não encontrado. Recarregue a página e tente de novo.');
            return;
        }
        $res = self::sanitize_steps($tpl['steps']);
        if (empty($res['ok'])) {
            add_settings_error(self::OPTION_KEY, 'tpl_invalid', 'O modelo veio inválido do CRM: ' . $res['error']);
            return;
        }
        $settings['steps'] = $res['steps'];
        update_option(self::OPTION_KEY, $settings, false);

        // Adoção da régua no CRM (fire com feedback; falha não desfaz o roteiro).
        $core = AdSpirit_Settings::get_core();
        $resp = wp_remote_post(rtrim($core['endpoint_url'], '/') . '/api/wp/qualifier-templates', array(
            'timeout' => 10,
            'headers' => array('Content-Type' => 'application/json', 'x-cf7-secret' => $core['secret']),
            'body' => wp_json_encode(array('brand_slug' => $core['brand_slug'], 'template_id' => $tid)),
        ));
        $applied = false;
        $reason = '';
        if (!is_wp_error($resp) && wp_remote_retrieve_response_code($resp) === 200) {
            $body = json_decode((string) wp_remote_retrieve_body($resp), true);
            $applied = is_array($body) && !empty($body['applied']);
            $reason = is_array($body) && isset($body['reason']) ? (string) $body['reason'] : '';
        }

        $telas = count($res['steps']);
        if ($applied) {
            add_settings_error(self::OPTION_KEY, 'tpl_ok', sprintf(
                'Modelo aplicado — %d telas. A régua de pontuação correspondente foi aplicada no AdSpirit; revise em Configurações → Lead scoring.',
                $telas
            ), 'updated');
        } elseif ($reason === 'manual_config') {
            add_settings_error(self::OPTION_KEY, 'tpl_ok_manual', sprintf(
                'Modelo aplicado — %d telas. A marca já tem régua própria no AdSpirit e ela foi MANTIDA (modelo nunca sobrescreve configuração manual).',
                $telas
            ), 'updated');
        } else {
            add_settings_error(self::OPTION_KEY, 'tpl_ok_noscore', sprintf(
                'Modelo aplicado — %d telas. Não consegui aplicar a régua automaticamente; configure em Configurações → Lead scoring no AdSpirit.',
                $telas
            ), 'updated');
        }
    }

    public function register_tab($tabs) {
        $tabs['qualifier'] = 'Form de avaliação';
        return $tabs;
    }

    /** Guia visual: qual shortcode escolher + como abrir via botão próprio. */
    public function render_tab() {
        $modes = array(
            array('Botão "Iniciar avaliação"', 'Mostra um botão; ao clicar, abre o form em TELA CHEIA. Bom pra CTA em hero/seção escura.', '[adspirit_form_qualifier]'),
            array('Contido na seção', 'O form aparece DENTRO da seção (card escuro, sem cobrir a tela). Pra encaixar numa landing com hero + seção.', '[adspirit_form_qualifier mode="embed"]'),
            array('Abre sozinho (tela cheia)', 'O form abre em tela cheia assim que a página carrega, sem botão. Pra página dedicada só do form.', '[adspirit_form_qualifier mode="inline"]'),
            array('Com o SEU botão', 'Não renderiza botão — você usa um botão próprio (estilizado no builder) pra abrir. Coloque este shortcode uma vez na página.', '[adspirit_form_qualifier mode="trigger"]'),
        );
        $qs = self::get_settings();
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Form</span>Form de avaliação (qualifier)</h2>
        <p class="as-section-help">O form de avaliação com o design da agência (preto + glassmorphism, multi-step BANT). <strong>Não confunda</strong> com <code>[adspirit_form]</code> (form branco genérico antigo). Veja na PÁGINA publicada — no editor do builder ele aparece vazio (é montado por JavaScript).</p>

        <?php AdSpirit_Menu::card_open('Disponibilizar no site todo', 'Sem precisar do shortcode em cada página', ($qs['sitewide'] ?? '0') === '1' ? '<span class="as-badge ok">Ligado</span>' : '<span class="as-badge muted">Desligado</span>'); ?>
        <?php AdSpirit_Menu::form_open('qualifier'); ?>
        <input type="hidden" name="qualifier_section" value="sitewide">
        <div class="as-toggle" style="margin-top:6px;">
            <input type="checkbox" id="as_qsite" name="sitewide" value="1" <?php checked($qs['sitewide'] ?? '0', '1'); ?>>
            <label class="t" for="as_qsite">Carregar o form em todas as páginas (escondido até clicarem)
                <small>Sem shortcode em página nenhuma: um botão com link <code>#adspirit-avaliacao</code> ou classe <code>agd_lead</code> abre o form. Custo: o CSS/JS carrega no site todo.</small>
            </label>
        </div>
        <?php AdSpirit_Menu::form_close('Salvar'); ?>
        <?php AdSpirit_Menu::card_close(); ?>

        <?php
        $custom_steps = self::get_steps();
        $is_custom = !empty($custom_steps);
        $badge = $is_custom
            ? '<span class="as-badge ok">Roteiro próprio · ' . count($custom_steps) . ' telas</span>'
            : '<span class="as-badge muted">Perguntas padrão</span>';
        AdSpirit_Menu::card_open(
            'Perguntas do formulário',
            'Cole um roteiro em JSON pra este site fazer outras perguntas. O formulário continua o mesmo — mesma tela cheia, mesmos cards de escolha, mesmo lead parcial —, muda só o conteúdo.',
            $badge
        );
        ?>
        <?php // Doutrina: JSON é avançado — recolhido; o caminho feliz é a
              // galeria de modelos logo abaixo. ?>
        <?php if ($is_custom): ?>
            <p style="margin:0 0 6px; color:var(--as-ink-soft);">Este site roda um roteiro próprio.</p>
            <details class="as-help">
                <summary>Ver o roteiro no ar (JSON)</summary>
                <textarea readonly rows="8" style="width:100%; font-family:monospace; font-size:11.5px; margin-top:8px;" onclick="this.select();"><?php
                    echo esc_textarea(wp_json_encode($custom_steps, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
                ?></textarea>
            </details>
        <?php else: ?>
            <p style="margin:0 0 6px; color:var(--as-ink-soft);">Este site usa as perguntas padrão. Importar um roteiro <strong>não apaga</strong> nada — dá pra voltar ao padrão a qualquer momento.</p>
        <?php endif; ?>

        <details class="as-help">
            <summary>Importar roteiro em JSON (avançado)</summary>
            <?php AdSpirit_Menu::form_open('qualifier'); ?>
            <input type="hidden" name="qualifier_section" value="steps">
            <textarea name="steps_json" rows="8" style="width:100%; font-family:monospace; font-size:11.5px; margin-top:8px;" placeholder='[{"isIntro": true, "eyebrow": "...", "title": "...", "sub": "..."}, ...]'></textarea>
            <p class="submit" style="margin-top:10px;">
                <button type="submit" class="button button-primary">Importar roteiro</button>
                <?php if ($is_custom): ?>
                    <button type="submit" class="button-link" name="reset_steps" value="1"
                            onclick="return confirm('Voltar pras perguntas padrão? O roteiro próprio deste site será removido.');">voltar ao padrão</button>
                <?php endif; ?>
            </p>
            </form>
        </details>

        <?php // Doutrina 08-18: referência técnica é NOTA DE RODAPÉ — texto
              // menor, sem box, hierarquia abaixo da ação de importar. ?>
        <details class="as-footnote" style="margin-top:14px;">
            <summary style="cursor:pointer; font-size:12px; color:var(--as-ink-faint); font-weight:400;">Referência: como o JSON é montado</summary>
            <p style="margin:8px 0 4px; font-size:12px; color:var(--as-ink-faint);">Uma tela por objeto, na ordem. A primeira é a abertura (<code>isIntro</code>); a tela final de sucesso é acrescentada sozinha.</p>
            <ul style="margin:0; padding-left:16px; line-height:1.8; font-size:12px; color:var(--as-ink-faint);">
                <li><strong>Pergunta com opções</strong> (os cards): <code>title</code>, <code>fieldKey</code>, <code>canonical</code> e <code>choices</code> — cada opção com <code>label</code> e, se quiser, <code>meta</code>. O atalho de teclado é atribuído sozinho.</li>
                <li><strong>Pergunta aberta</strong>: <code>title</code> e <code>fields</code>, cada campo com <code>key</code>, <code>type</code> (text, email, tel, url, textarea), <code>placeholder</code> e <code>canonical</code>.</li>
                <li><strong><code>canonical</code></strong> é o nome com que a resposta chega no CRM: <code>your-name</code>, <code>your-email</code>, <code>Telefone</code>, <code>empresa</code>, <code>cargo</code>, <code>revenue</code>, <code>Investimento</code>, <code>urgencia</code>, <code>pain</code>. Dois campos com o mesmo <code>canonical</code> chegam juntos (é assim que Nome + Sobrenome viram um nome só).</li>
                <li><strong><code>"capturePartial": true</code></strong> na etapa de contato: ao passar dela, o lead já é gravado no CRM mesmo se a pessoa abandonar depois.</li>
                <li><strong><code>"optional": true</code></strong> na etapa deixa a resposta opcional.</li>
                <li><strong><code>"showIf"</code></strong> mostra a etapa só quando respostas anteriores batem: <code>{"match": "all", "rules": [{"field": "porte", "op": "is", "value": "..."}]}</code>. Operadores: <code>is</code>, <code>not</code>, <code>contains</code>; <code>match</code> aceita <code>all</code> ou <code>any</code>. Etapa pulada não envia a resposta. Sem <code>showIf</code>, a etapa aparece sempre.</li>
            </ul>
        </details>
        <?php AdSpirit_Menu::card_close(); ?>

        <?php
        $tpls = $this->fetch_templates();
        AdSpirit_Menu::card_open(
            'Modelos prontos',
            'Roteiros de referência por mercado, direto do AdSpirit. Aplicar um modelo importa o formulário E a régua de pontuação correspondente.'
        );
        if (!class_exists('AdSpirit_Connect') || !AdSpirit_Connect::is_connected()): ?>
            <p style="margin:0; color:var(--as-ink-faint); font-size:13px;">Conecte o site ao AdSpirit (aba <strong>Conexão</strong>) pra ver os modelos.</p>
        <?php elseif (!is_array($tpls) || empty($tpls)): ?>
            <p style="margin:0; color:var(--as-ink-faint); font-size:13px;">Não consegui carregar os modelos agora — recarregue a página pra tentar de novo.</p>
        <?php else: ?>
            <?php AdSpirit_Menu::form_open('qualifier'); ?>
            <input type="hidden" name="qualifier_section" value="template">
            <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                <select name="template_id" style="min-width:320px;">
                    <option value="">— escolha um mercado —</option>
                    <?php foreach ($tpls as $t): if (empty($t['id']) || empty($t['label'])) continue; ?>
                        <option value="<?php echo esc_attr($t['id']); ?>"><?php echo esc_html($t['label']); ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="button button-primary"
                        onclick="return confirm('Aplicar este modelo substitui o roteiro atual deste site. Continuar?');">Aplicar modelo</button>
            </div>
            </form>
            <ul style="margin:12px 0 0; padding-left:18px; line-height:1.8; color:var(--as-ink-soft); font-size:12.5px;">
                <?php foreach ($tpls as $t): if (empty($t['label'])) continue; ?>
                    <li><strong><?php echo esc_html($t['label']); ?></strong><?php echo !empty($t['description']) ? ' — ' . esc_html($t['description']) : ''; ?></li>
                <?php endforeach; ?>
            </ul>
            <p class="as-field-help" style="margin-top:10px;">O modelo é um ponto de partida: depois de aplicar, edite o JSON no card acima pra ajustar perguntas e opções — e ajuste a régua no AdSpirit junto.</p>
        <?php endif; ?>
        <?php AdSpirit_Menu::card_close(); ?>

        <?php AdSpirit_Menu::card_open('Qual shortcode usar', 'Se NÃO ligar o "site todo", escolha conforme onde o form vai aparecer'); ?>
        <table class="as-table" style="width:100%;">
            <thead><tr><th style="width:200px;">Quero…</th><th>O que faz</th><th style="width:340px;">Shortcode (copie)</th></tr></thead>
            <tbody>
            <?php foreach ($modes as $m): ?>
                <tr>
                    <td><strong><?php echo esc_html($m[0]); ?></strong></td>
                    <td><?php echo esc_html($m[1]); ?></td>
                    <td>
                        <div style="display:flex; gap:6px; align-items:center;">
                            <input type="text" readonly value="<?php echo esc_attr($m[2]); ?>" class="regular-text code" style="flex:1; font-size:12px;" onclick="this.select();">
                            <button type="button" class="button as-copy" data-copy="<?php echo esc_attr($m[2]); ?>">Copiar</button>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <?php AdSpirit_Menu::card_close(); ?>

        <?php AdSpirit_Menu::card_open('Abrir com o seu próprio botão', 'Botão estilizado no seu builder, sem o botão do plugin — em 2 passos'); ?>
        <div style="display:grid; gap:18px;">
            <div>
                <p style="margin:0 0 8px; font-weight:600; color:var(--as-ink);">1. Deixe o form disponível na página <span style="font-weight:400; color:var(--as-ink-faint);">(escolha um)</span></p>
                <ul style="margin:0; padding-left:18px; line-height:1.85; color:var(--as-ink-soft);">
                    <li>Ligue <strong>"Site todo"</strong> no card acima — recomendado, vale pra todas as páginas, ou</li>
                    <li>Cole <code>[adspirit_form_qualifier mode="trigger"]</code> uma vez na página onde o botão fica (não aparece nada, só carrega o form).</li>
                </ul>
            </div>
            <div>
                <p style="margin:0 0 8px; font-weight:600; color:var(--as-ink);">2. Aponte o seu botão pro form</p>
                <p style="margin:0 0 8px; color:var(--as-ink-soft);">No campo <strong>Link / URL</strong> do botão (no builder), use:</p>
                <div style="display:flex; gap:6px; align-items:center; max-width:320px;">
                    <input type="text" readonly value="#adspirit-avaliacao" class="regular-text code" style="flex:1; font-size:12px;" onclick="this.select();">
                    <button type="button" class="button as-copy" data-copy="#adspirit-avaliacao">Copiar</button>
                </div>
                <p class="description" style="margin-top:10px;"><strong>Sem campo de link no botão?</strong> Adicione a ele o atributo <code>data-adspirit-qualifier</code>, ou a classe <code>adspirit-qualifier-trigger</code> (essa herda o estilo do plugin — prefira o link ou o atributo pra manter o seu design).</p>
            </div>
        </div>
        <?php AdSpirit_Menu::card_close(); ?>

        <script>
        (function(){
            document.querySelectorAll('.as-copy').forEach(function(btn){
                btn.addEventListener('click', function(){
                    var txt = btn.getAttribute('data-copy') || '';
                    var done = function(){ var o = btn.textContent; btn.textContent = 'Copiado!'; setTimeout(function(){ btn.textContent = o; }, 1400); };
                    if (navigator.clipboard && navigator.clipboard.writeText) {
                        navigator.clipboard.writeText(txt).then(done).catch(done);
                    } else {
                        var t = document.createElement('textarea'); t.value = txt; document.body.appendChild(t); t.select();
                        try { document.execCommand('copy'); } catch(e){}
                        document.body.removeChild(t); done();
                    }
                });
            });
        })();
        </script>
        <?php
    }

    /**
     * Render shortcode.
     *   atts: mode="popup"|"inline"|"embed"|"trigger" (default popup), button_label="..."
     *     - popup:   botão CTA → form em tela cheia (overlay)
     *     - inline:  form em tela cheia, abre direto no load (sem botão)
     *     - embed:   form contido na seção (card dark, sem overlay full-screen)
     *     - trigger: só o popup (sem botão) → você usa SEU botão pra abrir.
     *                Qualquer elemento abre o form se tiver:
     *                  • class "adspirit-qualifier-trigger", OU
     *                  • atributo data-adspirit-qualifier, OU
     *                  • link href="#adspirit-avaliacao"
     */
    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'mode' => 'popup',
            'button_label' => 'Iniciar avaliação',
            // Central de Forms: identificador do form gerenciado no AdSpirit.
            // Vazio = comportamento de sempre (local > embutido).
            'form' => '',
        ), $atts, 'adspirit_form_qualifier');
        $mode = in_array($atts['mode'], array('inline', 'embed', 'trigger'), true) ? $atts['mode'] : 'popup';

        self::enqueue_assets($mode, $atts['button_label'], sanitize_key((string) $atts['form']));

        // Trigger: SÓ o popup (sem botão). Use seu PRÓPRIO botão pra abrir —
        // qualquer elemento com data-adspirit-qualifier, class adspirit-qualifier-trigger,
        // ou link href="#adspirit-avaliacao" dispara o form.
        if ($mode === 'trigger') {
            return '<div class="adspirit-qualifier-root" data-mode="popup" hidden></div>';
        }
        // Embed: card contido na seção (sem overlay). Inline: tela cheia no load.
        if ($mode === 'embed' || $mode === 'inline') {
            return '<div class="adspirit-qualifier-root" data-mode="' . esc_attr($mode) . '"></div>';
        }
        // Popup mode: botão CTA que dispara o popup em tela cheia
        return sprintf(
            '<button type="button" class="adspirit-qualifier-trigger">%s</button><div class="adspirit-qualifier-root" data-mode="popup" hidden></div>',
            esc_html($atts['button_label'])
        );
    }

    /**
     * AJAX handler: recebe payload do form, repassa pro CRM, retorna
     * { redirect_url, profile } pro JS fazer countdown + redirect.
     */
    // Devolve um nonce fresco do action de submit. Sem auth de propósito:
    // o nonce de visitante anônimo é público por natureza (vem no HTML de
    // qualquer pageview) — aqui só contornamos o page cache.
    public function handle_nonce() {
        nocache_headers();
        wp_send_json_success(array(
            'nonce' => wp_create_nonce('adspirit_qualifier_submit'),
        ));
    }

    public function handle_submit() {
        // Nonce check
        $nonce = isset($_POST['nonce']) ? (string) $_POST['nonce'] : '';
        if (!wp_verify_nonce($nonce, 'adspirit_qualifier_submit')) {
            wp_send_json_error(array('error' => 'bad_nonce'), 403);
            return;
        }

        // Rate limit por IP REAL (não REMOTE_ADDR puro — colapsa em proxy
        // Cloudflare/EasyPanel). Lê CF-Connecting-IP > X-Forwarded-For
        // (primeiro IP) > REMOTE_ADDR. Cap 30/min (não 5 — atrás de proxy
        // o bucket era global). Adicionalmente, bucket por email pra
        // bloquear flood com 1 email só.
        // REMOTE_ADDR é sempre presente em CGI normal. Headers de proxy
        // são lidos pra deduzir IP real quando atrás de Cloudflare/proxy,
        // mas REQUER REMOTE_ADDR presente pra confirmar que o request
        // veio de um proxy real (não direto de internet falsificando
        // headers). Sem REMOTE_ADDR: negar (caso anômalo, CLI ou bug).
        if (empty($_SERVER['REMOTE_ADDR'])) {
            wp_send_json_error(array('error' => 'no_ip'), 400);
            return;
        }
        // Cascata delegada ao helper canônico (v2.30). O deny acima quando
        // falta REMOTE_ADDR permanece — é a garantia de que headers de proxy
        // só são honrados com um proxy real intermediando.
        $ip = class_exists('AdSpirit_Telemetry') ? AdSpirit_Telemetry::client_ip() : '';
        if ($ip === '') $ip = (string) $_SERVER['REMOTE_ADDR'];
        $bucket = 'adspirit_qualifier_rl_' . md5($ip);
        $hits = (int) get_transient($bucket);
        if ($hits >= 30) {
            wp_send_json_error(array('error' => 'rate_limit'), 429);
            return;
        }
        set_transient($bucket, $hits + 1, 60);

        // v2.9: anti-spam unificado — sempre on no qualifier (decisão §91).
        // Roda mesmas 6 checagens do CF7 (honeypot, time_trap, rate_limit,
        // UA, reverse_text, blocklist) via helper estático.
        if (class_exists('AdSpirit_Anti_Spam')) {
            $email_for_check = isset($_POST['fields']['email']) ? (string) $_POST['fields']['email'] : '';
            $payload_for_check = is_array($_POST['fields'] ?? null) ? (array) $_POST['fields'] : array();
            // Inclui meta antibot do payload top-level (honeypot + timestamp)
            if (isset($_POST['_adspirit_hp'])) $payload_for_check[AdSpirit_Anti_Spam::HONEYPOT_FIELD] = (string) $_POST['_adspirit_hp'];
            if (isset($_POST['_adspirit_ts'])) $payload_for_check['_adspirit_ts'] = (string) $_POST['_adspirit_ts'];
            $check = AdSpirit_Anti_Spam::validate_payload($payload_for_check, $email_for_check);
            if (empty($check['valid'])) {
                if (method_exists('AdSpirit_Anti_Spam', 'log_block')) {
                    AdSpirit_Anti_Spam::instance()->log_block(
                        'qualifier_' . ($check['reason_code'] ?? 'unknown'),
                        $check['reason_text'] ?? 'rejected by anti-spam'
                    );
                }
                // Connector 3.0 — quarentena revisável (nunca descarta em
                // silêncio): falso positivo se resgata na aba Submissões.
                if (class_exists('AdSpirit_Lead_Store')) {
                    AdSpirit_Lead_Store::record_spam(
                        $payload_for_check,
                        'qualifier',
                        'adspirit_form_qualifier',
                        ($check['reason_code'] ?? 'unknown') . ': ' . ($check['reason_text'] ?? 'rejected')
                    );
                }
                wp_send_json_error(array('error' => 'spam_blocked', 'reason' => $check['reason_code']), 403);
                return;
            }
        }

        // v2.10: Cloudflare Turnstile (anti-bot avançado) — opt-in via config.
        // Roda APÓS anti-spam básico pra economizar chamada externa em bots
        // óbvios. Fail-open em erro de rede do Cloudflare.
        if (class_exists('AdSpirit_Turnstile') && AdSpirit_Turnstile::applies_to_qualifier()) {
            $token = isset($_POST['_adspirit_turnstile']) ? (string) $_POST['_adspirit_turnstile'] : '';
            $cf_check = AdSpirit_Turnstile::verify_token($token);
            if (empty($cf_check['valid'])) {
                if (class_exists('AdSpirit_Anti_Spam') && method_exists('AdSpirit_Anti_Spam', 'log_block')) {
                    AdSpirit_Anti_Spam::instance()->log_block('qualifier_turnstile', $cf_check['reason'] ?? 'rejected');
                }
                wp_send_json_error(array('error' => 'spam_blocked', 'reason' => 'turnstile'), 403);
                return;
            }
        }

        // Lê fields do POST. Pra textarea (pain, notes), usa
        // sanitize_textarea_field pra preservar line breaks; demais
        // campos usam sanitize_text_field (single-line).
        $fields = isset($_POST['fields']) && is_array($_POST['fields']) ? $_POST['fields'] : array();
        $textarea_keys = array('pain', 'notes', 'message', 'mensagem');
        $sanitized = array();
        foreach ($fields as $key => $value) {
            $k = sanitize_key((string) $key);
            if (!is_scalar($value)) {
                $sanitized[$k] = '';
                continue;
            }
            $raw = (string) $value;
            $sanitized[$k] = in_array($k, $textarea_keys, true)
                ? sanitize_textarea_field($raw)
                : sanitize_text_field($raw);
        }

        // Lead PARCIAL: disparado após a etapa de contato (email+WhatsApp).
        // submission_id (do front) liga parcial e final via o mesmo id-base
        // (parcial usa sufixo "-p"); o CRM dedupa por contato e promove o
        // lead parcial in-place quando o final chega.
        $is_partial = !empty($_POST['_adspirit_partial']);
        $client_sid = isset($_POST['submission_id'])
            ? sanitize_text_field((string) $_POST['submission_id'])
            : '';

        // Roteiro custom (outro tenant): o mapeamento key → canônico vem da
        // config, não do de-para fixo da Digitals abaixo. Dois campos no
        // mesmo canônico entram concatenados na ordem do roteiro (é assim
        // que "Nome" + "Sobrenome" viram um `your-name` só).
        $custom_map = self::canonical_map();
        if (!empty($custom_map)) {
            $payload = array();
            foreach ($custom_map as $key => $canonical) {
                $val = isset($sanitized[$key]) ? trim((string) $sanitized[$key]) : '';
                if ($val === '') continue;
                $payload[$canonical] = (isset($payload[$canonical]) && $payload[$canonical] !== '')
                    ? $payload[$canonical] . ' ' . $val
                    : $val;
            }
            $payload['cf7_time'] = current_time('c');
            $payload['cf7_url'] = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : home_url('/');
            return $this->dispatch_payload($payload, $is_partial, $client_sid);
        }

        // Presença online: form coleta Instagram + site separados (pelo menos
        // um obrigatório). Combinamos no campo canônico `site-empresa` —
        // mesmo destino do antigo `social`, então o CRM/website column não muda.
        // Site (URL) tem prioridade; Instagram entra junto quando presente.
        $site_in  = trim((string) ($sanitized['site'] ?? ''));
        $insta_in = trim((string) ($sanitized['instagram'] ?? ''));
        $presence = $site_in;
        if ($insta_in !== '') {
            $presence = ($presence === '') ? $insta_in : $presence . ' · ' . $insta_in;
        }
        // Back-compat: sessões antigas ainda mandam `social` num campo só.
        if ($presence === '' && !empty($sanitized['social'])) {
            $presence = (string) $sanitized['social'];
        }

        // Mapeia pros field names canônicos do CF7 (pra Elisa/n8n continuar reconhecendo)
        $payload = array(
            'your-name' => trim(($sanitized['first_name'] ?? '') . ' ' . ($sanitized['last_name'] ?? '')),
            'your-email' => $sanitized['email'] ?? '',
            'Telefone' => $sanitized['phone'] ?? '',
            'site-empresa' => $presence,
            'instagram' => $insta_in,
            'empresa' => $sanitized['company'] ?? '',
            'cargo' => $sanitized['role'] ?? '',
            'Numero-funcionarios' => $sanitized['size'] ?? '',
            'nicho' => $sanitized['market'] ?? '',
            'ExperienciacomMarketing' => $sanitized['experience'] ?? '',
            'revenue' => $sanitized['revenue'] ?? '',
            // Quick fix (Fase 0): chave canônica = 'Investimento' (o CF7 corta
            // o nome do tag no 1º espaço → posta 'Investimento'; a coluna do
            // Google Sheet e o canônico do CRM também são 'Investimento'). Antes
            // ia 'Investimento mensal em Marketing' e não batia em coluna nenhuma.
            'Investimento' => $sanitized['investment'] ?? '',
            'urgencia' => $sanitized['timing'] ?? '',
            'pain' => $sanitized['pain'] ?? '',
            'cf7_time' => current_time('c'),
            'cf7_url' => isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : home_url('/'),
        );
        return $this->dispatch_payload($payload, $is_partial, $client_sid);
    }

    /**
     * Tronco comum de envio: marca o parcial, anexa telemetria, grava na
     * rede de segurança, POSTa pro CRM e responde o JS. Vale tanto pro
     * roteiro default quanto pro custom — nenhum tenant ganha caminho de
     * envio próprio, então correção aqui vale pra todos.
     */
    private function dispatch_payload($payload, $is_partial, $client_sid) {
        if ($is_partial) {
            $payload['_adspirit_partial'] = '1';
        }

        // Telemetria — signature real: collect_from_post($form_kind, $form_id, $referrer_url).
        // Coleta UTMs, gclid, fbclid e visitor journey via static call.
        if (class_exists('AdSpirit_Telemetry') && method_exists('AdSpirit_Telemetry', 'collect_from_post')) {
            try {
                $referrer = isset($_SERVER['HTTP_REFERER']) ? esc_url_raw($_SERVER['HTTP_REFERER']) : home_url('/');
                $telemetry = AdSpirit_Telemetry::collect_from_post('qualifier', 'adspirit_form_qualifier', $referrer);
                if (is_array($telemetry)) {
                    $payload['_adspirit_telemetry'] = $telemetry;
                }
            } catch (\Throwable $e) { /* silenciado */ }
        }

        // Despacha pro CRM
        $core = AdSpirit_Settings::get_core();
        if (empty($core['endpoint_url']) || empty($core['brand_slug']) || empty($core['secret'])) {
            wp_send_json_error(array('error' => 'config_incompleta'), 412);
            return;
        }

        // Submission id estável: usa o do front quando vier (liga parcial↔final),
        // senão gera. Parcial leva sufixo "-p" → idempotency distinta do final,
        // então o final NÃO cai no cache do webhook e roda o scoring.
        $base_sid = $client_sid !== '' ? $client_sid : ('q-' . time() . '-' . wp_generate_password(8, false));
        $submission_id = $base_sid . ($is_partial ? '-p' : '');

        // F0 Connector 3.0: identidade do form no payload (aditivo — o CRM
        // ignora chaves desconhecidas). Qualifier sem form da Central é
        // sempre lead comercial; com form da Central, valem a finalidade e
        // as regras condicionais cadastradas lá.
        $payload['_adspirit_form_id'] = 'adspirit_form_qualifier';
        $payload['_adspirit_form_kind'] = 'comercial';
        $central_slug = isset($_POST['central_form']) ? sanitize_key((string) $_POST['central_form']) : '';
        if ($central_slug !== '' && class_exists('AdSpirit_Central_Forms')) {
            $cf = AdSpirit_Central_Forms::get($central_slug);
            if (is_array($cf)) {
                $payload['_adspirit_form_id'] = $central_slug;
                $kind = $cf['finalidade'];
                if (class_exists('AdSpirit_Form') && method_exists('AdSpirit_Form', 'resolve_finalidade')) {
                    $kind = AdSpirit_Form::resolve_finalidade(
                        array('routing_rules' => $cf['routing_rules']),
                        $payload,
                        $cf['finalidade']
                    );
                }
                $payload['_adspirit_form_kind'] = $kind;
            }
        }

        // Fase 1: grava local ANTES do POST pro CRM. Integridade não bloqueia o
        // envio — se record_pending falhar (tabela ausente), retorna false e o
        // fluxo segue normal (lead vai pro CRM + log legado de fallback).
        $lead_source = $is_partial ? 'qualifier_partial' : 'qualifier';
        if (class_exists('AdSpirit_Lead_Store')) {
            AdSpirit_Lead_Store::record_pending($submission_id, $payload, $lead_source, 'adspirit_form_qualifier');
        }

        // Dispatcher canônico (Connector 3.0): mesmo caminho do CF7/cron/manual.
        // BLOCKING de propósito — o redirect por perfil (A/B/C/D) depende da
        // resposta síncrona do CRM; não tornar assíncrono. mark_crm_attempt
        // registra attempts/last_error de verdade → o cron de retry cobre o
        // qualifier completo quando o POST falha (parcial fica fora do cron).
        if (class_exists('AdSpirit_Lead_Store')) {
            $result = AdSpirit_Lead_Store::dispatch_to_crm($submission_id, $payload, 10);
            AdSpirit_Lead_Store::mark_crm_attempt($submission_id, $result);
            // v2.30: lembra o visitante (cookie cifrado) pra personalização.
            if (class_exists('AdSpirit_Lead_Identity') && !empty($payload['your-email'])) {
                AdSpirit_Lead_Identity::remember((string) $payload['your-email']);
            }
            $code = (int) $result['code'];
            $body = $result['body'];
            if ($code === 0) {
                wp_send_json_error(array('error' => 'crm_unreachable', 'detail' => (string) $result['error']), 502);
                return;
            }
            if (empty($result['ok']) || !is_array($body)) {
                wp_send_json_error(array('error' => 'crm_error', 'status' => $code), 502);
                return;
            }
        } else {
            // Fallback raro (Lead_Store não carregou): POST inline legado —
            // melhor despachar às cegas do que perder o lead.
            $endpoint = rtrim($core['endpoint_url'], '/') . '/api/webhooks/contact-form-7';
            $response = wp_remote_post($endpoint, array(
                'timeout' => 10,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'x-brand-slug' => $core['brand_slug'],
                    'x-cf7-secret' => $core['secret'],
                    'x-cf7-submission-id' => $submission_id,
                    'User-Agent' => 'AdSpirit-Connector/' . ADSPIRIT_CONNECTOR_VERSION,
                ),
                'body' => wp_json_encode($payload),
            ));
            if (is_wp_error($response)) {
                wp_send_json_error(array('error' => 'crm_unreachable', 'detail' => $response->get_error_message()), 502);
                return;
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            if ($code < 200 || $code >= 300 || !is_array($body)) {
                wp_send_json_error(array('error' => 'crm_error', 'status' => $code), 502);
                return;
            }
        }

        // Webhook out (fan-out pra n8n/Elisa, Zapier, etc) — fire-and-forget.
        // NÃO faz fanout no parcial (lead incompleto não dispara automações
        // downstream; só o envio final completo faz).
        if (!$is_partial && class_exists('AdSpirit_Integrations') && method_exists('AdSpirit_Integrations', 'fanout')) {
            try {
                AdSpirit_Integrations::fanout($payload);
                if (class_exists('AdSpirit_Lead_Store')) {
                    AdSpirit_Lead_Store::mark($submission_id, 'fanout', 'dispatched');
                }
            } catch (\Throwable $e) { /* silenciado */ }
        }

        // Log local pra aba "Submissions" (pega profile + lead_id do response).
        // Source distingue qualifier completo de parcial.
        $log_source = $is_partial ? 'qualifier_partial' : 'qualifier';
        $log_payload = array_merge($payload, array('_form_id' => $log_source));
        do_action('adspirit_lead_dispatched', $log_payload, $body, $log_source);

        // Resposta pro JS — repassa redirect_url + profile do CRM.
        // No parcial o front ignora a resposta (segue nos próximos steps).
        wp_send_json_success(array(
            'partial' => $is_partial,
            'redirect_url' => isset($body['redirect_url']) ? (string) $body['redirect_url'] : home_url('/'),
            'profile' => isset($body['profile']) ? (string) $body['profile'] : null,
            'duplicate' => !empty($body['duplicate']),
        ));
    }
}
