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

    public function inject_popup() {
        if (is_admin()) return;
        $c = self::get_settings();
        if ($c['enabled'] !== '1') return;
        // Não mostra se já tem decisão
        if (!empty($_COOKIE[self::COOKIE])) return;

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
        // CSS custom do admin: tira < > pra não escapar do <style> (admin-only,
        // mas defensivo). Injetado por último → sobrescreve tudo.
        $custom_css = trim((string) ($c['custom_css'] ?? ''));
        $custom_css = str_replace(array('<', '>'), '', $custom_css);

        ?>
        <style id="adspirit-lgpd-css">
        #adspirit-lgpd{
            position:fixed; z-index:99999;
            /* Tema escuro (default) via custom properties — o tema claro só
               troca as vars. Minimalista: SEM contorno; o fundo blur dá presença. */
            --lgpd-bg:rgba(9,9,11,0.88);
            --lgpd-fg:#F2F2F2;
            --lgpd-fg-soft:rgba(242,242,242,0.72);
            --lgpd-fg-faint:rgba(242,242,242,0.48);
            --lgpd-link:rgba(242,242,242,0.60);
            --lgpd-btn-bg:rgba(255,255,255,0.15);
            --lgpd-btn-bg-hover:rgba(255,255,255,0.28);
            --lgpd-btn-fg:#fff;
            background:var(--lgpd-bg);
            -webkit-backdrop-filter:blur(28px) saturate(130%);
            backdrop-filter:blur(28px) saturate(130%);
            color:var(--lgpd-fg);
            border:none;
            border-radius:14px;
            padding:20px 22px;
            box-shadow:0 6px 26px rgba(0,0,0,0.20);
            /* Puxa a tipografia do SITE (não impõe fonte própria do plugin). */
            font-family:inherit;
            font-weight:400; font-size:13px; line-height:1.6;
            /* Estado "armado": invisível esperando o timer de 10s. */
            opacity:0; visibility:hidden; transition:opacity 320ms ease;
        }
        /* Tema claro — resolve a maioria dos sites de fundo claro. */
        #adspirit-lgpd.adspirit-lgpd-theme-light{
            --lgpd-bg:rgba(255,255,255,0.92);
            --lgpd-fg:#14181F;
            --lgpd-fg-soft:rgba(20,24,31,0.70);
            --lgpd-fg-faint:rgba(20,24,31,0.50);
            --lgpd-link:rgba(20,24,31,0.62);
            --lgpd-btn-bg:#14181F;
            --lgpd-btn-bg-hover:#000;
            --lgpd-btn-fg:#fff;
            box-shadow:0 6px 26px rgba(0,0,0,0.12);
        }
        /* Entrada estilo tela de login do AdSpirit: blur-dissolve + fade + leve
           subida. Sem 'forwards' pra que o fade-out no dismiss funcione. */
        #adspirit-lgpd.adspirit-lgpd-show{
            opacity:1; visibility:visible;
            animation:adspirit-lgpd-in 1000ms cubic-bezier(0.16, 1, 0.3, 1);
        }
        @keyframes adspirit-lgpd-in{
            from{ opacity:0; filter:blur(10px); transform:translateY(16px); }
        }
        #adspirit-lgpd .adspirit-lgpd-kicker{
            font-size:10px; font-weight:600; letter-spacing:0.22em; text-transform:uppercase;
            color:var(--lgpd-fg-faint); margin:0 0 10px;
        }
        #adspirit-lgpd .adspirit-lgpd-msg{ margin:0 0 16px; color:var(--lgpd-fg-soft); font-size:12.5px; }
        #adspirit-lgpd .adspirit-lgpd-row{ display:flex; align-items:center; gap:16px; flex-wrap:wrap; }
        #adspirit-lgpd .adspirit-lgpd-link{
            color:var(--lgpd-link); text-decoration:underline; text-underline-offset:2px;
            font-size:11.5px; transition:color 160ms ease;
        }
        #adspirit-lgpd .adspirit-lgpd-link:hover{ color:var(--lgpd-fg); }
        #adspirit-lgpd .adspirit-lgpd-btn{
            margin-left:auto;
            background:var(--lgpd-btn-bg); color:var(--lgpd-btn-fg); border:0;
            padding:9px 28px; border-radius:4px;
            font-family:inherit;
            font-size:0.78rem; font-weight:600; letter-spacing:0.04em; text-transform:uppercase;
            cursor:pointer; transition:background-color .4s ease; line-height:1.6;
        }
        #adspirit-lgpd .adspirit-lgpd-btn:hover{ background:var(--lgpd-btn-bg-hover); }
        #adspirit-lgpd .adspirit-lgpd-btn:focus-visible{ outline:2px solid var(--lgpd-fg); outline-offset:2px; }
        @media (prefers-reduced-motion: reduce){
            #adspirit-lgpd{ transition:none; }
            #adspirit-lgpd.adspirit-lgpd-show{ animation:none; }
        }
        /* Mobile: card full-width com gutter, independente da posição do desktop. */
        @media (max-width:520px){
            #adspirit-lgpd{
                left:12px !important; right:12px !important; bottom:12px !important;
                top:auto !important; max-width:none !important; width:auto !important;
                border-radius:14px !important;
                padding:18px 18px;
            }
            #adspirit-lgpd .adspirit-lgpd-row{ gap:12px; }
            #adspirit-lgpd .adspirit-lgpd-btn{ margin-left:0; flex:1 1 auto; text-align:center; }
        }
        <?php if ($custom_css !== ''): ?>
        /* ---- CSS custom (admin) ---- */
        <?php echo $custom_css; ?>

        <?php endif; ?>
        </style>
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
        <script>
        (function(){
            var BANNER = document.getElementById('adspirit-lgpd');
            if (!BANNER) return;
            var done = false;
            function consent(reload){
                if (done) return; done = true;
                var d = new Date(); d.setTime(d.getTime() + 365*86400000);
                document.cookie = '<?php echo esc_js(self::COOKIE); ?>=accept_all;expires=' + d.toUTCString() + ';path=/;samesite=lax';
                BANNER.style.opacity = '0';
                setTimeout(function(){ if (BANNER && BANNER.parentNode) BANNER.parentNode.removeChild(BANNER); }, 320);
                window.removeEventListener('scroll', onScroll);
                document.removeEventListener('click', onClick, true);
                if (reload) setTimeout(function(){ location.reload(); }, 260);
            }
            // Consentimento implícito: ao continuar navegando (scroll, clique
            // em qualquer lugar fora do banner, ou navegação) o visitante aceita.
            function onScroll(){ if ((window.pageYOffset || document.documentElement.scrollTop) > 140) consent(false); }
            function onClick(e){ if (BANNER.contains(e.target)) return; consent(false); }
            // Botão "Entendi": registra e recarrega pra tracking começar já nesta página.
            window.adspiritLgpdOk = function(){ consent(true); };
            // Entra só 10s depois do load (entrada estilo login). Os listeners de
            // consentimento implícito só armam quando o banner aparece — antes
            // disso não há aviso, então não há consentimento a registrar.
            setTimeout(function(){
                if (done) return;
                BANNER.classList.add('adspirit-lgpd-show');
                window.addEventListener('scroll', onScroll, { passive: true });
                document.addEventListener('click', onClick, true);
            }, 10000);
        })();
        </script>
        <?php
    }

    public function render_tab() {
        $c = self::get_settings();
        $status = $c['enabled'] === '1' ? '<span class="as-badge ok">Ativo</span>' : '<span class="as-badge muted">Desligado</span>';
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Consentimento</span>Banner LGPD informativo</h2>
        <p class="as-section-help">Banner pequeno injetado no rodapé, blur + sem contorno. Tema claro/escuro e CSS próprio dão a customização; a <strong>tipografia é puxada do site</strong> (o plugin não impõe fonte). Consentimento <strong>implícito</strong>: ao continuar navegando o visitante aceita. Cookie <code><?php echo esc_html(self::COOKIE); ?></code> registra <code>accept_all</code> por 365 dias.</p>

        <?php AdSpirit_Menu::card_open('Configuração', 'Liga/desliga, textos, tema, posição e CSS próprio', $status); ?>
        <?php AdSpirit_Menu::form_open('lgpd'); ?>
        <table class="form-table">
            <tr>
                <th>Banner</th>
                <td><label><input type="checkbox" name="enabled" value="1" <?php checked($c['enabled'], '1'); ?>> <strong>Ativo</strong> — mostrar pra visitantes sem consentimento</label>
                    <p class="description">Desmarque pra <strong>desativar</strong> o banner no site (sem desinstalar o plugin).</p></td>
            </tr>
            <tr>
                <th><label for="lgpd_title">Título</label></th>
                <td><input type="text" id="lgpd_title" name="title" value="<?php echo esc_attr($c['title']); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><label for="lgpd_message">Mensagem</label></th>
                <td>
                    <textarea id="lgpd_message" name="message" rows="4" class="large-text"><?php echo esc_textarea($c['message']); ?></textarea>
                    <p class="description">Mantenha curto — 2 frases máx.</p>
                </td>
            </tr>
            <tr>
                <th><label for="lgpd_accept">Texto do botão</label></th>
                <td><input type="text" id="lgpd_accept" name="accept_label" value="<?php echo esc_attr($c['accept_label']); ?>" class="regular-text">
                    <p class="description">Botão único de reconhecimento (ex.: "Entendi" / "Continuar").</p></td>
            </tr>
            <tr>
                <th><label for="lgpd_theme">Tema</label></th>
                <td>
                    <select id="lgpd_theme" name="theme">
                        <option value="dark" <?php selected($c['theme'] ?? 'dark', 'dark'); ?>>Escuro (fundo preto)</option>
                        <option value="light" <?php selected($c['theme'] ?? 'dark', 'light'); ?>>Claro (fundo branco)</option>
                    </select>
                    <p class="description">Resolve a maioria dos casos conforme o fundo do site. Pra ajustes finos, use o CSS abaixo.</p>
                </td>
            </tr>
            <tr>
                <th><label for="lgpd_position">Posição</label></th>
                <td>
                    <select id="lgpd_position" name="position">
                        <option value="bottom-right" <?php selected($c['position'], 'bottom-right'); ?>>Canto inferior direito</option>
                        <option value="bottom-center" <?php selected($c['position'], 'bottom-center'); ?>>Centro inferior</option>
                        <option value="bottom-banner" <?php selected($c['position'], 'bottom-banner'); ?>>Barra inferior (banner full-width)</option>
                    </select>
                </td>
            </tr>
            <tr>
                <th><label for="lgpd_privacy">URL da política</label></th>
                <td><input type="url" id="lgpd_privacy" name="privacy_url" value="<?php echo esc_attr($c['privacy_url']); ?>" class="regular-text" placeholder="https://seusite.com/politica-privacidade"></td>
            </tr>
            <tr>
                <th><label for="lgpd_custom_css">CSS personalizado</label></th>
                <td>
                    <textarea id="lgpd_custom_css" name="custom_css" rows="6" class="large-text code" placeholder="#adspirit-lgpd { /* ... */ }
#adspirit-lgpd .adspirit-lgpd-btn { /* ... */ }"><?php echo esc_textarea($c['custom_css'] ?? ''); ?></textarea>
                    <p class="description">Injetado por último (sobrescreve tudo). Seletor raiz: <code>#adspirit-lgpd</code>. Partes: <code>.adspirit-lgpd-kicker</code>, <code>.adspirit-lgpd-msg</code>, <code>.adspirit-lgpd-link</code>, <code>.adspirit-lgpd-btn</code>. Vars de tema: <code>--lgpd-bg</code>, <code>--lgpd-fg</code>, <code>--lgpd-btn-bg</code>.</p>
                </td>
            </tr>
        </table>
        <?php AdSpirit_Menu::form_close('Salvar consentimento'); ?>
        <?php AdSpirit_Menu::card_close(); ?>

        <?php
        $is_light = ($c['theme'] ?? 'dark') === 'light';
        $pv_bg    = $is_light ? '#FFFFFF' : 'rgba(9,9,11,0.96)';
        $pv_fg    = $is_light ? '#14181F' : '#F2F2F2';
        $pv_soft  = $is_light ? 'rgba(20,24,31,0.70)' : 'rgba(242,242,242,0.72)';
        $pv_faint = $is_light ? 'rgba(20,24,31,0.50)' : 'rgba(242,242,242,0.48)';
        $pv_btnbg = $is_light ? '#14181F' : 'rgba(255,255,255,0.15)';
        $pv_outer = $is_light ? '#E9EDF2' : 'linear-gradient(120deg,#1a1f29,#2b2233)';
        AdSpirit_Menu::card_open('Preview ao vivo', 'Reflete o tema escolhido (salve pra atualizar). A fonte real é a do site.');
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
