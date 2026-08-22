<?php
/**
 * AdSpirit Connector — [adspirit_form] shortcode + Form Builder visual.
 *
 * Drop-in form com design AdSpirit, configurável por um builder no admin
 * (aba "Form builder"). Cada form é um array de campos (single-step) salvo
 * em wp_options. Render multi-step nativo continua suportado pelo engine.
 *
 * Uso: [adspirit_form id="lead-comercial"]
 *
 * Builder (Fase 2): aba dedicada — adicionar/remover/reordenar (↑↓) campos;
 * por campo: tipo, label, placeholder, obrigatoriedade e nome canônico (a
 * chave do payload). Guardrails no save: nome custom slugificado/validado e
 * nome duplicado bloqueado.
 *
 * Submit POSTa pro próprio plugin (admin-ajax.php) e entra na rede de
 * segurança (AdSpirit_Lead_Store) com retry — mesma garantia do CF7.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Form {
    const OPTION_KEY = 'adspirit_connector_native_forms';

    private static $instance = null;
    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('adspirit_form', array($this, 'render_shortcode'));
        add_action('wp_ajax_adspirit_form_submit', AdSpirit_Safe_Hook::action(array($this, 'handle_submit'), 'form_submit'));
        add_action('wp_ajax_nopriv_adspirit_form_submit', AdSpirit_Safe_Hook::action(array($this, 'handle_submit'), 'form_submit_anon'));

        // Builder: aba dedicada (grupo Captura). Consolida a edição de
        // OPTION_KEY num lugar só (o builder-JSON antigo da aba "Forms" saiu).
        add_filter('adspirit_connector_tabs', AdSpirit_Safe_Hook::filter(array($this, 'register_tab'), 'builder_tab_register'));
        add_filter('adspirit_connector_tab_groups', AdSpirit_Safe_Hook::filter(array($this, 'register_group'), 'builder_group_register'));
        add_action('adspirit_connector_render_tab_builder', AdSpirit_Safe_Hook::action(array($this, 'render_builder_tab'), 'builder_tab'));
        add_action('adspirit_connector_save_builder', AdSpirit_Safe_Hook::action(array($this, 'save_builder'), 'builder_save'));
    }

    public static function allowed_types() {
        return array('text', 'email', 'tel', 'url', 'select', 'textarea', 'checkbox');
    }

    public static function get_forms() {
        $v = get_option(self::OPTION_KEY, array());
        return is_array($v) ? $v : array();
    }

    public static function default_form_config() {
        return array(
            'title' => 'Formulário AdSpirit',
            'theme' => 'white',
            'success_message' => 'Obrigado! Recebemos seu contato e em breve falamos com você.',
            'steps' => array(
                array(
                    'title' => '',
                    'fields' => array(
                        array('name' => 'your-name', 'label' => 'Nome', 'type' => 'text', 'placeholder' => '', 'required' => true),
                        array('name' => 'your-email', 'label' => 'Email', 'type' => 'email', 'placeholder' => '', 'required' => true),
                        array('name' => 'Telefone', 'label' => 'Telefone', 'type' => 'tel', 'placeholder' => 'com DDD', 'required' => true),
                    ),
                ),
            ),
        );
    }

    /** Sugestões de nome canônico (datalist do builder). */
    public static function canonical_suggestions() {
        $base = array_keys(class_exists('AdSpirit_Settings') ? AdSpirit_Settings::canonical_fields() : array());
        // Extras que o qualifier também usa (mantém consistência de contrato).
        $extra = array('site-empresa', 'instagram', 'empresa', 'cargo', 'Numero-funcionarios',
            'nicho', 'ExperienciacomMarketing', 'revenue', 'Investimento', 'urgencia', 'pain');
        return array_values(array_unique(array_merge($base, $extra)));
    }

    /**
     * Guardrail (a): normaliza o nome do campo pra uma chave estável de
     * payload — sem espaço, acento ou char estranho. Canônicos conhecidos
     * (your-name, Telefone, site-empresa…) já são slug-safe e sobrevivem.
     * Evita o bug do tipo "Investimento mensal em Marketing".
     */
    public static function slugify_field_name($raw) {
        $s = trim((string) $raw);
        if ($s === '') return '';
        if (function_exists('remove_accents')) $s = remove_accents($s);
        $s = preg_replace('/[^A-Za-z0-9_-]+/', '-', $s);
        $s = preg_replace('/-+/', '-', $s);
        return trim($s, '-');
    }

    // ─────────────────────────────────────────────────────────
    // Front-end render
    // ─────────────────────────────────────────────────────────

    public function render_shortcode($atts) {
        // 3º arg ativa filter `shortcode_atts_adspirit_form` que A/B test usa
        $atts = shortcode_atts(array('id' => 'default', 'variant' => ''), $atts, 'adspirit_form');
        $forms = self::get_forms();
        $form = isset($forms[$atts['id']]) ? $forms[$atts['id']] : self::default_form_config();

        $steps = isset($form['steps']) ? $form['steps'] : array();
        if (empty($steps)) return '';

        $theme = (isset($form['theme']) && $form['theme'] === 'dark-glass') ? 'dark-glass' : 'white';
        $form_uid = 'adspirit-' . esc_attr($atts['id']) . '-' . wp_generate_password(6, false);

        ob_start();
        ?>
        <style>
            .adspirit-form { max-width: 540px; margin: 0 auto; font-family: -apple-system,BlinkMacSystemFont,'SF Pro Text','Segoe UI',sans-serif; background:#fff; border:1px solid #E5EAEE; border-radius:12px; padding:32px 28px; }
            .adspirit-form .progress { display:flex; gap:6px; margin-bottom:24px; }
            .adspirit-form .progress span { flex:1; height:4px; border-radius:2px; background:#E5EAEE; }
            .adspirit-form .progress span.done { background:#00B7B7; }
            .adspirit-form .progress span.current { background:#00B7B7; opacity:0.6; }
            /* Barra contínua: só em form longo (4+ etapas), onde a contagem
               de segmentos passa a pesar mais do que orienta. */
            .adspirit-form .progress.bar { display:block; height:4px; border-radius:2px; background:#E5EAEE; overflow:hidden; }
            .adspirit-form .progress.bar i { display:block; height:100%; border-radius:2px; background:#00B7B7; transition:width .45s cubic-bezier(.4,0,.2,1); }
            @media (prefers-reduced-motion: reduce) { .adspirit-form .progress.bar i { transition:none; } }
            .adspirit-form .step-title { font-size:11px; font-weight:700; letter-spacing:0.22em; text-transform:uppercase; color:#00B7B7; margin-bottom:4px; }
            .adspirit-form h3 { font-size:22px; font-weight:600; color:#0F1419; margin:0 0 18px; letter-spacing:-0.02em; }
            .adspirit-form .field { margin-bottom:14px; }
            .adspirit-form label { display:block; font-size:12px; font-weight:600; color:#3A4550; margin-bottom:6px; }
            .adspirit-form input, .adspirit-form select, .adspirit-form textarea {
                width:100%; padding:11px 14px; border:1px solid #E5EAEE; border-radius:8px;
                font-size:14px; font-family:inherit; background:#fff; color:#0F1419;
                transition:border-color 0.15s, box-shadow 0.15s;
            }
            .adspirit-form input:focus, .adspirit-form select:focus, .adspirit-form textarea:focus {
                outline:none; border-color:#00B7B7; box-shadow:0 0 0 3px rgba(0,183,183,0.18);
            }
            .adspirit-form .field-check { display:flex; align-items:center; gap:8px; }
            .adspirit-form .field-check input { width:auto; }
            .adspirit-form .nav { display:flex; justify-content:space-between; margin-top:24px; gap:10px; }
            .adspirit-form button { padding:11px 22px; border-radius:8px; font-size:13.5px; font-weight:600; cursor:pointer; font-family:inherit; }
            .adspirit-form button.primary { background:#00B7B7; color:#fff; border:0; }
            .adspirit-form button.primary:hover { background:#009999; }
            .adspirit-form button.secondary { background:transparent; color:#3A4550; border:1px solid #E5EAEE; }
            .adspirit-form .success { padding:24px; text-align:center; color:#15803D; font-size:14px; }
            .adspirit-form .error { padding:10px 12px; background:#FFF0F0; color:#C73838; border-radius:6px; font-size:12.5px; margin-bottom:12px; }

            /* ---- tema dark-glass (estética da agência) ---- */
            .adspirit-form.theme-dark-glass {
                background: rgba(18,20,24,0.92);
                border:1px solid rgba(255,255,255,0.08);
                box-shadow: 0 20px 60px rgba(0,0,0,0.45);
                backdrop-filter: blur(12px);
            }
            .adspirit-form.theme-dark-glass h3 { color:#F4F6F8; }
            .adspirit-form.theme-dark-glass label { color:#AAB3BD; }
            .adspirit-form.theme-dark-glass input,
            .adspirit-form.theme-dark-glass select,
            .adspirit-form.theme-dark-glass textarea {
                background: rgba(255,255,255,0.05); border-color: rgba(255,255,255,0.12); color:#F4F6F8;
            }
            .adspirit-form.theme-dark-glass .progress span { background: rgba(255,255,255,0.12); }
            .adspirit-form.theme-dark-glass .progress.bar { background: rgba(255,255,255,0.12); }
            .adspirit-form.theme-dark-glass button.secondary { color:#AAB3BD; border-color: rgba(255,255,255,0.16); }
        </style>
        <form class="adspirit-form theme-<?php echo esc_attr($theme); ?>" data-form-id="<?php echo esc_attr($atts['id']); ?>" id="<?php echo esc_attr($form_uid); ?>">
            <input type="hidden" name="_adspirit_nonce" value="<?php echo esc_attr(wp_create_nonce('adspirit_form_submit')); ?>">
            <?php
            // Arranque de progresso: a barra já nasce adiantada, pra reduzir
            // a sensação de caminho longo. Só a partir de 4 etapas — num form
            // de 2 o caminho não parece longo e os segmentos orientam melhor.
            // Forms curtos ficam byte a byte como estavam.
            $long_form = count($steps) >= 4;
            ?>
            <?php if ($long_form): ?>
                <?php // Largura do passo 0 já no HTML: show() só é chamado ao
                      // restaurar rascunho, então sem isto a barra ficaria
                      // parada no arranque até o primeiro clique.
                      $initial_pct = 8 + (1 / max(1, count($steps))) * 92; ?>
                <div class="progress bar"><i style="width:<?php echo esc_attr(round($initial_pct, 2)); ?>%;"></i></div>
            <?php else: ?>
                <div class="progress">
                    <?php foreach ($steps as $i => $_): ?>
                        <span class="<?php echo $i === 0 ? 'current' : ''; ?>"></span>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php foreach ($steps as $idx => $step): ?>
                <div class="step" data-step="<?php echo (int) $idx; ?>" style="<?php echo $idx === 0 ? '' : 'display:none;'; ?>">
                    <?php if (count($steps) > 1): ?><div class="step-title">Etapa <?php echo ($idx + 1); ?> de <?php echo count($steps); ?></div><?php endif; ?>
                    <?php if (!empty($step['title'])): ?><h3><?php echo esc_html($step['title']); ?></h3><?php endif; ?>
                    <?php foreach ($step['fields'] as $f): ?>
                        <?php $type = $f['type'] ?? 'text'; ?>
                        <div class="field <?php echo $type === 'checkbox' ? 'field-check' : ''; ?>">
                            <?php if ($type === 'checkbox'): ?>
                                <?php $this->render_field($f, $form_uid . '-' . $f['name']); ?>
                                <label for="<?php echo esc_attr($form_uid . '-' . $f['name']); ?>" style="margin:0;">
                                    <?php echo esc_html($f['label']); ?>
                                    <?php if (!empty($f['required'])): ?><span style="color:#C73838;">*</span><?php endif; ?>
                                </label>
                            <?php else: ?>
                                <label for="<?php echo esc_attr($form_uid . '-' . $f['name']); ?>">
                                    <?php echo esc_html($f['label']); ?>
                                    <?php if (!empty($f['required'])): ?><span style="color:#C73838;">*</span><?php endif; ?>
                                </label>
                                <?php $this->render_field($f, $form_uid . '-' . $f['name']); ?>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                    <div class="nav">
                        <?php if ($idx > 0): ?>
                            <button type="button" class="secondary" data-action="prev">← Voltar</button>
                        <?php else: ?><span></span><?php endif; ?>
                        <?php if ($idx < count($steps) - 1): ?>
                            <button type="button" class="primary" data-action="next">Continuar →</button>
                        <?php else: ?>
                            <button type="submit" class="primary">Enviar</button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </form>
        <script>
        (function() {
            var form = document.getElementById('<?php echo esc_js($form_uid); ?>');
            if (!form) return;
            var formId = form.dataset.formId;
            var steps = form.querySelectorAll('.step');
            var progress = form.querySelectorAll('.progress span');
            var progressFill = form.querySelector('.progress.bar i');
            var current = 0;
            var stepEnterTimes = [Date.now()];

            function show(i) {
                steps.forEach(function(s, idx) {
                    s.style.display = idx === i ? '' : 'none';
                    if (!progress[idx]) return; // form longo usa barra, não segmentos
                    progress[idx].classList.remove('current');
                    if (idx < i) progress[idx].classList.add('done');
                    else if (idx === i) progress[idx].classList.add('current');
                });
                if (progressFill) {
                    // Arranque de 8% + linear. Sem a curva pow(0.6) do wizard
                    // do CRM de propósito: ela foi calibrada pra ~50 passos e
                    // aqui o form tem 4 a 6, onde ela marcaria 48% no passo 1
                    // — contradizendo o "Etapa 1 de 4" logo abaixo. O arranque
                    // sozinho já dá a sensação de avanço sem mentir.
                    var real = steps.length > 0 ? (i + 1) / steps.length : 1;
                    progressFill.style.width = (8 + real * 92) + '%';
                }
                current = i;
                stepEnterTimes[i] = Date.now();
                try { localStorage.setItem('adspirit_form_' + formId + '_step', String(i)); } catch(e) {}
                window.scrollTo({ top: form.offsetTop - 40, behavior: 'smooth' });
            }

            function validate(stepEl) {
                // Preview: avança sem preencher (revisão sem gerar dados).
                if (document.body && document.body.classList.contains('adspirit-preview')) return true;
                var inputs = stepEl.querySelectorAll('input[required], select[required], textarea[required]');
                for (var i = 0; i < inputs.length; i++) {
                    if (inputs[i].type === 'checkbox') { if (!inputs[i].checked) { inputs[i].focus(); return false; } continue; }
                    if (!inputs[i].value || !inputs[i].value.trim()) { inputs[i].focus(); return false; }
                }
                return true;
            }

            form.addEventListener('click', function(e) {
                var btn = e.target.closest('button[data-action]');
                if (!btn) return;
                e.preventDefault();
                var act = btn.dataset.action;
                if (act === 'next') {
                    if (validate(steps[current])) { if (current < steps.length - 1) show(current + 1); }
                } else if (act === 'prev') {
                    if (current > 0) show(current - 1);
                }
            });

            form.addEventListener('input', function(e) {
                var el = e.target;
                if (!el.name) return;
                try {
                    var k = 'adspirit_form_' + formId;
                    var data = JSON.parse(localStorage.getItem(k) || '{}');
                    data[el.name] = el.value;
                    localStorage.setItem(k, JSON.stringify(data));
                } catch(err) {}
            });
            try {
                var saved = JSON.parse(localStorage.getItem('adspirit_form_' + formId) || '{}');
                Object.keys(saved).forEach(function(name) {
                    var el = form.querySelector('[name="' + name + '"]');
                    if (el && el.type !== 'checkbox') el.value = saved[name];
                });
                var savedStep = parseInt(localStorage.getItem('adspirit_form_' + formId + '_step') || '0', 10);
                if (savedStep > 0 && savedStep < steps.length) show(savedStep);
            } catch(e) {}

            form.addEventListener('submit', function(e) {
                e.preventDefault();
                if (!validate(steps[current])) return;
                var fd = new FormData(form);
                fd.append('action', 'adspirit_form_submit');
                fd.append('form_id', formId);
                var stepDurations = stepEnterTimes.map(function(t, i) {
                    var next = stepEnterTimes[i + 1] || Date.now();
                    return next - t;
                });
                fd.append('_adspirit_step_durations', JSON.stringify(stepDurations));

                var btn = form.querySelector('button[type="submit"]');
                btn.disabled = true; btn.textContent = 'Enviando…';

                fetch('<?php echo esc_js(admin_url('admin-ajax.php')); ?>', { method: 'POST', body: fd })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        if (d && d.ok) {
                            form.innerHTML = '<div class="success">' + (d.message || 'Obrigado!') + '</div>';
                            try { localStorage.removeItem('adspirit_form_' + formId); localStorage.removeItem('adspirit_form_' + formId + '_step'); } catch(e) {}
                        } else {
                            var err = document.createElement('div');
                            err.className = 'error';
                            err.textContent = (d && d.error) || 'Erro ao enviar.';
                            steps[current].insertBefore(err, steps[current].firstChild);
                            btn.disabled = false; btn.textContent = 'Enviar';
                        }
                    })
                    .catch(function(e) {
                        var err = document.createElement('div');
                        err.className = 'error';
                        err.textContent = 'Erro de rede: ' + e.message;
                        steps[current].insertBefore(err, steps[current].firstChild);
                        btn.disabled = false; btn.textContent = 'Enviar';
                    });
            });
        })();
        </script>
        <?php
        return ob_get_clean();
    }

    /**
     * Smart default de graça: o token `autocomplete` liga o preenchimento
     * que o browser e o iOS JÁ têm guardado. Sem ele, o campo não é
     * oferecido — a pessoa digita nome, e-mail e telefone do zero em todo
     * formulário. É o único "smart default" que não inventa dado nosso.
     *
     * Regra dura: só campo de IDENTIDADE (quem é a pessoa). Campo de
     * JULGAMENTO — faturamento, urgência, perfil, investimento — NUNCA
     * recebe sugestão: preencher isso sozinho enviesa a qualificação e o
     * lead é o produto. Nome não reconhecido não ganha atributo nenhum
     * (comportamento de antes, sem regressão).
     */
    public static function autocomplete_token($name, $type = 'text') {
        $k = strtolower(trim((string) $name));
        if (function_exists('remove_accents')) $k = remove_accents($k);
        $k = str_replace(array('_', ' '), '-', $k);

        $map = array(
            'your-name'    => 'name',
            'nome'         => 'name',
            'seu-nome'     => 'name',
            'name'         => 'name',
            // Nome e sobrenome separados pedem token próprio — senão o
            // browser tenta jogar o nome inteiro nos dois campos.
            'first-name'   => 'given-name',
            'primeiro-nome'=> 'given-name',
            'last-name'    => 'family-name',
            'sobrenome'    => 'family-name',
            'your-email'   => 'email',
            'email'        => 'email',
            'e-mail'       => 'email',
            'telefone'     => 'tel',
            'whatsapp'     => 'tel',
            'celular'      => 'tel',
            'phone'        => 'tel',
            'tel'          => 'tel',
            'empresa'      => 'organization',
            'company'      => 'organization',
            'nome-da-empresa' => 'organization',
            'cargo'        => 'organization-title',
            'funcao'       => 'organization-title',
            'site-empresa' => 'url',
            'site'         => 'url',
            'website'      => 'url',
            'cidade'       => 'address-level2',
            'estado'       => 'address-level1',
            'uf'           => 'address-level1',
            'cep'          => 'postal-code',
        );
        if (isset($map[$k])) return $map[$k];

        // Fallback pelo tipo do campo — um input type=email é e-mail
        // independente de como quem montou o form chamou ele.
        if (in_array($type, array('email', 'tel', 'url'), true)) return $type;
        return '';
    }

    private function render_field($f, $id) {
        $name = esc_attr($f['name']);
        $type = $f['type'] ?? 'text';
        // Pré-visualização (hub Formulários): sem `required` — preview é pra
        // CONSTRUIR o form, não pra preencher. O browser bloquearia o avanço.
        $req = (!empty($f['required']) && !defined('ADSPIRIT_QF_PREVIEW')) ? 'required' : '';
        $ph = isset($f['placeholder']) ? ' placeholder="' . esc_attr($f['placeholder']) . '"' : '';
        $ac_token = self::autocomplete_token($f['name'] ?? '', $type);
        $ac = $ac_token !== '' ? ' autocomplete="' . esc_attr($ac_token) . '"' : '';
        if ($type === 'select' && !empty($f['options'])) {
            echo '<select name="' . $name . '" id="' . esc_attr($id) . '" ' . $req . '>';
            echo '<option value="">— selecione —</option>';
            foreach ($f['options'] as $opt) {
                echo '<option value="' . esc_attr($opt) . '">' . esc_html($opt) . '</option>';
            }
            echo '</select>';
        } elseif ($type === 'textarea') {
            echo '<textarea name="' . $name . '" id="' . esc_attr($id) . '" rows="4" ' . $req . $ph . $ac . '></textarea>';
        } elseif ($type === 'checkbox') {
            echo '<input type="checkbox" name="' . $name . '" id="' . esc_attr($id) . '" value="1" ' . $req . '>';
        } else {
            $itype = in_array($type, array('email', 'tel', 'url'), true) ? $type : 'text';
            echo '<input type="' . esc_attr($itype) . '" name="' . $name . '" id="' . esc_attr($id) . '" ' . $req . $ph . $ac . '>';
        }
    }

    /**
     * AJAX submit. Entrega 2: grava na rede de segurança (AdSpirit_Lead_Store)
     * ANTES do POST e marca status por integração — mesma garantia do CF7.
     * Integridade não bloqueia o envio (record_pending falho → segue).
     */
    public function handle_submit() {
        $nonce = isset($_POST['_adspirit_nonce']) ? sanitize_text_field((string) $_POST['_adspirit_nonce']) : '';
        if (!$nonce || !wp_verify_nonce($nonce, 'adspirit_form_submit')) {
            wp_send_json(array('ok' => false, 'error' => 'nonce_invalido'), 403);
        }

        // v2.30: helper canônico (ganha X-Forwarded-For — sem ele o bucket
        // colapsava no IP do proxy e o rate limit virava global; mesma
        // decisão já tomada no rate limit do qualifier).
        $ip = class_exists('AdSpirit_Telemetry') ? AdSpirit_Telemetry::client_ip() : '';
        if ($ip === '') $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        $rl_key = 'adspirit_form_rl_' . md5($ip);
        $count = (int) get_transient($rl_key);
        if ($count >= 5) {
            wp_send_json(array('ok' => false, 'error' => 'rate_limit'), 429);
        }
        set_transient($rl_key, $count + 1, 60);

        $core = AdSpirit_Settings::get_core();
        if (empty($core['brand_slug']) || empty($core['secret'])) {
            wp_send_json(array('ok' => false, 'error' => 'plugin não conectado'));
        }

        $form_id = isset($_POST['form_id']) ? sanitize_text_field((string) $_POST['form_id']) : 'default';
        $forms = self::get_forms();
        $form = isset($forms[$form_id]) ? $forms[$form_id] : self::default_form_config();

        $payload = array();
        foreach ($_POST as $k => $v) {
            if ($k === 'action' || $k === 'form_id') continue;
            if (strpos($k, '_adspirit_') === 0) continue;
            $payload[$k] = is_string($v) ? sanitize_text_field($v) : (is_array($v) ? implode(', ', $v) : '');
        }
        $payload['cf7_time'] = current_time('c');
        $payload['cf7_url'] = wp_get_referer() ?: home_url('/');

        if (class_exists('AdSpirit_Telemetry')) {
            $tel = AdSpirit_Telemetry::collect_from_post('adspirit_native', $form_id, $payload['cf7_url']);
            if (!empty($_POST['_adspirit_step_durations'])) {
                $tel['multi_step_data'] = array(
                    'durations_ms' => json_decode((string) $_POST['_adspirit_step_durations'], true),
                    'completed_steps' => count(isset($form['steps']) ? $form['steps'] : array()),
                );
            }
            if (!empty($payload['your-email'])) {
                $tel['email_type'] = AdSpirit_Telemetry::classify_email((string) $payload['your-email']);
            }
            $payload['_adspirit_telemetry'] = $tel;
        }

        // F0 Connector 3.0: identidade + finalidade do form viajam no payload.
        // Finalidade vem da config do form ('comercial' | 'nutricao'); o CRM
        // bifurca no processor — nutrição vira contato+tag, sem lead no funil.
        // v2.30 (lição Bit Integrations): regras condicionais por payload/UTM
        // podem sobrepor a finalidade por SUBMISSÃO — primeiro match vence,
        // sem match fica a padrão. Form sem regras = comportamento idêntico.
        $finalidade = isset($form['finalidade']) && $form['finalidade'] === 'nutricao' ? 'nutricao' : 'comercial';
        $finalidade = self::resolve_finalidade($form, $payload, $finalidade);
        $payload['_adspirit_form_id'] = $form_id;
        $payload['_adspirit_form_kind'] = $finalidade;

        $payload = apply_filters('adspirit_form_submit_payload', $payload, $form_id);

        $submission_id = $form_id . '-' . time() . '-' . wp_generate_password(6, false);

        // Entrega 2: grava local ANTES do POST. Não bloqueia o envio.
        if (class_exists('AdSpirit_Lead_Store')) {
            AdSpirit_Lead_Store::record_pending($submission_id, $payload, 'native', $form_id);
        }

        // Dispatcher canônico (Connector 3.0) — attempts/last_error verdadeiros
        // e retry automático pelo cron quando o POST falha.
        if (class_exists('AdSpirit_Lead_Store')) {
            $result = AdSpirit_Lead_Store::dispatch_to_crm($submission_id, $payload, 8);
            AdSpirit_Lead_Store::mark_crm_attempt($submission_id, $result);
            // v2.30: lembra o visitante (cookie cifrado) pra personalização.
            if (class_exists('AdSpirit_Lead_Identity') && !empty($payload['your-email'])) {
                AdSpirit_Lead_Identity::remember((string) $payload['your-email']);
            }
            if ((int) $result['code'] === 0) {
                wp_send_json(array('ok' => false, 'error' => (string) $result['error']));
            }
            if (empty($result['ok'])) {
                wp_send_json(array('ok' => false, 'error' => 'CRM HTTP ' . (int) $result['code']));
            }
            $body = $result['body'];
        } else {
            // Fallback raro (Lead_Store não carregou): POST inline legado.
            $endpoint = rtrim($core['endpoint_url'], '/') . '/api/webhooks/contact-form-7';
            $response = wp_remote_post($endpoint, array(
                'timeout' => 8,
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
                wp_send_json(array('ok' => false, 'error' => $response->get_error_message()));
            }
            $code = (int) wp_remote_retrieve_response_code($response);
            $body = json_decode((string) wp_remote_retrieve_body($response), true);
            if ($code < 200 || $code >= 300) {
                wp_send_json(array('ok' => false, 'error' => 'CRM HTTP ' . $code));
            }
        }
        if (class_exists('AdSpirit_Integrations') && method_exists('AdSpirit_Integrations', 'fanout')) {
            AdSpirit_Integrations::fanout($payload);
            if (class_exists('AdSpirit_Lead_Store')) {
                AdSpirit_Lead_Store::mark($submission_id, 'fanout', 'dispatched');
            }
        }
        do_action('adspirit_lead_dispatched', array_merge($payload, array('_form_id' => $form_id)), is_array($body) ? $body : null, 'native');

        wp_send_json(array(
            'ok' => true,
            'message' => $form['success_message'] ?? 'Obrigado!',
        ));
    }

    // ─────────────────────────────────────────────────────────
    // Builder (admin)
    // ─────────────────────────────────────────────────────────

    public function register_tab($tabs) {
        if (!is_array($tabs)) return $tabs;
        $tabs['builder'] = 'Form builder';
        return $tabs;
    }

    public function register_group($groups) {
        if (!is_array($groups)) return $groups;
        if (isset($groups['captura']['tabs']) && is_array($groups['captura']['tabs'])
            && !in_array('builder', $groups['captura']['tabs'], true)) {
            // Coloca o builder logo após o qualifier.
            $groups['captura']['tabs'][] = 'builder';
        }
        return $groups;
    }

    /** Lê todos os campos de um form (achatando steps) pra edição single-step. */
    private function flatten_fields($form) {
        $out = array();
        foreach ((array) ($form['steps'] ?? array()) as $step) {
            foreach ((array) ($step['fields'] ?? array()) as $f) {
                $out[] = $f;
            }
        }
        return $out;
    }

    public function render_builder_tab() {
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) return;

        $forms = self::get_forms();
        $ids = array_keys($forms);
        $editing = isset($_GET['edit']) ? sanitize_key((string) $_GET['edit']) : '';
        $is_new = isset($_GET['new']);

        if ($is_new) {
            $cur_id = '';
            $cur = self::default_form_config();
        } elseif ($editing && isset($forms[$editing])) {
            $cur_id = $editing;
            $cur = $forms[$editing];
        } elseif (!empty($ids)) {
            $cur_id = $ids[0];
            $cur = $forms[$cur_id];
        } else {
            $cur_id = '';
            $cur = self::default_form_config();
        }

        $fields = $this->flatten_fields($cur);
        if (empty($fields)) $fields = $this->flatten_fields(self::default_form_config());
        $theme = (isset($cur['theme']) && $cur['theme'] === 'dark-glass') ? 'dark-glass' : 'white';
        $suggestions = self::canonical_suggestions();
        $types = self::allowed_types();

        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Form builder</span>Formulários do plugin</h2>
        <p class="as-section-help">Monte forms multi-campo sem código. Use <code>[adspirit_form id="seu-id"]</code> na página. Cada envio é gravado na rede de segurança (aba <strong>Submissões</strong>) <strong>antes</strong> de ir pro CRM — pode publicar.</p>

        <?php AdSpirit_Menu::card_open('Seus forms', count($ids) . ' form(s)', '<a href="' . esc_url(admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=builder&new=1')) . '" class="button button-primary">+ Novo form</a>'); ?>
        <?php if (empty($ids)): ?>
            <p style="margin:0; color:var(--as-ink-faint); font-size:13px;">Nenhum form ainda. Clique <strong>Novo form</strong> pra criar o primeiro.</p>
        <?php else: ?>
            <div style="display:flex; flex-wrap:wrap; gap:6px;">
            <?php foreach ($forms as $fid => $f): ?>
                <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=builder&edit=' . $fid)); ?>"
                   class="button <?php echo ($fid === $cur_id && !$is_new) ? 'button-primary' : ''; ?>">
                    <?php echo esc_html($f['title'] ?? $fid); ?>
                    <code style="margin-left:6px; opacity:0.7;">#<?php echo esc_html($fid); ?></code>
                </a>
            <?php endforeach; ?>
            </div>
        <?php endif; ?>
        <?php AdSpirit_Menu::card_close(); ?>

        <?php AdSpirit_Menu::card_open(
            $is_new || $cur_id === '' ? 'Novo formulário' : 'Editando — ' . esc_html($cur['title'] ?? $cur_id),
            'Reordene com ↑↓. O nome canônico é a chave que vai no payload.'
        ); ?>
        <?php AdSpirit_Menu::form_open('builder'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="ab_id">ID do form</label></th>
                    <td>
                        <input type="text" id="ab_id" name="form_id" class="regular-text" required pattern="[a-z0-9_-]+"
                               value="<?php echo esc_attr($cur_id); ?>" <?php echo $cur_id !== '' ? 'readonly' : ''; ?>
                               placeholder="lead-comercial">
                        <p class="description">Minúsculas, números, <code>-</code> e <code>_</code>. Usado no shortcode. Não dá pra trocar depois de criar.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="ab_title">Título</label></th>
                    <td><input type="text" id="ab_title" name="form_title" class="regular-text" value="<?php echo esc_attr($cur['title'] ?? ''); ?>"></td>
                </tr>
                <tr>
                    <th><label for="ab_theme">Tema</label></th>
                    <td>
                        <select id="ab_theme" name="form_theme">
                            <option value="white" <?php selected($theme, 'white'); ?>>Branco (claro)</option>
                            <option value="dark-glass" <?php selected($theme, 'dark-glass'); ?>>Dark glass (estética agência)</option>
                        </select>
                    </td>
                </tr>
                <tr>
                    <th><label for="ab_success">Mensagem de sucesso</label></th>
                    <td><input type="text" id="ab_success" name="form_success" class="regular-text" style="max-width:480px;" value="<?php echo esc_attr($cur['success_message'] ?? 'Obrigado!'); ?>"></td>
                </tr>
                <tr>
                    <th><label for="ab_status">Situação</label></th>
                    <td>
                        <select id="ab_status" name="form_status">
                            <option value="publicado" <?php selected($cur['status'] ?? 'publicado', 'publicado'); ?>>Publicado — capturando leads</option>
                            <option value="rascunho" <?php selected($cur['status'] ?? 'publicado', 'rascunho'); ?>>Rascunho — ainda montando</option>
                        </select>
                        <p class="description">Rascunho aparece marcado no hub e não deveria estar em página publicada.</p>
                    </td>
                </tr>
                <tr>
                    <th><label for="ab_finalidade">Finalidade</label></th>
                    <td>
                        <select id="ab_finalidade" name="form_finalidade">
                            <option value="comercial" <?php selected($cur['finalidade'] ?? 'comercial', 'comercial'); ?>>Comercial — vira lead no funil de vendas</option>
                            <option value="nutricao" <?php selected($cur['finalidade'] ?? 'comercial', 'nutricao'); ?>>Nutrição — vira contato com tag, sem lead</option>
                        </select>
                        <p class="description">Newsletter, materiais ricos e "trabalhe conosco" são nutrição: entram na base de contatos sem sujar o funil comercial.</p>
                        <details class="as-help" style="margin-top:10px;">
                            <summary>Regras condicionais (avançado)</summary>
                            <p class="description" style="margin:8px 0 6px;">Mudam a finalidade por submissão, olhando as respostas e a origem (<code>utm_source</code>, <code>utm_medium</code>, <code>utm_campaign</code>...). Primeira regra que bater vence; sem match, vale a finalidade acima. Operadores: <code>is</code>, <code>not</code>, <code>contains</code>.</p>
                            <textarea name="form_routing_rules" rows="4" class="large-text code" placeholder='[{"field": "utm_medium", "op": "is", "value": "email", "then": "nutricao"}]'><?php
                                $rr = isset($cur['routing_rules']) && is_array($cur['routing_rules']) && !empty($cur['routing_rules'])
                                    ? wp_json_encode($cur['routing_rules'], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) : '';
                                echo esc_textarea($rr);
                            ?></textarea>
                        </details>
                    </td>
                </tr>
            </table>

            <h4 style="margin:18px 0 8px;">Campos</h4>
            <datalist id="ab-canon-list">
                <?php foreach ($suggestions as $s): ?><option value="<?php echo esc_attr($s); ?>"></option><?php endforeach; ?>
            </datalist>

            <div id="ab-fields">
                <?php foreach ($fields as $i => $f): ?>
                    <?php $this->render_field_row($i, $f, $types); ?>
                <?php endforeach; ?>
            </div>

            <p><button type="button" class="button" id="ab-add-field">+ Adicionar campo</button></p>

            <details style="margin-top:18px;">
                <summary>Avançado — JSON cru</summary>
                <p class="as-field-help">Visualização da config salva. Edite pela UI acima; este JSON é só leitura/diagnóstico.</p>
                <pre style="max-height:280px; overflow:auto;"><?php echo esc_html(wp_json_encode($cur, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)); ?></pre>
            </details>

            <p class="submit">
                <button type="submit" class="button button-primary">Salvar form</button>
                <?php if ($cur_id !== '' && !$is_new): ?>
                    <button type="submit" class="button button-danger" name="delete_form" value="1"
                            onclick="return confirm('Excluir o form #<?php echo esc_js($cur_id); ?>? (não afeta páginas onde ele já foi colado)');">Excluir form</button>
                <?php endif; ?>
            </p>
        </form>
        <?php AdSpirit_Menu::card_close(); ?>

        <?php $this->render_builder_js($types); ?>
        <?php
    }

    /** Uma linha de campo no builder (server-rendered; índice no name). */
    private function render_field_row($i, $f, $types) {
        $i = (int) $i;
        $type = $f['type'] ?? 'text';
        $opts = isset($f['options']) && is_array($f['options']) ? implode(', ', $f['options']) : '';
        ?>
        <div class="ab-row" style="border:1px solid var(--as-line); border-radius:8px; padding:12px; margin-bottom:10px; background:var(--as-bg);">
            <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">
                <label style="flex:0 0 130px;">Tipo
                    <select name="fields[<?php echo $i; ?>][type]" class="ab-type" style="width:100%;">
                        <?php foreach ($types as $t): ?><option value="<?php echo esc_attr($t); ?>" <?php selected($type, $t); ?>><?php echo esc_html($t); ?></option><?php endforeach; ?>
                    </select>
                </label>
                <label style="flex:1 1 180px;">Label (rótulo visível)
                    <input type="text" name="fields[<?php echo $i; ?>][label]" value="<?php echo esc_attr($f['label'] ?? ''); ?>" style="width:100%;">
                </label>
                <label style="flex:1 1 160px;">Nome canônico (payload)
                    <input type="text" name="fields[<?php echo $i; ?>][name]" value="<?php echo esc_attr($f['name'] ?? ''); ?>" list="ab-canon-list" style="width:100%;" placeholder="ex: your-email">
                </label>
                <label style="flex:1 1 140px;">Placeholder
                    <input type="text" name="fields[<?php echo $i; ?>][placeholder]" value="<?php echo esc_attr($f['placeholder'] ?? ''); ?>" style="width:100%;">
                </label>
                <label style="flex:0 0 auto; white-space:nowrap;">
                    <input type="checkbox" name="fields[<?php echo $i; ?>][required]" value="1" <?php checked(!empty($f['required'])); ?>> Obrigatório
                </label>
            </div>
            <div class="ab-opts" style="margin-top:8px; <?php echo $type === 'select' ? '' : 'display:none;'; ?>">
                <label style="display:block;">Opções do select (separadas por vírgula)
                    <input type="text" name="fields[<?php echo $i; ?>][options]" value="<?php echo esc_attr($opts); ?>" style="width:100%;" placeholder="Opção A, Opção B, Opção C">
                </label>
            </div>
            <div style="margin-top:8px; display:flex; gap:6px;">
                <button type="button" class="button ab-up" title="Mover pra cima">↑</button>
                <button type="button" class="button ab-down" title="Mover pra baixo">↓</button>
                <button type="button" class="button button-danger ab-remove" title="Remover campo">Remover</button>
            </div>
        </div>
        <?php
    }

    /** JS do builder: add/remove/↑↓ + reindex no submit + toggle de opções. */
    private function render_builder_js($types) {
        $types_json = wp_json_encode(array_values($types));
        ?>
        <script>
        (function(){
            var wrap = document.getElementById('ab-fields');
            if (!wrap) return;
            var TYPES = <?php echo $types_json; ?>;

            function rowTemplate(idx) {
                var typeOpts = TYPES.map(function(t){ return '<option value="'+t+'">'+t+'</option>'; }).join('');
                var d = document.createElement('div');
                d.className = 'ab-row';
                d.style.cssText = 'border:1px solid var(--as-line); border-radius:8px; padding:12px; margin-bottom:10px; background:var(--as-bg);';
                d.innerHTML =
                  '<div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end;">'
                  + '<label style="flex:0 0 130px;">Tipo<select name="fields['+idx+'][type]" class="ab-type" style="width:100%;">'+typeOpts+'</select></label>'
                  + '<label style="flex:1 1 180px;">Label (rótulo visível)<input type="text" name="fields['+idx+'][label]" style="width:100%;"></label>'
                  + '<label style="flex:1 1 160px;">Nome canônico (payload)<input type="text" name="fields['+idx+'][name]" list="ab-canon-list" style="width:100%;" placeholder="ex: your-email"></label>'
                  + '<label style="flex:1 1 140px;">Placeholder<input type="text" name="fields['+idx+'][placeholder]" style="width:100%;"></label>'
                  + '<label style="flex:0 0 auto; white-space:nowrap;"><input type="checkbox" name="fields['+idx+'][required]" value="1"> Obrigatório</label>'
                  + '</div>'
                  + '<div class="ab-opts" style="margin-top:8px; display:none;"><label style="display:block;">Opções do select (separadas por vírgula)<input type="text" name="fields['+idx+'][options]" style="width:100%;" placeholder="Opção A, Opção B"></label></div>'
                  + '<div style="margin-top:8px; display:flex; gap:6px;">'
                  + '<button type="button" class="button ab-up" title="Mover pra cima">↑</button>'
                  + '<button type="button" class="button ab-down" title="Mover pra baixo">↓</button>'
                  + '<button type="button" class="button button-danger ab-remove" title="Remover campo">Remover</button>'
                  + '</div>';
                return d;
            }

            function reindex() {
                var rows = wrap.querySelectorAll('.ab-row');
                Array.prototype.forEach.call(rows, function(row, idx){
                    row.querySelectorAll('[name^="fields["]').forEach(function(el){
                        el.name = el.name.replace(/fields\[\d+\]/, 'fields['+idx+']');
                    });
                });
            }

            function toggleOpts(row) {
                var sel = row.querySelector('.ab-type');
                var opts = row.querySelector('.ab-opts');
                if (sel && opts) opts.style.display = (sel.value === 'select') ? '' : 'none';
            }

            wrap.addEventListener('change', function(e){
                if (e.target.classList.contains('ab-type')) toggleOpts(e.target.closest('.ab-row'));
            });
            wrap.addEventListener('click', function(e){
                var row = e.target.closest('.ab-row');
                if (!row) return;
                if (e.target.classList.contains('ab-remove')) {
                    if (wrap.querySelectorAll('.ab-row').length <= 1) { alert('O form precisa de pelo menos 1 campo.'); return; }
                    row.remove(); reindex();
                } else if (e.target.classList.contains('ab-up')) {
                    var prev = row.previousElementSibling;
                    if (prev) { wrap.insertBefore(row, prev); reindex(); }
                } else if (e.target.classList.contains('ab-down')) {
                    var next = row.nextElementSibling;
                    if (next) { wrap.insertBefore(next, row); reindex(); }
                }
            });

            var addBtn = document.getElementById('ab-add-field');
            if (addBtn) addBtn.addEventListener('click', function(){
                var idx = wrap.querySelectorAll('.ab-row').length;
                wrap.appendChild(rowTemplate(idx));
            });

            // Reindex final antes de enviar (garante ordem = DOM).
            var formEl = wrap.closest('form');
            if (formEl) formEl.addEventListener('submit', reindex);
        })();
        </script>
        <?php
    }

    /**
     * Save do builder. Guardrails: (a) slugify do nome de campo,
     * (b) bloqueia nome duplicado no mesmo form. Sanitiza tudo.
     */
    public function save_builder($post) {
        $form_id = isset($post['form_id']) ? sanitize_key((string) $post['form_id']) : '';
        if ($form_id === '') {
            add_settings_error(self::OPTION_KEY, 'no_id', 'ID do form inválido (use minúsculas, números, - e _).');
            return;
        }

        // Excluir
        if (!empty($post['delete_form'])) {
            $forms = self::get_forms();
            unset($forms[$form_id]);
            update_option(self::OPTION_KEY, $forms, false);
            add_settings_error(self::OPTION_KEY, 'deleted', sprintf('Form #%s excluído.', $form_id), 'updated');
            return;
        }

        $rows = isset($post['fields']) && is_array($post['fields']) ? $post['fields'] : array();
        $allowed = self::allowed_types();
        $seen = array();
        $clean = array();

        foreach ($rows as $row) {
            if (!is_array($row)) continue;
            // (a) slugify: usa o nome canônico; se vazio, deriva do label.
            $raw_name = isset($row['name']) && $row['name'] !== '' ? $row['name'] : ($row['label'] ?? '');
            $name = self::slugify_field_name($raw_name);
            if ($name === '') continue; // linha sem nome → ignora

            // (b) bloqueia duplicado (case-insensitive)
            $key = strtolower($name);
            if (isset($seen[$key])) {
                add_settings_error(self::OPTION_KEY, 'dup', sprintf(
                    'Campo duplicado: <code>%s</code>. Cada nome canônico só pode aparecer uma vez no form. Nada foi salvo.',
                    esc_html($name)
                ));
                return; // BLOQUEIA o save inteiro
            }
            $seen[$key] = true;

            $type = in_array($row['type'] ?? 'text', $allowed, true) ? $row['type'] : 'text';
            $options = array();
            if ($type === 'select' && !empty($row['options'])) {
                foreach (explode(',', (string) $row['options']) as $opt) {
                    $opt = sanitize_text_field(trim($opt));
                    if ($opt !== '') $options[] = $opt;
                }
            }
            $clean[] = array(
                'name'        => $name,
                'label'       => sanitize_text_field((string) ($row['label'] ?? '')),
                'type'        => $type,
                'placeholder' => sanitize_text_field((string) ($row['placeholder'] ?? '')),
                'required'    => !empty($row['required']),
                'options'     => $options,
            );
        }

        if (empty($clean)) {
            add_settings_error(self::OPTION_KEY, 'empty', 'O form precisa de pelo menos 1 campo válido. Nada foi salvo.');
            return;
        }

        $theme = (isset($post['form_theme']) && $post['form_theme'] === 'dark-glass') ? 'dark-glass' : 'white';
        $config = array(
            'title'           => sanitize_text_field((string) ($post['form_title'] ?? 'Formulário')),
            'theme'           => $theme,
            'success_message' => sanitize_text_field((string) ($post['form_success'] ?? 'Obrigado!')),
            // F0 Connector 3.0: finalidade por form (comercial | nutricao).
            'finalidade'      => (isset($post['form_finalidade']) && $post['form_finalidade'] === 'nutricao') ? 'nutricao' : 'comercial',
            // Rascunho x publicado (Pedro 08-20): esboço não pode parecer
            // ativo no hub. Ausência = publicado (não muda o que já existe).
            'status'          => (isset($post['form_status']) && $post['form_status'] === 'rascunho') ? 'rascunho' : 'publicado',
            'steps'           => array(array('title' => '', 'fields' => $clean)),
        );
        // v2.30: regras condicionais de finalidade (opcional). JSON inválido
        // avisa e salva SEM regras — nunca perde o resto da config do form.
        $rules = self::sanitize_routing_rules((string) ($post['form_routing_rules'] ?? ''));
        if ($rules === null) {
            add_settings_error(self::OPTION_KEY, 'routing_invalid',
                'Regras condicionais ignoradas: JSON inválido. O form foi salvo sem elas.', 'warning');
            $rules = array();
        }
        if (!empty($rules)) $config['routing_rules'] = $rules;

        // Sync 2 vias (reestruturação 08-18): salvar aqui reflete na
        // Central do AdSpirit. Fail-soft — sem conexão o form segue 100%
        // local e o hub mostra "só neste site".
        if (class_exists('AdSpirit_Central_Forms')) {
            $pushed = AdSpirit_Central_Forms::push(
                AdSpirit_Central_Forms::builder_to_central($form_id, $config)
            );
            if ($pushed) $config['synced_at'] = time();
        }

        $forms = self::get_forms();
        $forms[$form_id] = $config;
        update_option(self::OPTION_KEY, $forms, false);
        add_settings_error(self::OPTION_KEY, 'saved', sprintf(
            'Form salvo (%d campos). Shortcode: <code>[adspirit_form id="%s"]</code>',
            count($clean), esc_html($form_id)
        ), 'updated');
    }

    // ─── Roteamento condicional no envio (v2.30) ───

    /**
     * Regras {field, op is|not|contains, value, then comercial|nutricao} —
     * mesmo vocabulário do showIf do qualifier. Avaliadas contra o payload
     * + chaves virtuais utm_* (last-touch da telemetria, fallback first).
     * Primeiro match vence; sem regras/match devolve o default. Nunca lança.
     */
    public static function resolve_finalidade($form, array $payload, $default) {
        $rules = isset($form['routing_rules']) && is_array($form['routing_rules'])
            ? $form['routing_rules'] : array();
        if (empty($rules)) return $default;

        $ctx = array();
        foreach ($payload as $k => $v) {
            if (is_scalar($v)) $ctx[strtolower((string) $k)] = strtolower(trim((string) $v));
        }
        // UTM virtuais: last-touch vence, first-touch preenche o que faltar.
        foreach (array('_adspirit_t_ft', '_adspirit_t_lt') as $tk) {
            if (empty($payload[$tk]) || !is_string($payload[$tk])) continue;
            $touch = json_decode($payload[$tk], true);
            if (!is_array($touch)) continue;
            foreach ($touch as $tkey => $tval) {
                if (is_scalar($tval) && $tval !== '') {
                    $ctx[strtolower((string) $tkey)] = strtolower(trim((string) $tval));
                }
            }
        }

        foreach ($rules as $r) {
            if (!is_array($r) || empty($r['field'])) continue;
            $got = isset($ctx[strtolower((string) $r['field'])]) ? $ctx[strtolower((string) $r['field'])] : '';
            $want = strtolower(trim((string) ($r['value'] ?? '')));
            $op = isset($r['op']) ? (string) $r['op'] : 'is';
            $hit = ($op === 'not') ? ($got !== $want)
                : (($op === 'contains') ? ($want !== '' && strpos($got, $want) !== false)
                : ($got === $want));
            if ($hit) {
                return (isset($r['then']) && $r['then'] === 'nutricao') ? 'nutricao' : 'comercial';
            }
        }
        return $default;
    }

    /** JSON do admin → regras limpas. Devolve array (vazio se inválido/ausente). */
    public static function sanitize_routing_rules($raw_json) {
        $raw_json = trim((string) $raw_json);
        if ($raw_json === '') return array();
        $decoded = json_decode($raw_json, true);
        if (!is_array($decoded)) return null; // JSON inválido → erro no save
        $out = array();
        foreach ($decoded as $r) {
            if (!is_array($r)) continue;
            $field = sanitize_text_field((string) ($r['field'] ?? ''));
            if ($field === '') continue;
            $out[] = array(
                'field' => $field,
                'op'    => in_array(($r['op'] ?? 'is'), array('is', 'not', 'contains'), true) ? $r['op'] : 'is',
                'value' => sanitize_text_field((string) ($r['value'] ?? '')),
                'then'  => (($r['then'] ?? '') === 'nutricao') ? 'nutricao' : 'comercial',
            );
            if (count($out) >= 20) break; // teto sano
        }
        return $out;
    }
}
