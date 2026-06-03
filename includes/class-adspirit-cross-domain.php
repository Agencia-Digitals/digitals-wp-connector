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
            'wp_footer',
            AdSpirit_Safe_Hook::action(array($this, 'inject_script'), 'cross_domain_inject'),
            99
        );
    }

    public function inject_script() {
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

        $domains_json = wp_json_encode(array_values($domains));
        ?>
        <script>
        (function() {
            try {
                var DOMAINS = <?php echo $domains_json; ?>;
                if (!DOMAINS.length) return;

                function getCookie(name) {
                    var m = document.cookie.match('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\\/+^])/g, '\\$1') + '=([^;]*)');
                    return m ? decodeURIComponent(m[1]) : null;
                }
                function setCookie(name, value, days) {
                    var d = new Date(); d.setTime(d.getTime() + days * 86400000);
                    document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/;samesite=lax';
                }
                function uuid() {
                    return ([1e7]+-1e3+-4e3+-8e3+-1e11).replace(/[018]/g, function(c) {
                        return (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c/4)).toString(16);
                    });
                }
                var vid = getCookie('adspirit_vid');
                if (!vid) {
                    vid = uuid();
                    setCookie('adspirit_vid', vid, 90);
                }

                function decorate(a) {
                    if (!a.href || a.dataset.dosDecorated === '1') return;
                    try {
                        var u = new URL(a.href);
                        if (DOMAINS.indexOf(u.hostname.toLowerCase()) !== -1) {
                            u.searchParams.set('dos_vid', vid);
                            a.href = u.toString();
                            a.dataset.dosDecorated = '1';
                        }
                    } catch (e) { /* ignore invalid URLs */ }
                }

                document.querySelectorAll('a[href]').forEach(decorate);

                // Watch dinamicamente-injetados
                if (typeof MutationObserver !== 'undefined') {
                    var mo = new MutationObserver(function(mutations) {
                        mutations.forEach(function(m) {
                            m.addedNodes.forEach(function(node) {
                                if (!node.querySelectorAll) return;
                                if (node.tagName === 'A') decorate(node);
                                node.querySelectorAll && node.querySelectorAll('a[href]').forEach(decorate);
                            });
                        });
                    });
                    mo.observe(document.body, { childList: true, subtree: true });
                }
            } catch (e) {
                /* silenciado */
            }
        })();
        </script>
        <?php
    }

    public function render_tab() {
        $c = AdSpirit_Settings::get_cross_domain();
        AdSpirit_Menu::form_open('cross-domain');
        ?>
        <h2>Cross-domain link decoration</h2>
        <p class="description">
            Quando o cliente tem múltiplos domínios próprios (landing.com → checkout.io), o pixel perde o visitor ao navegar entre eles. Esta camada decora links com <code>?dos_vid=&lt;id&gt;</code> pra preservar continuidade.
        </p>
        <p class="description">
            <strong>Sincroniza com</strong> a config <code>linked_domains</code> em <code>tracking_install</code> do CRM — ideal manter os mesmos domínios nos dois lados.
        </p>

        <table class="form-table">
            <tr>
                <th>Status</th>
                <td>
                    <label>
                        <input type="checkbox" name="enabled" value="1" <?php checked($c['enabled'], '1'); ?>>
                        Ativar decoration
                    </label>
                </td>
            </tr>
            <tr>
                <th><label for="domains">Domínios afiliados</label></th>
                <td>
                    <textarea id="domains" name="domains" rows="6" class="large-text code" placeholder="checkout.cliente.com&#10;app.cliente.com.br"><?php echo esc_textarea($c['domains']); ?></textarea>
                    <p class="description">Um hostname por linha. Sem <code>http://</code> nem path. Apenas domínios <strong>próprios</strong> do cliente — não inclua redes sociais ou destinos de terceiros.</p>
                </td>
            </tr>
        </table>
        <?php
        AdSpirit_Menu::form_close('Salvar cross-domain');
    }

    public function handle_save($post) {
        $patch = array();
        $patch['enabled'] = !empty($post['enabled']) ? '1' : '0';
        $patch['domains'] = sanitize_textarea_field((string) ($post['domains'] ?? ''));
        AdSpirit_Settings::update_cross_domain($patch);
        add_settings_error(AdSpirit_Settings::OPTION_CROSS_DOMAIN, 'saved', 'Cross-domain salvo.', 'updated');
    }
}
