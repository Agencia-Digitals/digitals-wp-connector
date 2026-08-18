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
        // P0-2: form fora do escopo fica 100% intocado (nem honeypot).
        if (!$this->cf7_current_form_in_scope()) return $form_elements;

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

    /**
     * P0-2: o form CF7 sendo renderizado/validado agora está no escopo?
     * Sem form identificável → true (comportamento histórico, não bloqueia).
     * A checagem real (allowlist) vive em AdSpirit_Cf7_Handler::form_in_scope.
     */
    private function cf7_current_form_in_scope() {
        if (!class_exists('AdSpirit_Cf7_Handler')) return true;
        $form = function_exists('wpcf7_get_current_contact_form') ? wpcf7_get_current_contact_form() : null;
        if (!$form) return true;
        return AdSpirit_Cf7_Handler::form_in_scope($form->id());
    }

    public function validate_submission($result, $tags) {
        $cfg = AdSpirit_Settings::get_antispam();
        if ($cfg['enabled'] !== '1') return $result;
        // P0-2: form fora do escopo não passa pelo anti-spam do plugin.
        if (!$this->cf7_current_form_in_scope()) return $result;

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

        // (4) User-Agent check — bots costumam mandar UA vazio ou claramente
        //     malicioso. Cobertura equivalente ao WP Armour.
        if (($cfg['ua_check'] ?? '1') === '1') {
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string) $_SERVER['HTTP_USER_AGENT']) : '';
            if ($ua === '') {
                $this->reject($result, 'ua_empty', 'User-Agent vazio — assinatura comum de bot.');
                return $result;
            }
            // Bloqueia UAs claramente automatizados que ignoram cabeçalhos
            // padrão de browser. Lista conservadora pra não falsos positivos.
            $bad_ua_signals = array('python-requests', 'curl/', 'wget/', 'Go-http-client', 'libwww-perl');
            foreach ($bad_ua_signals as $sig) {
                if (stripos($ua, $sig) !== false) {
                    $this->reject($result, 'ua_bot', 'User-Agent de cliente HTTP automatizado: ' . $sig);
                    return $result;
                }
            }
        }

        // (5) Reverse text trap — texto sem stopwords PT-BR + entropia alta
        if (($cfg['reverse_trap'] ?? '1') === '1' && class_exists('AdSpirit_Quickwins')) {
            $all_text = '';
            foreach ($_POST as $k => $v) {
                if (strpos($k, '_adspirit_') === 0) continue; // ignora nossos meta
                if (is_string($v)) $all_text .= ' ' . $v;
            }
            if (AdSpirit_Quickwins::is_suspicious_text($all_text)) {
                $this->reject($result, 'reverse_text', 'Texto com alta entropia + sem palavras comuns — provável bot.');
                return $result;
            }
        }

        // (6) Blocklist
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

    /**
     * v2.9: helper público pra outros handlers (qualifier, etc) reusarem
     * a mesma engine de validação sem depender do hook wpcf7_validate.
     *
     * @param array $payload Dados do form ($_POST equivalente).
     * @param string $email Email canônico (se houver).
     * @return array ['valid'=>bool, 'reason_code'=>string|null, 'reason_text'=>string|null]
     *
     * Não retorna early na primeira falha — sempre completa pra log
     * mais informativo no caller. (Caller decide o que fazer.)
     */
    public static function validate_payload(array $payload, $email = '') {
        if (!class_exists('AdSpirit_Settings')) return array('valid' => true, 'reason_code' => null, 'reason_text' => null);
        $cfg = AdSpirit_Settings::get_antispam();
        if ($cfg['enabled'] !== '1') return array('valid' => true, 'reason_code' => null, 'reason_text' => null);

        // (1) Honeypot — campo escondido preenchido = bot
        if ($cfg['honeypot'] === '1') {
            $hp = isset($payload[self::HONEYPOT_FIELD]) ? trim((string) $payload[self::HONEYPOT_FIELD]) : '';
            if ($hp !== '') return array('valid' => false, 'reason_code' => 'honeypot', 'reason_text' => 'Honeypot preenchido.');
        }

        // (2) Time trap via timestamp explícito no payload (front envia _adspirit_ts)
        if ($cfg['time_trap'] === '1') {
            $min_s = max(0, intval($cfg['time_trap_min_s']));
            if ($min_s > 0) {
                $ts_ms = isset($payload['_adspirit_ts']) ? intval($payload['_adspirit_ts']) : 0;
                if ($ts_ms > 0) {
                    $delta_ms = (time() * 1000) - $ts_ms;
                    if ($delta_ms < $min_s * 1000) {
                        return array('valid' => false, 'reason_code' => 'time_trap',
                            'reason_text' => sprintf('Submetido em %.1fs (min %ds).', $delta_ms / 1000, $min_s));
                    }
                }
            }
        }

        // (3) Rate limit por IP — bucket dedicado pra payload validation
        if ($cfg['rate_limit'] === '1') {
            $max = max(1, intval($cfg['rate_limit_max']));
            $ip = isset($_SERVER['HTTP_CF_CONNECTING_IP']) ? trim((string) $_SERVER['HTTP_CF_CONNECTING_IP'])
                : (isset($_SERVER['HTTP_X_FORWARDED_FOR']) ? trim(explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR'])[0])
                : (isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : ''));
            if ($ip !== '') {
                $key = 'adspirit_rl_payload_' . md5($ip);
                $count = (int) get_transient($key);
                if ($count >= $max) {
                    return array('valid' => false, 'reason_code' => 'rate_limit',
                        'reason_text' => sprintf('IP excedeu %d submits/min.', $max));
                }
                set_transient($key, $count + 1, 60);
            }
        }

        // (4) User-Agent check
        if (($cfg['ua_check'] ?? '1') === '1') {
            $ua = isset($_SERVER['HTTP_USER_AGENT']) ? trim((string) $_SERVER['HTTP_USER_AGENT']) : '';
            if ($ua === '') return array('valid' => false, 'reason_code' => 'ua_empty', 'reason_text' => 'User-Agent vazio.');
            $bad = array('python-requests', 'curl/', 'wget/', 'Go-http-client', 'libwww-perl');
            foreach ($bad as $sig) {
                if (stripos($ua, $sig) !== false) {
                    return array('valid' => false, 'reason_code' => 'ua_bot', 'reason_text' => 'UA cliente HTTP automatizado.');
                }
            }
        }

        // (5) Reverse text trap
        if (($cfg['reverse_trap'] ?? '1') === '1' && class_exists('AdSpirit_Quickwins')) {
            $all_text = '';
            foreach ($payload as $k => $v) {
                if (strpos((string) $k, '_adspirit_') === 0) continue;
                if (is_string($v)) $all_text .= ' ' . $v;
            }
            if (AdSpirit_Quickwins::is_suspicious_text($all_text)) {
                return array('valid' => false, 'reason_code' => 'reverse_text', 'reason_text' => 'Texto suspeito (alta entropia).');
            }
        }

        // (6) Blocklist
        if (!empty($cfg['blocklist_emails'])) {
            $em = strtolower(trim((string) $email));
            $patterns = preg_split('/\r?\n/', trim((string) $cfg['blocklist_emails']));
            foreach ($patterns as $pattern) {
                $pattern = trim($pattern);
                if (!$pattern) continue;
                $regex = '/' . str_replace('/', '\\/', $pattern) . '/i';
                if (@preg_match($regex, $em)) {
                    return array('valid' => false, 'reason_code' => 'blocklist_email', 'reason_text' => 'Email bloqueado: ' . $pattern);
                }
            }
        }
        if (!empty($cfg['blocklist_words'])) {
            $words = preg_split('/\r?\n/', trim((string) $cfg['blocklist_words']));
            $all_text = '';
            foreach ($payload as $v) if (is_string($v)) $all_text .= ' ' . $v;
            $all_text = strtolower($all_text);
            foreach ($words as $word) {
                $word = trim(strtolower($word));
                if (!$word) continue;
                if (strpos($all_text, $word) !== false) {
                    return array('valid' => false, 'reason_code' => 'blocklist_word', 'reason_text' => 'Texto contém: ' . $word);
                }
            }
        }

        return array('valid' => true, 'reason_code' => null, 'reason_text' => null);
    }

    private function reject($result, $reason_code, $reason_text) {
        // Invalida o form. CF7 mostra mensagem genérica de erro.
        $result->invalidate('honeypot-or-spam', __('Submissão rejeitada.', 'adspirit-connector'));
        $this->log_block($reason_code, $reason_text);

        // Connector 3.0 — quarentena: bloqueio nunca descarta em silêncio.
        // Vai pra aba Submissões com status 'spam' + motivo; falso positivo
        // se resgata com Reenviar. Fail-soft e com anti-flood no Lead Store.
        if (class_exists('AdSpirit_Lead_Store')) {
            $payload = array();
            foreach ($_POST as $k => $v) {
                $key = (string) $k;
                if ($key === 'action' || strpos($key, '_wp') === 0 || strpos($key, '_adspirit') === 0) continue;
                if (!is_scalar($v)) continue;
                $payload[$key] = sanitize_text_field((string) $v);
            }
            $form_id = '';
            if (class_exists('WPCF7_Submission')) {
                $sub = WPCF7_Submission::get_instance();
                $cf = $sub ? $sub->get_contact_form() : null;
                if ($cf) $form_id = (string) $cf->id();
            }
            AdSpirit_Lead_Store::record_spam($payload, 'cf7', $form_id, $reason_code . ': ' . $reason_text);
        }
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
        $status_badge = $c['enabled'] === '1' ? '<span class="as-badge ok">Ativo</span>' : '<span class="as-badge muted">Desligado</span>';
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Anti-spam</span>Bloqueio automático de bots</h2>
        <p class="as-section-help">O que for barrado fica registrado abaixo e vai pra quarentena em Leads enviados — nada some em silêncio.</p>

        <?php // Doutrina: o dado (o que foi bloqueado) vem antes do controle. ?>
        <?php if (empty($log)): ?>
            <div class="as-notice info"><p>Nenhum bloqueio registrado ainda. Quando alguma camada barrar um envio, ele aparece aqui.</p></div>
        <?php else: ?>
            <table class="as-table">
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
                            <td><span class="as-badge muted"><?php echo esc_html($entry['code']); ?></span></td>
                            <td><?php echo esc_html($entry['message']); ?></td>
                            <td><code><?php echo esc_html($entry['ip']); ?></code></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <?php AdSpirit_Menu::card_open('Camadas de proteção', 'Cada camada é independente — ligue só o que quiser', $status_badge); ?>
        <?php AdSpirit_Menu::form_open('antispam'); ?>

        <div class="as-toggle">
            <input type="checkbox" id="as-anti-enabled" name="enabled" value="1" <?php checked($c['enabled'], '1'); ?>>
            <label class="t" for="as-anti-enabled">Proteção anti-spam ligada<small>Chave geral: desligar aqui pausa todas as camadas abaixo.</small></label>
        </div>

        <div class="as-toggle">
            <input type="checkbox" id="as-anti-honeypot" name="honeypot" value="1" <?php checked($c['honeypot'], '1'); ?>>
            <label class="t" for="as-anti-honeypot">Campo-armadilha invisível<small>Robôs preenchem um campo que pessoas não veem — e se denunciam.</small></label>
        </div>

        <div class="as-toggle">
            <input type="checkbox" id="as-anti-timetrap" name="time_trap" value="1" <?php checked($c['time_trap'], '1'); ?>>
            <label class="t" for="as-anti-timetrap">Barrar envios rápidos demais<small>Robô envia o formulário em menos de 1 segundo; pessoa não.</small></label>
        </div>
        <div class="as-toggle as-sub">
            <label class="t" for="as-anti-timetrap-min">Tempo mínimo: <input type="number" id="as-anti-timetrap-min" name="time_trap_min_s" min="1" max="30" value="<?php echo esc_attr($c['time_trap_min_s']); ?>" style="width:60px;"> segundos entre abrir a página e enviar</label>
        </div>

        <div class="as-toggle">
            <input type="checkbox" id="as-anti-rate" name="rate_limit" value="1" <?php checked($c['rate_limit'], '1'); ?>>
            <label class="t" for="as-anti-rate">Limitar envios repetidos do mesmo visitante<small>Bloqueia rajadas: o mesmo endereço não passa do limite por minuto.</small></label>
        </div>
        <div class="as-toggle as-sub">
            <label class="t" for="as-anti-rate-max">Limite: <input type="number" id="as-anti-rate-max" name="rate_limit_max" min="1" max="20" value="<?php echo esc_attr($c['rate_limit_max']); ?>" style="width:60px;"> envios por minuto</label>
        </div>

        <div class="as-toggle">
            <input type="checkbox" id="as-anti-ua" name="ua_check" value="1" <?php checked($c['ua_check'] ?? '1', '1'); ?>>
            <label class="t" for="as-anti-ua">Barrar programas automatizados<small>Rejeita envios de ferramentas que não são um navegador de verdade.</small></label>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="blocklist_emails">Emails bloqueados</label>
            <textarea id="blocklist_emails" name="blocklist_emails" rows="5" class="large-text"><?php echo esc_textarea($c['blocklist_emails']); ?></textarea>
            <p class="description">Um padrão por linha. Ex.: <code>@example\.ru$</code> barra qualquer email terminado em @example.ru.</p>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="blocklist_words">Palavras bloqueadas</label>
            <textarea id="blocklist_words" name="blocklist_words" rows="5" class="large-text"><?php echo esc_textarea($c['blocklist_words']); ?></textarea>
            <p class="description">Uma palavra por linha. Barra o envio se ela aparecer em qualquer campo do formulário.</p>
        </div>

        <details class="as-help">
            <summary>Como as camadas funcionam</summary>
            <ul>
                <li>As camadas rodam em sequência — basta uma rejeitar pra barrar o envio.</li>
                <li>Cobrem o mesmo escopo de plugins como WP Armour (com limite por IP, que eles não têm) — dá pra desativá-los.</li>
                <li>Na lista de emails, cada linha é uma expressão regular: <code>^(test|spam)</code> barra emails começando com test ou spam.</li>
                <li>A busca por palavras ignora maiúsculas/minúsculas e olha todos os campos do formulário.</li>
                <li>Bloqueio não descarta: o envio vai pra quarentena em Leads enviados e pode ser resgatado se for engano.</li>
            </ul>
        </details>

        <?php AdSpirit_Menu::form_close('Salvar anti-spam'); ?>
        <?php AdSpirit_Menu::card_close(); ?>
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
        $patch['ua_check']        = !empty($post['ua_check']) ? '1' : '0';
        $patch['blocklist_emails']= sanitize_textarea_field((string) ($post['blocklist_emails'] ?? ''));
        $patch['blocklist_words'] = sanitize_textarea_field((string) ($post['blocklist_words'] ?? ''));
        AdSpirit_Settings::update_antispam($patch);
        add_settings_error(AdSpirit_Settings::OPTION_ANTISPAM, 'saved', 'Anti-spam salvo.', 'updated');
    }
}
