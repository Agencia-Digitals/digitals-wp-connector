<?php
/**
 * AdSpirit Connector — Cross-domain link decoration.
 *
 * Quando o cliente tem múltiplos domínios próprios (landing.com → checkout.io,
 * lp.x.com → app.y.com.br), o pixel perde continuidade do visitor ao
 * navegar entre eles. Solução: decorar links de saída com `?dos_vid=<vid>`,
 * que o pixel do destino lê e adota como mesmo visitor.
 *
 * Setting: lista de hostnames "afiliados". JS escaneia todos os <a> ao
 * carregar a página + watch new ones (MutationObserver) e adiciona o
 * param quando href.hostname está na lista.
 *
 * Visitor ID: gerado/lido do cookie `adspirit_vid` (sincroniza com o
 * mesmo cookie do pixel.js).
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Cross_Domain {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action(
            'adspirit_connector_render_tab_cross-domain',
            AdSpirit_Safe_Hook::action(array($this, 'render_tab'), 'cross_domain_tab')
        );
        add_action(
            'adspirit_connector_save_cross-domain',
            AdSpirit_Safe_Hook::action(array($this, 'handle_save'), 'cross_domain_save')
        );
        add_action(
            'wp_enqueue_scripts',
            AdSpirit_Safe_Hook::action(array($this, 'enqueue_assets'), 'cross_domain_enqueue')
        );
    }

    public function enqueue_assets() {
        $cfg = AdSpirit_Settings::get_cross_domain();
        if ($cfg['enabled'] !== '1') return;
        $raw = (string) ($cfg['domains'] ?? '');
        $domains = array_filter(array_map(function($s) {
            $s = trim(strtolower((string) $s));
            $s = preg_replace('#^https?://#', '', $s);
            $s = preg_replace('#/.*$#', '', $s);
            $s = preg_replace('#^\.#', '', $s);
            return $s;
        }, preg_split('/\r?\n/', $raw)));
        if (empty($domains)) return;

        $version = defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : '2.30.0';
        wp_enqueue_script(
            'adspirit-cross-domain',
            ADSPIRIT_CONNECTOR_URL . 'assets/cross-domain.js',
            array(),
            $version,
            true
        );
        wp_add_inline_script(
            'adspirit-cross-domain',
            'window.__adspiritXDomainCfg = ' . wp_json_encode(array('domains' => array_values($domains))) . ';',
            'before'
        );
    }

    public function render_tab() {
        $c = AdSpirit_Settings::get_cross_domain();
        $status_badge = $c['enabled'] === '1' ? '<span class="as-badge ok">Ativo</span>' : '<span class="as-badge muted">Desligado</span>';
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Cross-domain</span>Rastreio entre sites</h2>
        <p class="as-section-help">Mantém a jornada do visitante quando ele sai de um site seu pra outro (ex.: da landing page pro checkout).</p>

        <?php AdSpirit_Menu::card_open('Configuração', 'Liste só os domínios que são seus', $status_badge); ?>
        <?php AdSpirit_Menu::form_open('cross-domain'); ?>

        <div class="as-toggle">
            <input type="checkbox" id="xd_enabled" name="enabled" value="1" <?php checked($c['enabled'], '1'); ?>>
            <label class="t" for="xd_enabled">Rastreio entre sites ligado<small>Os links pros domínios da lista abaixo passam a carregar o código do visitante.</small></label>
        </div>

        <div class="as-field">
            <label class="as-field-label" for="domains">Seus outros domínios</label>
            <textarea id="domains" name="domains" rows="6" class="large-text code" placeholder="checkout.cliente.com&#10;app.cliente.com.br"><?php echo esc_textarea($c['domains']); ?></textarea>
            <p class="description">Um domínio por linha, sem <code>http://</code> nem barra. Só domínios seus — nunca redes sociais ou sites de terceiros.</p>
        </div>

        <details class="as-help">
            <summary>Como funciona</summary>
            <ul>
                <li>Sem isso, o visitante que troca de domínio vira "visitante novo" e a jornada se perde.</li>
                <li>Os links de saída pros domínios da lista ganham automaticamente <code>?dos_vid=&lt;id&gt;</code>; o pixel do destino lê e continua a mesma jornada.</li>
                <li>Mantenha a mesma lista no AdSpirit (domínios vinculados do rastreio) pros dois lados baterem.</li>
            </ul>
        </details>

        <?php AdSpirit_Menu::form_close('Salvar cross-domain'); ?>
        <?php AdSpirit_Menu::card_close(); ?>
        <?php
    }

    public function handle_save($post) {
        $patch = array();
        $patch['enabled'] = !empty($post['enabled']) ? '1' : '0';
        $patch['domains'] = sanitize_textarea_field((string) ($post['domains'] ?? ''));
        AdSpirit_Settings::update_cross_domain($patch);
        add_settings_error(AdSpirit_Settings::OPTION_CROSS_DOMAIN, 'saved', 'Cross-domain salvo.', 'updated');
    }
}
