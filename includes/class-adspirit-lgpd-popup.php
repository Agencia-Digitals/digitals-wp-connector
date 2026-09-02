<?php
/**
 * AdSpirit Connector — LGPD/GDPR consent banner.
 *
 * Banner informativo (não opt-in) injetado no wp_footer, com o mesmo
 * design do form qualifier: preto translúcido + glassmorphism (blur),
 * tipografia Inter, sem cor de destaque.
 *
 * Modelo de consentimento: IMPLÍCITO. Conforme a prática aceita no Brasil
 * pra cookies não-sensíveis, ao continuar navegando (scroll / clique /
 * navegação) o visitante concorda — registramos `adspirit_consent=accept_all`.
 * Não há escolha de aceitar/recusar nem categorias granulares: só um aviso
 * mínimo + botão "Entendi". Cookie persiste por 365 dias.
 *
 * Pixel + CAPI + GA4 + telemetria continuam gated em has_consent(); com o
 * consentimento implícito (accept_all) todos passam a true.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Lgpd_Popup {
    const COOKIE = 'adspirit_consent';
    const OPTION_KEY = 'adspirit_connector_lgpd';

    private static $instance = null;
    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action(
            'wp_footer',
            AdSpirit_Safe_Hook::action(array($this, 'inject_popup'), 'lgpd_inject'),
            5
        );
        add_action(
            'wp_enqueue_scripts',
            AdSpirit_Safe_Hook::action(array($this, 'enqueue_assets'), 'lgpd_enqueue')
        );
        add_action(
            'adspirit_connector_render_tab_lgpd',
            AdSpirit_Safe_Hook::action(array($this, 'render_tab'), 'lgpd_tab')
        );
        add_action(
            'adspirit_connector_save_lgpd',
            AdSpirit_Safe_Hook::action(array($this, 'handle_save'), 'lgpd_save')
        );
        add_filter('adspirit_connector_tabs', AdSpirit_Safe_Hook::filter(array($this, 'add_tab'), 'lgpd_tab_register'));
    }

    public function add_tab($tabs) {
        // Insere "Consentimento" antes de "Logs"
        $out = array();
        foreach ($tabs as $slug => $label) {
            if ($slug === 'logs') $out['lgpd'] = 'Consentimento';
            $out[$slug] = $label;
        }
        if (!isset($out['lgpd'])) $out['lgpd'] = 'Consentimento';
        return $out;
    }

    public static function defaults() {
        return array(
            'enabled' => '1',
            'title' => 'Privacidade',
            'message' => 'Usamos cookies para personalizar sua experiência, entender como você usa o site e medir resultados. Ao continuar navegando, você concorda com o uso.',
            'accept_label' => 'Entendi',
            'position' => 'bottom-right', // bottom-right | bottom-center | bottom-banner
            'privacy_url' => '',
            'theme' => 'dark',  // dark | light
            'custom_css' => '', // CSS extra do admin, injetado no fim do <style>
        );
    }

    public static function get_settings() {
        return wp_parse_args(get_option(self::OPTION_KEY, array()), self::defaults());
    }

    public static function has_consent($category = 'all') {
        if (empty($_COOKIE[self::COOKIE])) return false;
        $raw = (string) $_COOKIE[self::COOKIE];
        // Formato: "accept_all" | "essential" | "custom:tracking,analytics"
        if ($raw === 'accept_all') return true;
        if ($raw === 'essential') return $category === 'essential';
        if (strpos($raw, 'custom:') === 0) {
            $cats = explode(',', substr($raw, 7));
            return in_array($category, $cats, true);
        }
        return false;
    }

    public static function has_telemetry_consent() {
        return self::has_consent('tracking') || self::has_consent('all');
    }

    /**
     * v2.2 — usados pelo behavioral tracker (frontend signals) e pelo
     * loader do Microsoft Clarity. Mantêm `has_consent()` como source-of-truth
     * pra evitar duplicação de regra de cookie.
     */
    public static function has_analytics_consent() {
        return self::has_consent('analytics') || self::has_consent('all');
    }

    public static function has_behavioral_consent() {
        return self::has_consent('tracking') || self::has_consent('all');
    }

    /**
     * O banner vai pro HTML desta request? (mesma regra no enqueue e no render)
     *
     * Aqui NÃO se pergunta pelo cookie, e isso é deliberado. A pergunta era
     * feita aqui até 02/09 e produzia um defeito que só aparece com cache de
     * página ligado: quem decide o conteúdo da cópia cacheada é o PRIMEIRO
     * visitante, e a decisão dele passa a valer pra todo mundo. Os dois lados
     * quebram:
     *
     *   · cópia gerada por quem ainda não decidiu → o aviso fica gravado no
     *     HTML e reaparece a cada página, mesmo pra quem já aceitou (foi o
     *     que aconteceu no site da Digitals);
     *   · cópia gerada por quem já aceitou → o aviso some do HTML e ninguém
     *     mais vê, inclusive quem nunca decidiu — que é o lado grave.
     *
     * Então a marcação vai SEMPRE (quando o aviso está ligado) e quem decide
     * é o navegador, lendo o cookie no `lgpd-popup.js`. O elemento nasce
     * `visibility:hidden`, então não há piscada pra quem já aceitou. Custa
     * alguns KB pra esse visitante e, em troca, o comportamento passa a ser o
     * mesmo com ou sem cache — que é o único jeito de isto ser confiável.
     */
    private function should_render() {
        if (is_admin()) return false;
        $c = self::get_settings();
        return $c['enabled'] === '1';
    }

    public function enqueue_assets() {
        if (!$this->should_render()) return;
        $c = self::get_settings();
        $version = defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : '2.30.0';
        wp_enqueue_style('adspirit-lgpd', ADSPIRIT_CONNECTOR_URL . 'assets/lgpd-popup.css', array(), $version);
        // CSS custom do admin: tira < > pra não escapar do <style> (admin-only,
        // mas defensivo). Injetado DEPOIS do arquivo → sobrescreve tudo.
        $custom_css = trim(str_replace(array('<', '>'), '', (string) ($c['custom_css'] ?? '')));
        if ($custom_css !== '') {
            wp_add_inline_style('adspirit-lgpd', $custom_css);
        }
        wp_enqueue_script('adspirit-lgpd', ADSPIRIT_CONNECTOR_URL . 'assets/lgpd-popup.js', array(), $version, true);
        wp_add_inline_script(
            'adspirit-lgpd',
            'window.__adspiritLgpdCfg = ' . wp_json_encode(array('cookie' => self::COOKIE)) . ';',
            'before'
        );
    }

    public function inject_popup() {
        if (!$this->should_render()) return;
        $c = self::get_settings();

        // Centro via margin-auto (não translateX) pra não conflitar com o
        // transform da animação de entrada (translateY).
        $pos_css = array(
            'bottom-right' => 'right: 24px; bottom: 24px; max-width: 380px;',
            'bottom-center' => 'left: 0; right: 0; margin-left: auto; margin-right: auto; bottom: 24px; max-width: 480px;',
            'bottom-banner' => 'left: 0; right: 0; bottom: 0; max-width: none; border-radius: 0; border-bottom: 0;',
        );
        $style = isset($pos_css[$c['position']]) ? $pos_css[$c['position']] : $pos_css['bottom-right'];

        // Tema claro/escuro via classe modificadora.
        $theme_class = ($c['theme'] ?? 'dark') === 'light' ? ' adspirit-lgpd-theme-light' : '';

        ?>
        <div id="adspirit-lgpd" class="adspirit-lgpd<?php echo esc_attr($theme_class); ?>" style="<?php echo esc_attr($style); ?>">
            <div class="adspirit-lgpd-kicker"><?php echo esc_html($c['title']); ?></div>
            <p class="adspirit-lgpd-msg"><?php echo esc_html($c['message']); ?></p>
            <div class="adspirit-lgpd-row">
                <?php if (!empty($c['privacy_url'])): ?>
                    <a class="adspirit-lgpd-link" href="<?php echo esc_url($c['privacy_url']); ?>" target="_blank" rel="noopener">Política de privacidade</a>
                <?php endif; ?>
                <button type="button" class="adspirit-lgpd-btn" onclick="adspiritLgpdOk()"><?php echo esc_html($c['accept_label']); ?></button>
            </div>
        </div>
        <?php
    }

    public function render_tab() {
        $c = self::get_settings();
        $status = $c['enabled'] === '1' ? '<span class="as-badge ok">Ativo</span>' : '<span class="as-badge muted">Desligado</span>';
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Consentimento</span>Aviso de cookies</h2>
        <p class="as-section-help">Aviso pequeno mostrado no rodapé do site. Quem continua navegando (ou clica no botão) aceita — e o aviso não volta por 1 ano.</p>

        <?php
        $is_light = ($c['theme'] ?? 'dark') === 'light';
        $pv_bg    = $is_light ? '#FFFFFF' : 'rgba(9,9,11,0.96)';
        $pv_fg    = $is_light ? '#14181F' : '#F2F2F2';
        $pv_soft  = $is_light ? 'rgba(20,24,31,0.70)' : 'rgba(242,242,242,0.72)';
        $pv_faint = $is_light ? 'rgba(20,24,31,0.50)' : 'rgba(242,242,242,0.48)';
        $pv_btnbg = $is_light ? '#14181F' : 'rgba(255,255,255,0.15)';
        $pv_outer = $is_light ? '#E9EDF2' : 'linear-gradient(120deg,#1a1f29,#2b2233)';
        // Doutrina: o estado (como o aviso está hoje) vem antes do controle.
        AdSpirit_Menu::card_open('Como está no site', 'Reflete o que foi salvo. A fonte real é a do site.', $status);
        ?>
        <div style="background:<?php echo $pv_outer; ?>; padding:34px; border-radius:8px;">
            <div style="background:<?php echo $pv_bg; ?>; color:<?php echo $pv_fg; ?>; border:none; border-radius:14px; padding:20px 22px; max-width:380px; box-shadow:0 6px 26px rgba(0,0,0,0.18); font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif; font-size:13px; line-height:1.6;">
                <div style="font-size:10px; font-weight:600; letter-spacing:0.22em; text-transform:uppercase; color:<?php echo $pv_faint; ?>; margin-bottom:10px;"><?php echo esc_html($c['title']); ?></div>
                <p style="margin:0 0 16px; color:<?php echo $pv_soft; ?>; font-size:12.5px;"><?php echo esc_html($c['message']); ?></p>
                <div style="display:flex; align-items:center; gap:16px; flex-wrap:wrap;">
                    <?php if (!empty($c['privacy_url'])): ?>
                        <span style="color:<?php echo $pv_soft; ?>; text-decoration:underline; text-underline-offset:2px; font-size:11.5px;">Política de privacidade</span>
                    <?php endif; ?>
                    <span style="margin-left:auto; background:<?php echo $pv_btnbg; ?>; color:#fff; padding:9px 28px; border-radius:4px; font-size:0.78rem; font-weight:600; letter-spacing:0.04em; text-transform:uppercase;"><?php echo esc_html($c['accept_label']); ?></span>
                </div>
            </div>
        </div>
        <?php AdSpirit_Menu::card_close(); ?>

        <?php AdSpirit_Menu::card_open('Configuração', 'Textos, tema e posição do aviso'); ?>
        <?php AdSpirit_Menu::form_open('lgpd'); ?>

        <div class="as-toggle">
            <input type="checkbox" id="lgpd_enabled" name="enabled" value="1" <?php checked($c['enabled'], '1'); ?>>
            <label class="t" for="lgpd_enabled">Aviso de cookies ligado<small>Aparece só pra quem ainda não aceitou. Desmarque pra tirar do site sem desinstalar nada.</small></label>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="lgpd_title">Título</label>
            <input type="text" id="lgpd_title" name="title" value="<?php echo esc_attr($c['title']); ?>" class="regular-text">
        </div>

        <div class="as-field">
            <label class="as-field-label" for="lgpd_message">Mensagem</label>
            <textarea id="lgpd_message" name="message" rows="4" class="large-text"><?php echo esc_textarea($c['message']); ?></textarea>
            <p class="description">Mantenha curto — 2 frases no máximo.</p>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="lgpd_accept">Texto do botão</label>
            <input type="text" id="lgpd_accept" name="accept_label" value="<?php echo esc_attr($c['accept_label']); ?>" class="regular-text">
            <p class="description">Botão único de reconhecimento (ex.: "Entendi" / "Continuar").</p>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="lgpd_theme">Tema</label>
            <select id="lgpd_theme" name="theme">
                <option value="dark" <?php selected($c['theme'] ?? 'dark', 'dark'); ?>>Escuro (fundo preto)</option>
                <option value="light" <?php selected($c['theme'] ?? 'dark', 'light'); ?>>Claro (fundo branco)</option>
            </select>
            <p class="description">Escolha conforme o fundo do site. Pra ajuste fino, use o CSS avançado abaixo.</p>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="lgpd_position">Posição</label>
            <select id="lgpd_position" name="position">
                <option value="bottom-right" <?php selected($c['position'], 'bottom-right'); ?>>Canto inferior direito</option>
                <option value="bottom-center" <?php selected($c['position'], 'bottom-center'); ?>>Centro inferior</option>
                <option value="bottom-banner" <?php selected($c['position'], 'bottom-banner'); ?>>Barra inferior (largura toda)</option>
            </select>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="lgpd_privacy">Link da política de privacidade</label>
            <input type="url" id="lgpd_privacy" name="privacy_url" value="<?php echo esc_attr($c['privacy_url']); ?>" class="regular-text" placeholder="https://seusite.com/politica-privacidade">
            <p class="description">Opcional. Com um link aqui, o aviso mostra "Política de privacidade".</p>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="lgpd_custom_css">CSS avançado (opcional)</label>
            <textarea id="lgpd_custom_css" name="custom_css" rows="6" class="large-text code" placeholder="#adspirit-lgpd { /* ... */ }
#adspirit-lgpd .adspirit-lgpd-btn { /* ... */ }"><?php echo esc_textarea($c['custom_css'] ?? ''); ?></textarea>
            <p class="description">Só se precisar de ajuste fino visual — o aviso já funciona sem nada aqui.</p>
        </div>

        <details class="as-help">
            <summary>Como o consentimento funciona + seletores do CSS</summary>
            <ul>
                <li>Consentimento implícito: continuar navegando (rolar ou clicar fora do aviso) conta como aceite.</li>
                <li>O aceite fica no cookie <code><?php echo esc_html(self::COOKIE); ?></code> como <code>accept_all</code> por 365 dias — o aviso não volta.</li>
                <li>A tipografia é herdada do site; o plugin não impõe fonte.</li>
                <li>O CSS avançado é injetado por último (sobrescreve tudo). Seletor raiz: <code>#adspirit-lgpd</code>; partes: <code>.adspirit-lgpd-kicker</code>, <code>.adspirit-lgpd-msg</code>, <code>.adspirit-lgpd-link</code>, <code>.adspirit-lgpd-btn</code>; vars de tema: <code>--lgpd-bg</code>, <code>--lgpd-fg</code>, <code>--lgpd-btn-bg</code>.</li>
            </ul>
        </details>

        <?php AdSpirit_Menu::form_close('Salvar consentimento'); ?>
        <?php AdSpirit_Menu::card_close(); ?>
        <?php
    }

    public function handle_save($post) {
        $patch = array();
        $patch['enabled'] = !empty($post['enabled']) ? '1' : '0';
        foreach (array('title','accept_label','position','privacy_url') as $k) {
            if (isset($post[$k])) {
                $patch[$k] = sanitize_text_field((string) $post[$k]);
            }
        }
        if (isset($post['message'])) {
            $patch['message'] = sanitize_textarea_field((string) $post['message']);
        }
        if (isset($post['theme'])) {
            $patch['theme'] = $post['theme'] === 'light' ? 'light' : 'dark';
        }
        if (isset($post['custom_css'])) {
            // CSS multiline: só remove < > pra não escapar do <style> (admin-only).
            $patch['custom_css'] = trim(str_replace(array('<', '>'), '', (string) $post['custom_css']));
        }
        $merged = wp_parse_args($patch, self::get_settings());
        update_option(self::OPTION_KEY, $merged, false);
        add_settings_error(self::OPTION_KEY, 'saved', 'Configurações de consentimento salvas.', 'updated');
    }
}
