<?php
/**
 * AdSpirit Connector — [adspirit_form] shortcode + multi-step form.
 *
 * Drop-in form com design AdSpirit. Multi-step nativo, mobile-first,
 * persistence via localStorage. Cada step rastreado em telemetria
 * (time_per_step, abandons).
 *
 * Uso básico:
 *   [adspirit_form id="lead-comercial"]
 *
 * Steps configurados no painel "Forms" → "[adspirit_form] builder".
 * Cada form é um array de steps, cada step tem N campos.
 *
 * Submit POSTa pro próprio plugin (admin-ajax.php) que dispara o mesmo
 * fluxo do CF7 (anti-spam → telemetria → CRM webhook).
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
        add_action('adspirit_connector_render_tab_forms', AdSpirit_Safe_Hook::action(array($this, 'render_builder'), 'form_builder'), 9);
        add_action('adspirit_connector_save_native_form', AdSpirit_Safe_Hook::action(array($this, 'save_form'), 'form_save'));
    }

    public static function get_forms() {
        $v = get_option(self::OPTION_KEY, array());
        return is_array($v) ? $v : array();
    }

    public static function default_form_config() {
        return array(
            'title' => 'Formulário AdSpirit',
            'steps' => array(
                array(
                    'title' => 'Sobre você',
                    'fields' => array(
                        array('name' => 'your-name', 'label' => 'Nome', 'type' => 'text', 'required' => true),
                        array('name' => 'your-email', 'label' => 'Email', 'type' => 'email', 'required' => true),
                        array('name' => 'Telefone', 'label' => 'Telefone', 'type' => 'tel', 'required' => true),
                    ),
                ),
                array(
                    'title' => 'Sobre o negócio',
                    'fields' => array(
                        array('name' => 'empresa', 'label' => 'Empresa', 'type' => 'text', 'required' => false),
                        array('name' => 'nicho', 'label' => 'Nicho', 'type' => 'text', 'required' => false),
                        array('name' => 'Investimento', 'label' => 'Investimento mensal', 'type' => 'select', 'options' => array('Nunca investi em Marketing', '1k-3k', '3k-5k', '5k-10k', '10k+'), 'required' => false),
                    ),
                ),
            ),
            'success_message' => 'Obrigado! Recebemos seu contato e em breve falamos com você.',
        );
    }

    public function render_shortcode($atts) {
        $atts = shortcode_atts(array('id' => 'default'), $atts);
        $forms = self::get_forms();
        $form = isset($forms[$atts['id']]) ? $forms[$atts['id']] : self::default_form_config();

        $steps = isset($form['steps']) ? $form['steps'] : array();
        if (empty($steps)) return '';

        $form_uid = 'adspirit-' . esc_attr($atts['id']) . '-' . wp_generate_password(6, false);

        ob_start();
        ?>
        <style>
            .adspirit-form { max-width: 540px; margin: 0 auto; font-family: -apple-system,BlinkMacSystemFont,'SF Pro Text','Segoe UI',sans-serif; background:#fff; border:1px solid #E5EAEE; border-radius:12px; padding:32px 28px; }
            .adspirit-form .progress { display:flex; gap:6px; margin-bottom:24px; }
            .adspirit-form .progress span { flex:1; height:4px; border-radius:2px; background:#E5EAEE; }
            .adspirit-form .progress span.done { background:#00B7B7; }
            .adspirit-form .progress span.current { background:#00B7B7; opacity:0.6; }
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
            .adspirit-form .nav { display:flex; justify-content:space-between; margin-top:24px; gap:10px; }
            .adspirit-form button { padding:11px 22px; border-radius:8px; font-size:13.5px; font-weight:600; cursor:pointer; font-family:inherit; }
            .adspirit-form button.primary { background:#00B7B7; color:#fff; border:0; }
            .adspirit-form button.primary:hover { background:#009999; }
            .adspirit-form button.secondary { background:transparent; color:#3A4550; border:1px solid #E5EAEE; }
            .adspirit-form .success { padding:24px; text-align:center; color:#15803D; font-size:14px; }
            .adspirit-form .error { padding:10px 12px; background:#FFF0F0; color:#C73838; border-radius:6px; font-size:12.5px; margin-bottom:12px; }
        </style>
        <form class="adspirit-form" data-form-id="<?php echo esc_attr($atts['id']); ?>" id="<?php echo esc_attr($form_uid); ?>">
            <div class="progress">
                <?php foreach ($steps as $i => $_): ?>
                    <span class="<?php echo $i === 0 ? 'current' : ''; ?>"></span>
                <?php endforeach; ?>
            </div>

            <?php foreach ($steps as $idx => $step): ?>
                <div class="step" data-step="<?php echo (int) $idx; ?>" style="<?php echo $idx === 0 ? '' : 'display:none;'; ?>">
                    <div class="step-title">Etapa <?php echo ($idx + 1); ?> de <?php echo count($steps); ?></div>
                    <h3><?php echo esc_html($step['title'] ?? ''); ?></h3>
                    <?php foreach ($step['fields'] as $f): ?>
                        <div class="field">
                            <label for="<?php echo esc_attr($form_uid . '-' . $f['name']); ?>">
                                <?php echo esc_html($f['label']); ?>
                                <?php if (!empty($f['required'])): ?><span style="color:#C73838;">*</span><?php endif; ?>
                            </label>
                            <?php $this->render_field($f, $form_uid . '-' . $f['name']); ?>
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
            var current = 0;
            var stepEnterTimes = [Date.now()];

            function show(i) {
                steps.forEach(function(s, idx) {
                    s.style.display = idx === i ? '' : 'none';
                    progress[idx].classList.remove('current');
                    if (idx < i) progress[idx].classList.add('done');
                    else if (idx === i) progress[idx].classList.add('current');
                });
                current = i;
                stepEnterTimes[i] = Date.now();
                try { localStorage.setItem('adspirit_form_' + formId + '_step', String(i)); } catch(e) {}
                window.scrollTo({ top: form.offsetTop - 40, behavior: 'smooth' });
            }

            function validate(stepEl) {
                var inputs = stepEl.querySelectorAll('input[required], select[required], textarea[required]');
                for (var i = 0; i < inputs.length; i++) {
                    if (!inputs[i].value || !inputs[i].value.trim()) {
                        inputs[i].focus();
                        return false;
                    }
                }
                return true;
            }

            form.addEventListener('click', function(e) {
                var btn = e.target.closest('button[data-action]');
                if (!btn) return;
                e.preventDefault();
                var act = btn.dataset.action;
                if (act === 'next') {
                    if (validate(steps[current])) {
                        if (current < steps.length - 1) show(current + 1);
                    }
                } else if (act === 'prev') {
                    if (current > 0) show(current - 1);
                }
            });

            // Persistence: salva valores em localStorage
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
            // Restore on load
            try {
                var saved = JSON.parse(localStorage.getItem('adspirit_form_' + formId) || '{}');
                Object.keys(saved).forEach(function(name) {
                    var el = form.querySelector('[name="' + name + '"]');
                    if (el) el.value = saved[name];
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
                // Telemetria de steps
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

    private function render_field($f, $id) {
        $name = esc_attr($f['name']);
        $type = $f['type'] ?? 'text';
        $req = !empty($f['required']) ? 'required' : '';
        if ($type === 'select' && !empty($f['options'])) {
            echo '<select name="' . $name . '" id="' . esc_attr($id) . '" ' . $req . '>';
            echo '<option value="">— selecione —</option>';
            foreach ($f['options'] as $opt) {
                echo '<option value="' . esc_attr($opt) . '">' . esc_html($opt) . '</option>';
            }
            echo '</select>';
        } elseif ($type === 'textarea') {
            echo '<textarea name="' . $name . '" id="' . esc_attr($id) . '" rows="4" ' . $req . '></textarea>';
        } else {
            echo '<input type="' . esc_attr($type) . '" name="' . $name . '" id="' . esc_attr($id) . '" ' . $req . '>';
        }
    }

    public function handle_submit() {
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

        // Telemetria + step durations
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

        $endpoint = rtrim($core['endpoint_url'], '/') . '/api/webhooks/contact-form-7';
        $response = wp_remote_post($endpoint, array(
            'timeout' => 8,
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-brand-slug' => $core['brand_slug'],
                'x-cf7-secret' => $core['secret'],
                'x-cf7-submission-id' => $form_id . '-' . time() . '-' . wp_generate_password(6, false),
                'User-Agent' => 'AdSpirit-Connector/' . ADSPIRIT_CONNECTOR_VERSION,
            ),
            'body' => wp_json_encode($payload),
        ));

        if (is_wp_error($response)) {
            wp_send_json(array('ok' => false, 'error' => $response->get_error_message()));
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            wp_send_json(array('ok' => false, 'error' => 'CRM HTTP ' . $code));
        }
        wp_send_json(array(
            'ok' => true,
            'message' => $form['success_message'] ?? 'Obrigado!',
        ));
    }

    public function render_builder() {
        // O field-mapping tab também renderiza no slug "forms". Este hook tem
        // priority 9 (vem antes) e adiciona seção "Formulários nativos".
        $forms = self::get_forms();
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Formulários nativos</span>[adspirit_form] shortcode</h2>
        <p class="as-section-help">Forms próprios do plugin, multi-step, sem precisar CF7. Use o shortcode <code>[adspirit_form id="seu-id"]</code> em qualquer página.</p>

        <?php AdSpirit_Menu::card_open('Forms configurados', 'Edite/crie forms multi-step com builder JSON simples'); ?>
        <?php if (empty($forms)): ?>
            <p style="margin:0; color:var(--as-ink-faint); font-size:13px;">Nenhum form criado. Use o builder abaixo pra criar o primeiro.</p>
        <?php else: ?>
            <table class="as-table">
                <thead><tr><th>ID</th><th>Título</th><th>Steps</th><th>Shortcode</th></tr></thead>
                <tbody>
                <?php foreach ($forms as $id => $f): ?>
                    <tr>
                        <td><code><?php echo esc_html($id); ?></code></td>
                        <td><?php echo esc_html($f['title'] ?? '—'); ?></td>
                        <td><?php echo count($f['steps'] ?? array()); ?></td>
                        <td><code>[adspirit_form id="<?php echo esc_html($id); ?>"]</code></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php AdSpirit_Menu::card_close(); ?>

        <?php AdSpirit_Menu::card_open('Builder (JSON)', 'Cole JSON config aqui. Veja default no toggle abaixo'); ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="adspirit_save">
            <input type="hidden" name="adspirit_tab" value="native_form">
            <?php wp_nonce_field('adspirit_native_form_save', '_adspirit_nonce'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="aform_id">ID do form</label></th>
                    <td><input type="text" id="aform_id" name="form_id" class="regular-text" required pattern="[a-z0-9_-]+" placeholder="lead-comercial"></td>
                </tr>
                <tr>
                    <th><label for="aform_json">Config JSON</label></th>
                    <td>
                        <textarea id="aform_json" name="form_json" rows="14" class="large-text" style="font-family:ui-monospace,monospace;"><?php echo esc_textarea(wp_json_encode(self::default_form_config(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)); ?></textarea>
                    </td>
                </tr>
            </table>
            <p class="submit"><button type="submit" class="button button-primary">Salvar form</button></p>
        </form>
        <?php AdSpirit_Menu::card_close(); ?>
        <hr class="as-hr">
        <?php
    }

    public function save_form($post) {
        $id = isset($post['form_id']) ? sanitize_key((string) $post['form_id']) : '';
        $json = isset($post['form_json']) ? (string) $post['form_json'] : '';
        if (!$id) { add_settings_error(self::OPTION_KEY, 'no_id', 'ID inválido.'); return; }
        $data = json_decode(wp_unslash($json), true);
        if (!is_array($data)) { add_settings_error(self::OPTION_KEY, 'bad_json', 'JSON inválido.'); return; }
        $forms = self::get_forms();
        $forms[$id] = $data;
        update_option(self::OPTION_KEY, $forms, false);
        add_settings_error(self::OPTION_KEY, 'saved', 'Form salvo. Use <code>[adspirit_form id="' . esc_html($id) . '"]</code>.', 'updated');
    }
}
