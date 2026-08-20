<?php
/**
 * AdSpirit Connector — Mailchimp passthrough dedicado.
 *
 * Subscribe contatos do CF7 (e qualquer payload normalizado) numa List
 * Mailchimp via Marketing API v3.
 *
 *   POST https://{dc}.api.mailchimp.com/3.0/lists/{list_id}/members
 *     Auth: Basic anystring:{API_KEY}
 *     Body: { email_address, status, merge_fields:{FNAME,LNAME,PHONE}, tags }
 *
 * Idempotência:
 *   - email_hash = MD5(lowercase email) é o identificador único do membro
 *     dentro da lista. Re-submeter o mesmo email com POST devolve 400
 *     "Member Exists" — esse erro é tratado como sucesso silencioso
 *     (fire-and-forget; já temos o contato).
 *
 * Data Center:
 *   - API Key Mailchimp termina em "-usN" (ex: a1b2c3d4-us21).
 *     A substring depois do último "-" é o DC. Detectado em runtime.
 *
 * Disparo:
 *   - Hook em 'adspirit_connector_dispatch_lead' (action paralela do CF7
 *     handler, futura) OU chamada explícita via dispatch_for_payload().
 *   - Por hora, ligamos no fanout: hook tardio depois do POST pro CRM.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Mailchimp {
    const OPTION_KEY = 'adspirit_connector_mailchimp';
    const LOG_KEY    = 'adspirit_connector_mailchimp_log';
    const LOG_MAX    = 100;

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Tab register + render + save
        add_filter('adspirit_connector_tabs', AdSpirit_Safe_Hook::filter(array($this, 'register_tab'), 'mailchimp_tab_register'));
        add_action(
            'adspirit_connector_render_tab_mailchimp',
            AdSpirit_Safe_Hook::action(array($this, 'render_tab'), 'mailchimp_tab')
        );
        add_action(
            'adspirit_connector_save_mailchimp',
            AdSpirit_Safe_Hook::action(array($this, 'handle_save'), 'mailchimp_save')
        );

        // Botão "Testar API" → AJAX → ping
        add_action(
            'wp_ajax_adspirit_mailchimp_ping',
            AdSpirit_Safe_Hook::action(array($this, 'ajax_ping'), 'mailchimp_ping')
        );

        // Hook no CF7 fanout — toda submissão dispatch pra Mailchimp se
        // habilitado. Action genérica que o CF7 handler chama no final.
        add_action(
            'adspirit_connector_cf7_dispatched',
            AdSpirit_Safe_Hook::action(array($this, 'on_cf7_dispatched'), 'mailchimp_cf7'),
            10,
            2
        );
    }

    // ─────────────────────────────────────────────────────────
    // Settings storage
    // ─────────────────────────────────────────────────────────
    public static function defaults() {
        return array(
            'enabled'      => '0',
            'api_key'      => '',
            'list_id'      => '',
            'status'       => 'subscribed',   // ou 'pending' (double opt-in)
            'tags_default' => '',             // CSV: "AdSpirit, Lead"
        );
    }

    public static function get_config() {
        return wp_parse_args(get_option(self::OPTION_KEY, array()), self::defaults());
    }

    public static function update_config(array $patch) {
        $current = self::get_config();
        update_option(self::OPTION_KEY, array_merge($current, $patch), false);
    }

    // ─────────────────────────────────────────────────────────
    // Data Center detect
    // ─────────────────────────────────────────────────────────
    /**
     * Mailchimp API key tem formato "xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-usN".
     * Tudo depois do último "-" é o data center (ex: "us21", "us6").
     * Retorna '' se o key estiver vazio/malformado.
     */
    public static function detect_dc($api_key) {
        $api_key = trim((string) $api_key);
        if ($api_key === '') return '';
        $pos = strrpos($api_key, '-');
        if ($pos === false || $pos === strlen($api_key) - 1) return '';
        $dc = substr($api_key, $pos + 1);
        // Sanity: dc é alfanumérico curto (us1..us21, etc).
        if (!preg_match('/^[a-z0-9]{2,10}$/i', $dc)) return '';
        return strtolower($dc);
    }

    public static function base_url($api_key) {
        $dc = self::detect_dc($api_key);
        if ($dc === '') return '';
        return 'https://' . $dc . '.api.mailchimp.com/3.0';
    }

    // ─────────────────────────────────────────────────────────
    // Dispatch
    // ─────────────────────────────────────────────────────────

    /**
     * Recebe payload canônico (mesmo formato do CF7 handler:
     *   your-name, your-email, Telefone, etc) e POSTa pro Mailchimp.
     *
     * Fire-and-forget (blocking=false). Erros vão pro log circular +
     * error_log; não bloqueiam o flow do CF7.
     */
    public static function dispatch_for_payload(array $payload) {
        $cfg = self::get_config();
        if ($cfg['enabled'] !== '1') return null;
        if (empty($cfg['api_key']) || empty($cfg['list_id'])) {
            self::log('skipped', 0, 'config_incompleta');
            return null;
        }
        $email = isset($payload['your-email']) ? trim((string) $payload['your-email']) : '';
        if ($email === '' || !is_email($email)) {
            self::log('skipped', 0, 'email_invalido');
            return null;
        }

        $base = self::base_url($cfg['api_key']);
        if ($base === '') {
            self::log('error', 0, 'api_key_malformado');
            return null;
        }

        // Merge fields a partir do payload canônico
        $full_name = isset($payload['your-name']) ? trim((string) $payload['your-name']) : '';
        $first = '';
        $last  = '';
        if ($full_name !== '') {
            $parts = preg_split('/\s+/', $full_name, 2);
            $first = isset($parts[0]) ? $parts[0] : '';
            $last  = isset($parts[1]) ? $parts[1] : '';
        }
        $phone = isset($payload['Telefone']) ? trim((string) $payload['Telefone']) : '';

        $merge_fields = array();
        if ($first !== '') $merge_fields['FNAME'] = $first;
        if ($last  !== '') $merge_fields['LNAME'] = $last;
        if ($phone !== '') $merge_fields['PHONE'] = $phone;

        // Tags default (CSV)
        $tags = array();
        if (!empty($cfg['tags_default'])) {
            $tags = array_filter(array_map('trim', explode(',', (string) $cfg['tags_default'])));
            $tags = array_values($tags);
        }

        $body = array(
            'email_address' => $email,
            'status'        => $cfg['status'] === 'pending' ? 'pending' : 'subscribed',
        );
        if (!empty($merge_fields)) $body['merge_fields'] = $merge_fields;
        if (!empty($tags))         $body['tags']         = $tags;

        $url = $base . '/lists/' . rawurlencode((string) $cfg['list_id']) . '/members';

        $args = array(
            'timeout'  => 6,
            'blocking' => false, // fire-and-forget
            'headers'  => array(
                'Content-Type'  => 'application/json',
                'Authorization' => 'Basic ' . base64_encode('anystring:' . $cfg['api_key']),
                'User-Agent'    => 'AdSpirit-Connector/' . ADSPIRIT_CONNECTOR_VERSION,
            ),
            'body'     => wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        );

        $response = wp_remote_post($url, $args);
        if (is_wp_error($response)) {
            self::log('error', 0, $response->get_error_message(), array('email' => $email));
            error_log('[AdSpirit Connector] Mailchimp dispatch failed: ' . $response->get_error_message());
            return false;
        }

        // blocking=false não retorna http_code aqui. Log otimista.
        self::log('sent', 0, null, array(
            'email'        => $email,
            'list_id'      => $cfg['list_id'],
            'merge_fields' => array_keys($merge_fields),
            'tags'         => $tags,
        ));
        return true;
    }

    /**
     * Hook callback — chamado pelo CF7 handler depois do POST pro CRM.
     * Espera $payload como arg 0; $form_id como arg 1 (ignorado por enquanto).
     */
    public function on_cf7_dispatched($payload, $form_id = null) {
        if (!is_array($payload)) return;
        self::dispatch_for_payload($payload);
    }

    // ─────────────────────────────────────────────────────────
    // AJAX: ping
    // ─────────────────────────────────────────────────────────

    /**
     * Botão "Testar API" — GET /3.0/ping. Retorna JSON com ok/code/body.
     * Usa o API key digitado no form (sem precisar salvar antes).
     */
    public function ajax_ping() {
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) {
            wp_send_json(array('ok' => false, 'error' => 'forbidden'), 403);
        }
        check_ajax_referer('adspirit_mailchimp_ping');

        $api_key = isset($_POST['api_key']) ? sanitize_text_field((string) wp_unslash($_POST['api_key'])) : '';
        // Fallback pro key salvo
        if ($api_key === '') {
            $cfg = self::get_config();
            $api_key = (string) $cfg['api_key'];
        }
        if ($api_key === '') {
            wp_send_json(array(
                'ok'    => false,
                'error' => 'api_key_vazio',
                'hint'  => 'Cole o API Key (formato xxxx-usN) antes de testar.',
            ));
        }
        $base = self::base_url($api_key);
        if ($base === '') {
            wp_send_json(array(
                'ok'    => false,
                'error' => 'api_key_malformado',
                'hint'  => 'Esperado terminar com "-usN" (ex: a1b2c3d4-us21).',
            ));
        }

        $url = $base . '/ping';
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'Authorization' => 'Basic ' . base64_encode('anystring:' . $api_key),
                'User-Agent'    => 'AdSpirit-Connector/' . ADSPIRIT_CONNECTOR_VERSION,
            ),
        ));

        if (is_wp_error($response)) {
            wp_send_json(array(
                'ok'      => false,
                'error'   => 'network',
                'message' => $response->get_error_message(),
            ));
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $parsed = json_decode($body, true);

        wp_send_json(array(
            'ok'        => $code === 200,
            'http_code' => $code,
            'dc'        => self::detect_dc($api_key),
            'endpoint'  => $url,
            'response'  => $parsed ?: $body,
        ));
    }

    // ─────────────────────────────────────────────────────────
    // Logs
    // ─────────────────────────────────────────────────────────
    public static function log($status, $http_code, $error = null, array $extra = array()) {
        $log = get_option(self::LOG_KEY, array());
        if (!is_array($log)) $log = array();
        $entry = array(
            'at'        => current_time('c'),
            'status'    => $status,
            'http_code' => $http_code,
            'error'     => $error,
        );
        if (!empty($extra)) $entry = array_merge($entry, $extra);
        array_unshift($log, $entry);
        if (count($log) > self::LOG_MAX) {
            $log = array_slice($log, 0, self::LOG_MAX);
        }
        update_option(self::LOG_KEY, $log, false);
    }

    public static function get_logs() {
        $log = get_option(self::LOG_KEY, array());
        return is_array($log) ? $log : array();
    }

    // ─────────────────────────────────────────────────────────
    // Tab
    // ─────────────────────────────────────────────────────────
    public function register_tab($tabs) {
        // Insere "Mailchimp" antes de "Logs" se possível
        if (!is_array($tabs)) return $tabs;
        $new = array();
        $inserted = false;
        foreach ($tabs as $slug => $label) {
            if ($slug === 'logs' && !$inserted) {
                $new['mailchimp'] = 'Mailchimp';
                $inserted = true;
            }
            $new[$slug] = $label;
        }
        if (!$inserted) $new['mailchimp'] = 'Mailchimp';
        return $new;
    }

    public function render_tab() {
        $c = self::get_config();
        $dc = self::detect_dc($c['api_key']);
        $status_badge = $c['enabled'] === '1'
            ? '<span class="as-badge ok">Ativo</span>'
            : '<span class="as-badge muted">Desligado</span>';
        $ping_nonce = wp_create_nonce('adspirit_mailchimp_ping');
        $ajax_url   = admin_url('admin-ajax.php');
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Mailchimp</span>Leads na lista do Mailchimp</h2>
        <p class="as-section-help">Cada lead do site é inscrito na sua audiência do Mailchimp — sem duplicar quem já está na lista.</p>

        <?php // Doutrina: o dado (últimos envios) vem antes do controle. ?>
        <?php $this->render_logs_card(); ?>

        <?php AdSpirit_Menu::card_open(
            'Credenciais Mailchimp',
            'API Key em <em>Account → Extras → API keys</em>; Audience ID em <em>Audience → Settings</em>',
            $status_badge
        ); ?>
        <?php AdSpirit_Menu::form_open('mailchimp'); ?>

        <div class="as-toggle">
            <input type="checkbox" id="mc_enabled" name="enabled" value="1" <?php checked($c['enabled'], '1'); ?>>
            <label class="t" for="mc_enabled">Envio pro Mailchimp ligado<small>Cada lead do site entra na lista escolhida abaixo.</small></label>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="mc_api_key">Chave da API (API Key)</label>
            <input type="password" id="mc_api_key" name="api_key" value="<?php echo esc_attr($c['api_key']); ?>" class="regular-text" autocomplete="off" placeholder="xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-us21">
            <button type="button" class="button" onclick="var e=document.getElementById('mc_api_key');e.type=e.type==='password'?'text':'password';">Mostrar</button>
            <p class="description">
                Termina em <code>-usN</code>. Servidor detectado:
                <strong id="mc-dc-badge"><?php echo $dc ? esc_html($dc) : '<em style="color:var(--as-ink-faint)">aguardando a chave</em>'; ?></strong>
            </p>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="mc_list_id">Audience ID (List ID)</label>
            <input type="text" id="mc_list_id" name="list_id" value="<?php echo esc_attr($c['list_id']); ?>" class="regular-text" placeholder="a1b2c3d4e5">
            <p class="description">10 letras e números. Está em Audience → Settings → Audience name &amp; defaults.</p>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="mc_status">Como o lead entra na lista</label>
            <select id="mc_status" name="status">
                <option value="subscribed" <?php selected($c['status'], 'subscribed'); ?>>Inscrito direto, sem confirmação</option>
                <option value="pending"    <?php selected($c['status'], 'pending'); ?>>Com email de confirmação (double opt-in)</option>
            </select>
            <p class="description">A confirmação por email é mais segura pra reputação da sua conta Mailchimp.</p>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="mc_tags">Tags aplicadas</label>
            <input type="text" id="mc_tags" name="tags_default" value="<?php echo esc_attr($c['tags_default']); ?>" class="regular-text" placeholder="AdSpirit, Lead CF7">
            <p class="description">Separadas por vírgula. Todo lead inscrito por aqui recebe essas tags.</p>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="adspirit-mc-test-btn">Testar a chave</label>
            <button type="button" class="button" id="adspirit-mc-test-btn">Testar API agora</button>
            <p class="description">Usa a chave digitada acima, sem precisar salvar antes. O resultado aparece logo abaixo.</p>
            <pre class="as-test-result" id="adspirit-mc-test-result" style="display:none; margin-top:12px;"></pre>
        </div>

        <?php AdSpirit_Menu::form_close('Salvar Mailchimp'); ?>
        <?php AdSpirit_Menu::card_close(); ?>

        <script>
        (function() {
            var apiInput = document.getElementById('mc_api_key');
            var dcBadge  = document.getElementById('mc-dc-badge');
            function recomputeDc() {
                if (!apiInput || !dcBadge) return;
                var v = (apiInput.value || '').trim();
                var idx = v.lastIndexOf('-');
                if (idx < 0 || idx === v.length - 1) {
                    dcBadge.innerHTML = '<em style="color:var(--as-ink-faint)">aguardando key</em>';
                    return;
                }
                var dc = v.substring(idx + 1).toLowerCase();
                if (!/^[a-z0-9]{2,10}$/.test(dc)) {
                    dcBadge.innerHTML = '<em style="color:var(--as-danger)">formato inválido</em>';
                    return;
                }
                dcBadge.textContent = dc;
            }
            if (apiInput) {
                apiInput.addEventListener('input', recomputeDc);
                recomputeDc();
            }

            var btn = document.getElementById('adspirit-mc-test-btn');
            var box = document.getElementById('adspirit-mc-test-result');
            if (!btn) return;
            btn.addEventListener('click', function() {
                btn.disabled = true;
                var original = btn.textContent;
                btn.textContent = 'Testando…';
                box.style.display = 'block';
                box.textContent = 'Aguarde…';
                var fd = new FormData();
                fd.append('action', 'adspirit_mailchimp_ping');
                fd.append('_wpnonce', '<?php echo esc_js($ping_nonce); ?>');
                fd.append('api_key', apiInput ? apiInput.value : '');
                fetch('<?php echo esc_js($ajax_url); ?>', { method: 'POST', body: fd, credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        box.textContent = JSON.stringify(d, null, 2);
                        btn.disabled = false;
                        btn.textContent = original;
                    })
                    .catch(function(err) {
                        box.textContent = 'Erro: ' + err.message;
                        btn.disabled = false;
                        btn.textContent = original;
                    });
            });
        })();
        </script>
        <?php
    }

    private function render_logs_card() {
        $logs = self::get_logs();
        AdSpirit_Menu::card_open(
            'Últimos envios',
            'Os ' . self::LOG_MAX . ' eventos mais recentes ficam guardados aqui.'
        );
        if (empty($logs)) {
            echo '<p class="description">Nenhum envio registrado ainda. Quando um lead for pro Mailchimp, ele aparece aqui.</p>';
        } else {
            echo '<table class="as-table"><thead><tr>'
                . '<th>Quando</th><th>Status</th><th>Email</th><th>Detalhes</th>'
                . '</tr></thead><tbody>';
            foreach (array_slice($logs, 0, 25) as $entry) {
                $when = isset($entry['at']) ? $entry['at'] : '';
                $st   = isset($entry['status']) ? $entry['status'] : '';
                $email = isset($entry['email']) ? $entry['email'] : '';
                $badge = 'muted';
                if ($st === 'sent')    $badge = 'ok';
                if ($st === 'error')   $badge = 'danger';
                if ($st === 'skipped') $badge = 'warn';
                $detail = isset($entry['error']) && $entry['error']
                    ? esc_html($entry['error'])
                    : esc_html(wp_json_encode(array_diff_key(
                        $entry,
                        array('at' => 1, 'status' => 1, 'http_code' => 1, 'error' => 1, 'email' => 1)
                    )));
                echo '<tr>'
                    . '<td><code>' . esc_html($when) . '</code></td>'
                    . '<td><span class="as-badge ' . esc_attr($badge) . '">' . esc_html($st) . '</span></td>'
                    . '<td>' . esc_html($email) . '</td>'
                    . '<td>' . $detail . '</td>'
                    . '</tr>';
            }
            echo '</tbody></table>';
        }
        AdSpirit_Menu::card_close();
    }

    // ─────────────────────────────────────────────────────────
    // Save handler
    // ─────────────────────────────────────────────────────────
    public function handle_save($post) {
        $patch = array();
        $patch['enabled'] = !empty($post['enabled']) ? '1' : '0';
        $patch['api_key'] = sanitize_text_field((string) ($post['api_key'] ?? ''));
        $patch['list_id'] = sanitize_text_field((string) ($post['list_id'] ?? ''));
        $status = sanitize_key((string) ($post['status'] ?? 'subscribed'));
        $patch['status'] = in_array($status, array('subscribed', 'pending'), true) ? $status : 'subscribed';
        $patch['tags_default'] = sanitize_text_field((string) ($post['tags_default'] ?? ''));

        // Valida API key se preenchido
        if ($patch['api_key'] !== '' && self::detect_dc($patch['api_key']) === '') {
            add_settings_error(
                self::OPTION_KEY,
                'invalid_key',
                'API Key inválido: precisa terminar em "-usN" (ex: a1b2c3d4-us21).',
                'error'
            );
            // Salva mesmo assim — user pode estar editando aos poucos
        }

        self::update_config($patch);
        add_settings_error(self::OPTION_KEY, 'saved', 'Mailchimp salvo.', 'updated');
    }
}
