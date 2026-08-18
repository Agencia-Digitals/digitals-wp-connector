<?php
/**
 * AdSpirit Turnstile — Cloudflare Turnstile invisível pra anti-bot avançado.
 *
 * Complementa AdSpirit_Anti_Spam (honeypot + time-trap + UA + blocklists)
 * pegando uma categoria que essas validações não cobrem: **headless Chrome
 * com Puppeteer/Playwright** que finge ser browser real. Cloudflare cruza
 * com sinais que só ele tem (bilhões de requests/dia) — invisível ao
 * usuário humano normal, captcha visual só quando suspeita real.
 *
 * Setup:
 *   1. Cliente cria conta gratis em cloudflare.com (NÃO precisa migrar DNS)
 *   2. Dashboard → Turnstile → Add Site → escolhe modo "Invisible"
 *   3. Cola site_key + secret_key na aba AdSpirit → Cloudflare Turnstile
 *   4. Ativa toggle "Aplicar no qualifier"
 *
 * Custo: zero, ilimitado, sem cobrança por request.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Turnstile {
    const OPTION_KEY = 'adspirit_turnstile';
    const VERIFY_URL = 'https://challenges.cloudflare.com/turnstile/v0/siteverify';
    const WIDGET_JS = 'https://challenges.cloudflare.com/turnstile/v0/api.js';

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_filter('adspirit_connector_tabs',
            AdSpirit_Safe_Hook::filter(array($this, 'register_tab'), 'turnstile_tab_register'));
        add_action('adspirit_connector_render_tab_turnstile',
            AdSpirit_Safe_Hook::action(array($this, 'render_tab'), 'turnstile_tab_render'));
        add_action('adspirit_connector_save_turnstile',
            AdSpirit_Safe_Hook::action(array($this, 'handle_save'), 'turnstile_save'));

        // v2.10.3: hooks no fluxo CF7 SOMENTE quando Turnstile está realmente
        // ativo + opt-in pra CF7. Antes ficavam sempre registrados (early-return
        // dentro do handler), mas isso somava 1 invocação extra a cada submit CF7
        // e podia interferir em sites com cache de JS minificado / muitos plugins.
        // Sem opt-in, ZERO interferência no fluxo CF7 existente.
        if (self::is_active() && self::applies_to_cf7()) {
            add_filter('wpcf7_validate',
                AdSpirit_Safe_Hook::filter(array($this, 'maybe_validate_cf7'), 'turnstile_cf7_validate'),
                20, 2);
        }

        // Enqueue script no front somente se Turnstile ativo (qualifier OU CF7).
        // Idem: sem ativação, nenhum script de terceiros (cloudflare) é carregado.
        if (self::is_active()) {
            add_action('wp_enqueue_scripts',
                AdSpirit_Safe_Hook::action(array($this, 'maybe_enqueue_widget'), 'turnstile_enqueue'));
        }
    }

    public function register_tab($tabs) {
        $tabs['turnstile'] = 'Cloudflare Turnstile';
        return $tabs;
    }

    public static function defaults() {
        return array(
            'enabled' => '0',
            'site_key' => '',
            'secret_key' => '',
            'apply_qualifier' => '1', // sempre on quando turnstile ativo
            'apply_cf7' => '0',       // opt-in
        );
    }

    public static function get_settings() {
        return wp_parse_args(get_option(self::OPTION_KEY, array()), self::defaults());
    }

    /** True se Turnstile está completamente configurado e ativo. */
    public static function is_active() {
        $s = self::get_settings();
        return ($s['enabled'] ?? '0') === '1'
            && !empty($s['site_key'])
            && !empty($s['secret_key']);
    }

    /** True se deve aplicar no qualifier (toggle on + configurado). */
    public static function applies_to_qualifier() {
        if (!self::is_active()) return false;
        $s = self::get_settings();
        return ($s['apply_qualifier'] ?? '1') === '1';
    }

    public static function applies_to_cf7() {
        if (!self::is_active()) return false;
        $s = self::get_settings();
        return ($s['apply_cf7'] ?? '0') === '1';
    }

    /**
     * Verifica token contra Cloudflare. Retorna ['valid'=>bool, 'reason'=>string|null].
     * Fail-open em erro de rede: se Cloudflare não responde em 5s, deixa passar
     * pra não derrubar leads legítimos.
     */
    public static function verify_token($token) {
        if (!self::is_active()) return array('valid' => true, 'reason' => 'turnstile_inactive');
        if (empty($token)) return array('valid' => false, 'reason' => 'token_missing');

        $s = self::get_settings();
        $body = array(
            'secret' => $s['secret_key'],
            'response' => (string) $token,
            'remoteip' => isset($_SERVER['REMOTE_ADDR']) ? (string) $_SERVER['REMOTE_ADDR'] : '',
        );
        $response = wp_remote_post(self::VERIFY_URL, array(
            'timeout' => 5,
            'body' => $body,
            'headers' => array('Content-Type' => 'application/x-www-form-urlencoded'),
        ));
        if (is_wp_error($response)) {
            // Fail-open em erro de rede pra não bloquear leads legítimos
            error_log('[AdSpirit Turnstile] verify network error: ' . $response->get_error_message());
            return array('valid' => true, 'reason' => 'fail_open_network');
        }
        $code = wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            error_log('[AdSpirit Turnstile] verify HTTP ' . $code);
            return array('valid' => true, 'reason' => 'fail_open_http_' . $code);
        }
        $json = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($json)) return array('valid' => true, 'reason' => 'fail_open_parse');
        if (!empty($json['success'])) return array('valid' => true, 'reason' => null);
        $codes = isset($json['error-codes']) && is_array($json['error-codes']) ? implode(',', $json['error-codes']) : 'unknown';
        return array('valid' => false, 'reason' => 'cf_rejected:' . $codes);
    }

    public function maybe_enqueue_widget() {
        if (is_admin()) return;
        if (!self::is_active()) return;
        wp_enqueue_script('cf-turnstile-api', self::WIDGET_JS, array(), null, true);
    }

    /**
     * Injeta o widget invisible no form. Chamado por shortcodes / handlers
     * que querem aplicar Turnstile.
     */
    public static function render_widget($options = array()) {
        if (!self::is_active()) return '';
        $s = self::get_settings();
        $opts = wp_parse_args($options, array(
            'callback' => 'adspiritTurnstileCallback',
            'action' => 'submit',
            'size' => 'invisible',
            'theme' => 'auto',
        ));
        return sprintf(
            '<div class="cf-turnstile" data-sitekey="%s" data-callback="%s" data-action="%s" data-size="%s" data-theme="%s"></div>',
            esc_attr($s['site_key']),
            esc_attr($opts['callback']),
            esc_attr($opts['action']),
            esc_attr($opts['size']),
            esc_attr($opts['theme'])
        );
    }

    /** Hook CF7: valida turnstile quando opt-in. */
    public function maybe_validate_cf7($result, $tags) {
        if (!self::applies_to_cf7()) return $result;
        $token = isset($_POST['cf-turnstile-response']) ? (string) $_POST['cf-turnstile-response'] : '';
        $check = self::verify_token($token);
        if (empty($check['valid'])) {
            $result->invalidate('cf-turnstile-response', __('Verificação anti-bot falhou. Recarregue e tente de novo.', 'adspirit-connector'));
            if (class_exists('AdSpirit_Anti_Spam') && method_exists('AdSpirit_Anti_Spam', 'log_block')) {
                AdSpirit_Anti_Spam::instance()->log_block('cf7_turnstile', $check['reason'] ?? 'rejected');
            }
        }
        return $result;
    }

    public function handle_save($post) {
        $patch = array(
            'enabled' => !empty($post['enabled']) ? '1' : '0',
            'site_key' => isset($post['site_key']) ? sanitize_text_field((string) $post['site_key']) : '',
            'secret_key' => isset($post['secret_key']) ? sanitize_text_field((string) $post['secret_key']) : '',
            'apply_qualifier' => !empty($post['apply_qualifier']) ? '1' : '0',
            'apply_cf7' => !empty($post['apply_cf7']) ? '1' : '0',
        );
        $merged = wp_parse_args($patch, self::get_settings());
        update_option(self::OPTION_KEY, $merged, false);
        add_settings_error(self::OPTION_KEY, 'saved', 'Configurações de Turnstile salvas.', 'updated');
    }

    public function render_tab() {
        $s = self::get_settings();
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Anti-bot</span>Cloudflare Turnstile</h2>
        <p class="as-section-help">
            Captcha invisível da Cloudflare (substituto do reCAPTCHA do Google).
            <strong>Grátis e ilimitado.</strong> Pega bots sofisticados (headless Chrome / Puppeteer)
            que o anti-spam tradicional não pega. Usuário humano não vê nada.
        </p>

        <?php AdSpirit_Menu::card_open('Como configurar (5 min)'); ?>
        <ol style="margin:0; padding-left:24px; font-size:13.5px;">
            <li>Crie conta gratuita em <a href="https://dash.cloudflare.com/sign-up" target="_blank" rel="noopener">cloudflare.com</a> (NÃO precisa migrar DNS do site)</li>
            <li>No dashboard, vá em <strong>Turnstile</strong> → <strong>Add Site</strong></li>
            <li>Preencha:
                <ul style="margin:8px 0 8px 16px;">
                    <li>Site name: o que quiser (ex.: "AdSpirit Forms")</li>
                    <li>Hostnames: seu domínio (ex.: <code><?php echo esc_html(parse_url(home_url(), PHP_URL_HOST) ?: 'exemplo.com'); ?></code>)</li>
                    <li>Widget mode: <strong>Invisible</strong></li>
                </ul>
            </li>
            <li>Cole <strong>Site Key</strong> e <strong>Secret Key</strong> nos campos abaixo</li>
            <li>Marque "Habilitar Turnstile" e Salvar</li>
        </ol>
        <?php AdSpirit_Menu::card_close(); ?>

        <?php AdSpirit_Menu::form_open('turnstile'); ?>
        <?php AdSpirit_Menu::card_open('Configuração'); ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Habilitar Turnstile</th>
                <td>
                    <label><input type="checkbox" name="enabled" value="1" <?php checked($s['enabled'], '1'); ?>> Ativar verificação Cloudflare Turnstile</label>
                    <p class="description">Quando desativado, plugin se comporta como antes (sem Turnstile).</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="t-site-key">Site Key</label></th>
                <td>
                    <input type="text" id="t-site-key" name="site_key" value="<?php echo esc_attr($s['site_key']); ?>" class="regular-text" placeholder="0x4AAAAAA...">
                    <p class="description">Chave pública (aparece no HTML do site). Copiada do dashboard Cloudflare → Turnstile → Settings.</p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="t-secret-key">Secret Key</label></th>
                <td>
                    <input type="password" id="t-secret-key" name="secret_key" value="<?php echo esc_attr($s['secret_key']); ?>" class="regular-text" placeholder="0x4AAAAAA...">
                    <p class="description">Chave privada (NÃO publicada). Usada pra verificar token no servidor.</p>
                </td>
            </tr>
            <tr>
                <th scope="row">Aplicar em</th>
                <td>
                    <label><input type="checkbox" name="apply_qualifier" value="1" <?php checked($s['apply_qualifier'], '1'); ?>> <code>[adspirit_form_qualifier]</code></label><br>
                    <label><input type="checkbox" name="apply_cf7" value="1" <?php checked($s['apply_cf7'], '1'); ?>> Forms do Contact Form 7</label>
                    <p class="description">CF7 fica opt-in pra não quebrar forms legacy com lógicas específicas. Qualifier vem ligado por padrão.</p>
                </td>
            </tr>
        </table>
        <?php AdSpirit_Menu::card_close(); ?>
        <?php // form_close() já imprime o botão de salvar — não duplicar aqui. ?>
        <?php AdSpirit_Menu::form_close(); ?>

        <?php if (self::is_active()) : ?>
            <?php AdSpirit_Menu::card_open('Status'); ?>
            <p>✓ Turnstile <strong>ativo</strong>. Site key configurada: <code><?php echo esc_html(substr($s['site_key'], 0, 12) . '…'); ?></code></p>
            <p>Aplicando em:
                <?php if (self::applies_to_qualifier()) : ?> <strong>[adspirit_form_qualifier]</strong><?php endif; ?>
                <?php if (self::applies_to_cf7()) : ?> · <strong>Contact Form 7</strong><?php endif; ?>
            </p>
            <?php AdSpirit_Menu::card_close(); ?>
        <?php endif; ?>
        <?php
    }
}
