<?php
/**
 * AdSpirit Connector — Quem está medindo este site.
 *
 * A versão anterior perguntava "o nosso módulo está ligado?" e, quando não
 * estava, escrevia "ninguém está medindo". Errou feio num site que mede há
 * anos: o Analytics vinha pelo plugin oficial do Google, a gravação de sessão
 * pelo Hotjar, e o detector só sabia procurar Clarity e o formato antigo de ID
 * do GA4. Dizer "não configurado" sobre isso não é impreciso — é errado.
 *
 * Aqui a pergunta é "quem faz este trabalho neste site?", e a resposta sai de
 * um catálogo de fornecedores. Cada um se identifica de duas formas: pelo
 * rastro no HTML e pelo plugin ativo. Basta uma.
 *
 * Acrescentar fornecedor é acrescentar uma linha no catálogo. É de propósito:
 * o erro anterior veio de a lista estar escondida dentro da lógica.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Deteccao {

    /**
     * Catálogo. `trabalho` é o que a pessoa quer feito; `fornecedor` é quem faz.
     *
     * padroes  — regex no HTML da home; o primeiro grupo, quando existe, vira
     *            o identificador mostrado na tela.
     * plugin   — arquivo do plugin, quando dá pra reconhecer por ele.
     */
    public static function catalogo() {
        return array(

            // ---- Medição de anúncio ----
            array(
                'trabalho' => 'meta', 'fornecedor' => 'Pixel da Meta', 'marca' => 'meta',
                'padroes' => array(
                    "/fbq\\s*\\(\\s*['\\\"]init['\\\"]\\s*,\\s*['\\\"](\\d{8,20})['\\\"]/",
                    '/facebook\\.com\\/tr\\?id=(\\d{8,20})/',
                    '/connect\\.facebook\\.net\\/[^\\/]+\\/fbevents\\.js/',
                ),
                'plugins' => array('official-facebook-pixel/facebook-pixel.php', 'pixelyoursite/facebook-pixel-master.php'),
            ),
            array(
                'trabalho' => 'google-ads', 'fornecedor' => 'Google Ads', 'marca' => 'google',
                'padroes' => array('/\\b(AW-[0-9]{6,})\\b/'),
            ),

            // ---- Analytics ----
            array(
                'trabalho' => 'analytics', 'fornecedor' => 'Google Analytics', 'marca' => 'google',
                // GT- é o formato novo do Google Tag: ele carrega o G- em
                // runtime, então o G- costuma NÃO aparecer no HTML. Procurar
                // só por G- é o que fazia a gente não achar Analytics em site
                // que tem.
                'padroes' => array('/\\b(G-[A-Z0-9]{6,})\\b/', '/\\b(GT-[A-Z0-9]{6,})\\b/'),
                'plugins' => array('google-site-kit/google-site-kit.php'),
                'plugin_nome' => 'Google Analytics (via Site Kit)',
            ),

            // ---- Gravação de sessão ----
            array(
                'trabalho' => 'gravacao', 'fornecedor' => 'Hotjar', 'marca' => 'hotjar',
                'padroes' => array('/hjid\\s*:\\s*(\\d{4,})/', '/static\\.hotjar\\.com/'),
                'plugins' => array('hotjar/hotjar.php'),
            ),
            array(
                'trabalho' => 'gravacao', 'fornecedor' => 'Microsoft Clarity', 'marca' => 'clarity',
                'padroes' => array('/clarity\\.ms\\/tag\\/([a-z0-9]+)/'),
            ),
            array(
                'trabalho' => 'gravacao', 'fornecedor' => 'Smartlook', 'marca' => 'outro',
                'padroes' => array('/smartlook\\.com\\/recorder/'),
            ),
            array(
                'trabalho' => 'gravacao', 'fornecedor' => 'FullStory', 'marca' => 'outro',
                'padroes' => array('/fullstory\\.com\\/s\\/fs\\.js/'),
            ),
            array(
                'trabalho' => 'gravacao', 'fornecedor' => 'Lucky Orange', 'marca' => 'outro',
                'padroes' => array('/luckyorange\\.com/'),
            ),

            // ---- Gerenciador de tags ----
            array(
                'trabalho' => 'gerenciador', 'fornecedor' => 'Google Tag Manager', 'marca' => 'google',
                'padroes' => array('/\\b(GTM-[A-Z0-9]{4,})\\b/'),
                'plugins' => array('duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php'),
            ),

            // ---- O nosso ----
            array(
                'trabalho' => 'adspirit', 'fornecedor' => 'Pixel do AdSpirit', 'marca' => 'adspirit',
                'padroes' => array('/\\/pixel\\.js\\?t=([a-z0-9_]+)/'),
            ),
        );
    }

    /**
     * Varre o HTML e os plugins ativos. Devolve, por trabalho, a lista de quem
     * faz — com identificador e como foi encontrado.
     */
    public static function quem_mede($html) {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $por_trabalho = array();

        foreach (self::catalogo() as $c) {
            $achou = false; $id = ''; $como = '';

            foreach ((array) ($c['padroes'] ?? array()) as $re) {
                if (preg_match($re, (string) $html, $m)) {
                    $achou = true; $como = 'pagina';
                    if (isset($m[1])) { $id = $m[1]; }
                    break;
                }
            }
            if (!$achou) {
                foreach ((array) ($c['plugins'] ?? array()) as $arq) {
                    if (is_plugin_active($arq)) { $achou = true; $como = 'plugin'; break; }
                }
            }
            if (!$achou) continue;

            $por_trabalho[$c['trabalho']][] = array(
                'fornecedor' => ($como === 'plugin' && !empty($c['plugin_nome'])) ? $c['plugin_nome'] : $c['fornecedor'],
                'marca' => $c['marca'],
                'id' => $id,
                'como' => $como,
            );
        }
        return $por_trabalho;
    }
}
