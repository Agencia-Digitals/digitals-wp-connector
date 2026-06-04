<?php
/**
 * AdSpirit Connector — LGPD/GDPR consent popup.
 *
 * Banner minimalista com design AdSpirit injetado no wp_footer.
 * 3 opções: aceitar tudo, só essencial, personalizar (modal granular).
 * Cookie `adspirit_consent` salva escolha por 365 dias.
 *
 * Pixel + CAPI + GA4 + telemetria respeitam o consent:
 *   - "essential": só funções básicas (form submit), zero tracking
 *   - "accept_all": tudo
 *   - "custom": granular por categoria (tracking, analytics, marketing)
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
        add_filter('adspirit_connector_tabs', array($this, 'add_tab'));
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
            'title' => 'Sua privacidade',
            'message' => 'Usamos cookies pra entender como você usa o site, melhorar a experiência e medir resultados de marketing. Você pode aceitar tudo, manter apenas o essencial ou personalizar suas preferências.',
            'accept_label' => 'Aceitar tudo',
            'essential_label' => 'Apenas essenciais',
            'custom_label' => 'Personalizar',
            'position' => 'bottom-right', // bottom-right | bottom-center | bottom-banner
            'privacy_url' => '',
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

    public function inject_popup() {
        if (is_admin()) return;
        $c = self::get_settings();
        if ($c['enabled'] !== '1') return;
        // Não mostra se já tem decisão
        if (!empty($_COOKIE[self::COOKIE])) return;

        $pos_css = array(
            'bottom-right' => 'right: 24px; bottom: 24px; max-width: 380px;',
            'bottom-center' => 'left: 50%; transform: translateX(-50%); bottom: 24px; max-width: 480px;',
            'bottom-banner' => 'left: 0; right: 0; bottom: 0; max-width: none; border-radius: 0; border-bottom: 0;',
        );
        $style = isset($pos_css[$c['position']]) ? $pos_css[$c['position']] : $pos_css['bottom-right'];

        ?>
        <div id="adspirit-lgpd" style="position:fixed; z-index:99999; <?php echo esc_attr($style); ?> background:#0F1419; color:#fff; border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:20px 22px; box-shadow:0 12px 40px rgba(0,0,0,0.3); font-family:-apple-system,BlinkMacSystemFont,'SF Pro Text','Segoe UI',sans-serif; font-size:13px; line-height:1.55;">
            <div style="font-size:10px; font-weight:700; letter-spacing:0.22em; text-transform:uppercase; color:#00B7B7; margin-bottom:8px;"><?php echo esc_html($c['title']); ?></div>
            <p style="margin:0 0 14px; color:#C8D2DA; font-size:12.5px;"><?php echo esc_html($c['message']); ?></p>
            <?php if (!empty($c['privacy_url'])): ?>
                <p style="margin:0 0 14px;"><a href="<?php echo esc_url($c['privacy_url']); ?>" target="_blank" rel="noopener" style="color:#00B7B7; text-decoration:underline; text-underline-offset:2px; font-size:11.5px;">Política de privacidade</a></p>
            <?php endif; ?>
            <div style="display:flex; gap:8px; flex-wrap:wrap;">
                <button type="button" onclick="adspiritLgpdChoose('accept_all')" style="background:#00B7B7; color:#fff; border:0; padding:9px 14px; border-radius:6px; font-size:12.5px; font-weight:600; cursor:pointer; font-family:inherit;"><?php echo esc_html($c['accept_label']); ?></button>
                <button type="button" onclick="adspiritLgpdChoose('essential')" style="background:transparent; color:#fff; border:1px solid rgba(255,255,255,0.15); padding:9px 14px; border-radius:6px; font-size:12.5px; font-weight:500; cursor:pointer; font-family:inherit;"><?php echo esc_html($c['essential_label']); ?></button>
                <button type="button" onclick="document.getElementById('adspirit-lgpd-custom').style.display='block';" style="background:transparent; color:#8A95A0; border:0; padding:9px 4px; font-size:11.5px; cursor:pointer; font-family:inherit;"><?php echo esc_html($c['custom_label']); ?></button>
            </div>
            <div id="adspirit-lgpd-custom" style="display:none; margin-top:14px; padding-top:14px; border-top:1px solid rgba(255,255,255,0.08);">
                <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#C8D2DA; margin-bottom:8px;"><input type="checkbox" id="adspirit-cat-tracking" checked accent-color="#00B7B7"> Tracking (pixel, atribuição, telemetria)</label>
                <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#C8D2DA; margin-bottom:8px;"><input type="checkbox" id="adspirit-cat-analytics" checked accent-color="#00B7B7"> Analytics (GA4)</label>
                <label style="display:flex; align-items:center; gap:8px; font-size:12px; color:#C8D2DA; margin-bottom:12px;"><input type="checkbox" id="adspirit-cat-marketing" checked accent-color="#00B7B7"> Marketing (Meta CAPI)</label>
                <button type="button" onclick="adspiritLgpdSaveCustom()" style="background:#00B7B7; color:#fff; border:0; padding:8px 14px; border-radius:6px; font-size:12.5px; font-weight:600; cursor:pointer; font-family:inherit;">Salvar preferências</button>
            </div>
        </div>
        <script>
        function adspiritLgpdChoose(choice) {
            var d = new Date(); d.setTime(d.getTime() + 365*86400000);
            document.cookie = '<?php echo esc_js(self::COOKIE); ?>=' + choice + ';expires=' + d.toUTCString() + ';path=/;samesite=lax';
            var el = document.getElementById('adspirit-lgpd');
            if (el) el.remove();
            if (choice === 'accept_all') setTimeout(function() { location.reload(); }, 200);
        }
        function adspiritLgpdSaveCustom() {
            var cats = [];
            if (document.getElementById('adspirit-cat-tracking').checked) cats.push('tracking');
            if (document.getElementById('adspirit-cat-analytics').checked) cats.push('analytics');
            if (document.getElementById('adspirit-cat-marketing').checked) cats.push('marketing');
            adspiritLgpdChoose('custom:' + cats.join(','));
        }
        </script>
        <?php
    }

    public function render_tab() {
        $c = self::get_settings();
        $status = $c['enabled'] === '1' ? '<span class="as-badge ok">Ativo</span>' : '<span class="as-badge muted">Desligado</span>';
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Consentimento</span>Popup LGPD/GDPR minimalista</h2>
        <p class="as-section-help">Banner pequeno injetado no rodapé do site, com design AdSpirit. 3 opções de consentimento + modal granular. Cookie <code><?php echo esc_html(self::COOKIE); ?></code> guarda a escolha por 365 dias.</p>

        <?php AdSpirit_Menu::card_open('Configuração', 'Edite textos, posição e link da política de privacidade', $status); ?>
        <?php AdSpirit_Menu::form_open('lgpd'); ?>
        <table class="form-table">
            <tr>
                <th>Status</th>
                <td><label><input type="checkbox" name="enabled" value="1" <?php checked($c['enabled'], '1'); ?>> Mostrar popup pra visitantes sem consent</label></td>
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
                <th><label for="lgpd_accept">Label "aceitar tudo"</label></th>
                <td><input type="text" id="lgpd_accept" name="accept_label" value="<?php echo esc_attr($c['accept_label']); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><label for="lgpd_essential">Label "essenciais"</label></th>
                <td><input type="text" id="lgpd_essential" name="essential_label" value="<?php echo esc_attr($c['essential_label']); ?>" class="regular-text"></td>
            </tr>
            <tr>
                <th><label for="lgpd_custom">Label "personalizar"</label></th>
                <td><input type="text" id="lgpd_custom" name="custom_label" value="<?php echo esc_attr($c['custom_label']); ?>" class="regular-text"></td>
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
        </table>
        <?php AdSpirit_Menu::form_close('Salvar consentimento'); ?>
        <?php AdSpirit_Menu::card_close(); ?>

        <?php AdSpirit_Menu::card_open('Preview ao vivo', 'Como aparece no site (cores e tipografia idênticas ao popup real)'); ?>
        <div style="background:#F8FAFC; padding:30px; border-radius:8px;">
            <div style="background:#0F1419; color:#fff; border:1px solid rgba(255,255,255,0.08); border-radius:12px; padding:20px 22px; max-width: 380px; font-family:-apple-system, BlinkMacSystemFont, 'SF Pro Text', sans-serif; font-size:13px;">
                <div style="font-size:10px; font-weight:700; letter-spacing:0.22em; text-transform:uppercase; color:#00B7B7; margin-bottom:8px;"><?php echo esc_html($c['title']); ?></div>
                <p style="margin:0 0 14px; color:#C8D2DA; font-size:12.5px;"><?php echo esc_html($c['message']); ?></p>
                <div style="display:flex; gap:8px; flex-wrap:wrap;">
                    <span style="background:#00B7B7; color:#fff; padding:9px 14px; border-radius:6px; font-size:12.5px; font-weight:600;"><?php echo esc_html($c['accept_label']); ?></span>
                    <span style="background:transparent; color:#fff; border:1px solid rgba(255,255,255,0.15); padding:8px 13px; border-radius:6px; font-size:12.5px; font-weight:500;"><?php echo esc_html($c['essential_label']); ?></span>
                </div>
            </div>
        </div>
        <?php AdSpirit_Menu::card_close(); ?>
        <?php
    }

    public function handle_save($post) {
        $patch = array();
        $patch['enabled'] = !empty($post['enabled']) ? '1' : '0';
        foreach (array('title','message','accept_label','essential_label','custom_label','position','privacy_url') as $k) {
            if (isset($post[$k])) {
                $patch[$k] = sanitize_text_field((string) $post[$k]);
            }
        }
        if (isset($post['message'])) {
            $patch['message'] = sanitize_textarea_field((string) $post['message']);
        }
        $merged = wp_parse_args($patch, self::get_settings());
        update_option(self::OPTION_KEY, $merged, false);
        add_settings_error(self::OPTION_KEY, 'saved', 'Configurações de consentimento salvas.', 'updated');
    }
}
