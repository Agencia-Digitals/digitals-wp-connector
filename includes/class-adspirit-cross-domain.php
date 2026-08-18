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
