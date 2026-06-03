<?php
/**
 * AdSpirit Connector — Anti-spam embutido.
 *
 * 4 camadas (cada uma toggle independente):
 *   1. Honeypot: campo invisível adicionado em todo form CF7. Humano não
 *      vê / não preenche; bot preenche e a gente bloqueia.
 *   2. Time trap: cookie com timestamp setado no page load. Submissão em
 *      menos de N segundos = bot.
 *   3. Rate limit por IP: > X submits em 1min = bloqueia.
 *   4. Blocklist: regex de email + palavras-chave em qualquer campo.
 *
 * Hook: filter `wpcf7_validate` (vai pré-validar a submission).
 *
 * Stats: log circular de blocks (últimos 100), mostrado em Logs tab.
 *
 * Compatível com WP Armour, GoTC, etc. Rodam em sequência — qualquer um
 * que rejeite, rejeita.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Anti_Spam {
    const LOG_MAX = 100;
    const HONEYPOT_FIELD = 'adspirit_hp';
    const TIME_COOKIE = 'adspirit_t';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // CF7 filters / frontend hooks — wrapped pra nunca quebrar página
        add_filter(
            'wpcf7_form_elements',
            AdSpirit_Safe_Hook::filter(array($this, 'inject_honeypot'), 'antispam_honeypot')
        );
        add_action(
            'wp_head',
            AdSpirit_Safe_Hook::action(array($this, 'inject_time_cookie'), 'antispam_time_cookie')
        );
        add_filter(
            'wpcf7_validate',
            AdSpirit_Safe_Hook::filter(array($this, 'validate_submission'), 'antispam_validate'),
            5,
            2
        );

        // Admin tab — wrapped também pra não derrubar wp-admin
        add_action(
            'adspirit_connector_render_tab_antispam',
            AdSpirit_Safe_Hook::action(array($this, 'render_tab'), 'antispam_render_tab')
        );
        add_action(
            'adspirit_connector_save_antispam',
            AdSpirit_Safe_Hook::action(array($this, 'handle_save'), 'antispam_save')
        );
    }

    // ─────────────────────────────────────────────────────────
    // HONEYPOT: campo invisível injetado em todos os forms CF7
    // ─────────────────────────────────────────────────────────
    public function inject_honeypot($form_elements) {
        $cfg = AdSpirit_Settings::get_antispam();
        if ($cfg['enabled'] !== '1' || $cfg['honeypot'] !== '1') return $form_elements;

        $hidden = sprintf(
            '<div style="position:absolute;left:-9999px;width:1px;height:1px;overflow:hidden;" aria-hidden="true">
                <label for="%1$s">Não preencher este campo</label>
                <input type="text" id="%1$s" name="%1$s" tabindex="-1" autocomplete="off" value="">
            </div>',
            esc_attr(self::HONEYPOT_FIELD)
        );
        return $hidden . $form_elements;
    }

    // ─────────────────────────────────────────────────────────
    // TIME TRAP: seta cookie no page load, valida no submit
    // ─────────────────────────────────────────────────────────
    public function inject_time_cookie() {
        $cfg = AdSpirit_Settings::get_antispam();
        if ($cfg['enabled'] !== '1' || $cfg['time_trap'] !== '1') return;
        // Cookie HTTPOnly não — precisa ser lido pelo JS pra renovar.
        // Setado via JS pra evitar issues de header-already-sent.
        ?>
        <script>
        (function() {
            try {
                if (!document.cookie.match(/(?:^|;\s*)<?php echo esc_js(self::TIME_COOKIE); ?>=/)) {
                    document.cookie = '<?php echo esc_js(self::TIME_COOKIE); ?>=' + Date.now() + ';path=/;max-age=3600;samesite=lax';
                }
            } catch (e) {}
        })();
        </script>
        <?php
    }

    public function validate_submission($result, $tags) {
        $cfg = AdSpirit_Settings::get_antispam();
        if ($cfg['enabled'] !== '1') return $result;

        // (1) Honeypot
        if ($cfg['honeypot'] === '1') {
            $hp = isset($_POST[self::HONEYPOT_FIELD]) ? trim((string) $_POST[self::HONEYPOT_FIELD]) : '';
            if ($hp !== '') {
                $this->reject($result, 'honeypot', 'Honeypot preenchido — provavelmente bot.');
                return $result;
            }
        }

        // (2) Time trap
        if ($cfg['time_trap'] === '1') {
            $min_s = max(0, intval($cfg['time_trap_min_s']));
            if ($min_s > 0) {
                $cookie_ts = isset($_COOKIE[self::TIME_COOKIE]) ? intval($_COOKIE[self::TIME_COOKIE]) : 0;
                if ($cookie_ts > 0) {
                    $delta_ms = (time() * 1000) - $cookie_ts;
                    if ($delta_ms < $min_s * 1000) {
                        $this->reject($result, 'time_trap', sprintf('Submetido em %.1fs — abaixo do mínimo de %ds.', $delta_ms / 1000, $min_s));
                        return $result;
                    }
                }
            }
        }

        // (3) Rate limit por IP
        if ($cfg['rate_limit'] === '1') {
            $max = max(1, intval($cfg['rate_limit_max']));
            $ip = $this->client_ip();
            $key = 'adspirit_rl_' . md5($ip);
            $count = (int) get_transient($key);
            if ($count >= $max) {
                $this->reject($result, 'rate_limit', sprintf('IP %s excedeu %d submits/min.', $ip, $max));
                return $result;
            }
            set_transient($key, $count + 1, 60);
        }

        // (4) Blocklist
        if (!empty($cfg['blocklist_emails']) || !empty($cfg['blocklist_words'])) {
            $email = isset($_POST['your-email']) ? strtolower(trim((string) $_POST['your-email'])) : '';
            $patterns = preg_split('/\r?\n/', trim((string) $cfg['blocklist_emails']));
            foreach ($patterns as $pattern) {
                $pattern = trim($pattern);
                if (!$pattern) continue;
                $regex = '/' . str_replace('/', '\\/', $pattern) . '/i';
                if (@preg_match($regex, $email)) {
                    $this->reject($result, 'blocklist_email', 'Email casou com blocklist: ' . $pattern);
                    return $result;
                }
            }

            $words = preg_split('/\r?\n/', trim((string) $cfg['blocklist_words']));
            $all_text = '';
            foreach ($_POST as $k => $v) {
                if (is_string($v)) $all_text .= ' ' . $v;
            }
            $all_text = strtolower($all_text);
            foreach ($words as $word) {
                $word = trim(strtolower($word));
                if (!$word) continue;
                if (strpos($all_text, $word) !== false) {
                    $this->reject($result, 'blocklist_word', 'Texto contém palavra bloqueada: ' . $word);
                    return $result;
                }
            }
        }

        return $result;
    }

    private function reject($result, $reason_code, $reason_text) {
        // Invalida o form. CF7 mostra mensagem genérica de erro.
        $result->invalidate('honeypot-or-spam', __('Submissão rejeitada.', 'adspirit-connector'));
        $this->log_block($reason_code, $reason_text);
    }

    private function client_ip() {
        $ip = isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '0.0.0.0';
        // Trust proxy headers comuns em hosts modernos
        if (!empty($_SERVER['HTTP_CF_CONNECTING_IP'])) return (string) $_SERVER['HTTP_CF_CONNECTING_IP'];
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']);
            return trim($parts[0]);
        }
        return $ip;
    }

    public function log_block($code, $message) {
        $log = get_option(AdSpirit_Settings::OPTION_ANTISPAM_LOG, array());
        if (!is_array($log)) $log = array();
        array_unshift($log, array(
            'at'      => current_time('c'),
            'code'    => $code,
            'message' => $message,
            'ip'      => $this->client_ip(),
        ));
        if (count($log) > self::LOG_MAX) {
            $log = array_slice($log, 0, self::LOG_MAX);
        }
        update_option(AdSpirit_Settings::OPTION_ANTISPAM_LOG, $log, false);
    }

    // ─────────────────────────────────────────────────────────
    // TAB UI
    // ─────────────────────────────────────────────────────────
    public function render_tab() {
        $c = AdSpirit_Settings::get_antispam();
        $log = get_option(AdSpirit_Settings::OPTION_ANTISPAM_LOG, array());
        if (!is_array($log)) $log = array();

        AdSpirit_Menu::form_open('antispam');
        ?>
        <h2>Anti-spam</h2>
        <p class="description">4 camadas independentes. Roda em sequência — qualquer uma que rejeite, bloqueia. Compatível com WP Armour / outros plugins (eles também filtram em paralelo).</p>

        <table class="form-table">
            <tr>
                <th><label>Status geral</label></th>
                <td>
                    <label>
                        <input type="checkbox" name="enabled" value="1" <?php checked($c['enabled'], '1'); ?>>
                        Ativar anti-spam embutido (mestre)
                    </label>
                    <p class="description">Desligar aqui bypassa todas as camadas. Útil pra debug temporário.</p>
                </td>
            </tr>
            <tr>
                <th><label>Honeypot</label></th>
                <td>
                    <label>
                        <input type="checkbox" name="honeypot" value="1" <?php checked($c['honeypot'], '1'); ?>>
                        Injetar campo invisível em todos os forms CF7
                    </label>
                    <p class="description">Bots preenchem todos os campos por reflex; humanos não veem o campo escondido.</p>
                </td>
            </tr>
            <tr>
                <th><label>Time trap</label></th>
                <td>
                    <label>
                        <input type="checkbox" name="time_trap" value="1" <?php checked($c['time_trap'], '1'); ?>>
                        Rejeitar submits muito rápidos
                    </label>
                    <p class="description">Mínimo: <input type="number" name="time_trap_min_s" min="1" max="30" value="<?php echo esc_attr($c['time_trap_min_s']); ?>" class="small-text"> segundos entre carregar a página e submeter. Bots costumam disparar em menos de 1s.</p>
                </td>
            </tr>
            <tr>
                <th><label>Rate limit por IP</label></th>
                <td>
                    <label>
                        <input type="checkbox" name="rate_limit" value="1" <?php checked($c['rate_limit'], '1'); ?>>
                        Limitar submits do mesmo IP
                    </label>
                    <p class="description">Máximo: <input type="number" name="rate_limit_max" min="1" max="20" value="<?php echo esc_attr($c['rate_limit_max']); ?>" class="small-text"> submits/minuto.</p>
                </td>
            </tr>
            <tr>
                <th><label for="blocklist_emails">Blocklist de emails (regex)</label></th>
                <td>
                    <textarea id="blocklist_emails" name="blocklist_emails" rows="5" class="large-text code"><?php echo esc_textarea($c['blocklist_emails']); ?></textarea>
                    <p class="description">Um regex por linha. Ex.: <code>@example\.ru$</code> bloqueia qualquer @example.ru. <code>^(test|spam)</code> bloqueia emails começando com test/spam.</p>
                </td>
            </tr>
            <tr>
                <th><label for="blocklist_words">Blocklist de palavras</label></th>
                <td>
                    <textarea id="blocklist_words" name="blocklist_words" rows="5" class="large-text code"><?php echo esc_textarea($c['blocklist_words']); ?></textarea>
                    <p class="description">Uma palavra por linha. Match case-insensitive em QUALQUER campo do form. Útil pra bloquear spam de tópicos específicos.</p>
                </td>
            </tr>
        </table>
        <?php
        AdSpirit_Menu::form_close('Salvar anti-spam');

        ?>
        <h2>Últimos bloqueios (até 100)</h2>
        <?php if (empty($log)): ?>
            <p>Nenhum bloqueio registrado ainda.</p>
        <?php else: ?>
            <table class="widefat striped">
                <thead>
                    <tr>
                        <th>Quando</th>
                        <th>Camada</th>
                        <th>Motivo</th>
                        <th>IP</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($log as $entry): ?>
                        <tr>
                            <td><?php echo esc_html($entry['at']); ?></td>
                            <td><code><?php echo esc_html($entry['code']); ?></code></td>
                            <td><?php echo esc_html($entry['message']); ?></td>
                            <td><code><?php echo esc_html($entry['ip']); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
        <?php
    }

    public function handle_save($post) {
        $patch = array();
        $patch['enabled']         = !empty($post['enabled']) ? '1' : '0';
        $patch['honeypot']        = !empty($post['honeypot']) ? '1' : '0';
        $patch['time_trap']       = !empty($post['time_trap']) ? '1' : '0';
        $patch['time_trap_min_s'] = max(1, intval($post['time_trap_min_s'] ?? 2));
        $patch['rate_limit']      = !empty($post['rate_limit']) ? '1' : '0';
        $patch['rate_limit_max']  = max(1, intval($post['rate_limit_max'] ?? 3));
        $patch['blocklist_emails']= sanitize_textarea_field((string) ($post['blocklist_emails'] ?? ''));
        $patch['blocklist_words'] = sanitize_textarea_field((string) ($post['blocklist_words'] ?? ''));
        AdSpirit_Settings::update_antispam($patch);
        add_settings_error(AdSpirit_Settings::OPTION_ANTISPAM, 'saved', 'Anti-spam salvo.', 'updated');
    }
}
