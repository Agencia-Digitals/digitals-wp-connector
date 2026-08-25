<?php
/**
 * AdSpirit Connector — tags de navegador (pixel da Meta e GA4) no site.
 *
 * Última perna do fluxo: o time conecta as contas no AdSpirit e escolhe os
 * ativos da marca; o AdSpirit manda os identificadores; e aqui a tag entra
 * na página. Ninguém cola código em tema, functions.php ou plugin de
 * headers.
 *
 * O QUE FAZ E O QUE NÃO FAZ. Coloca a tag de NAVEGADOR — o fbq e o gtag,
 * que o visitante executa. O envio pelo SERVIDOR (CAPI da Meta, Measurement
 * Protocol do GA4) continua sendo outro caminho, que já existia e não muda.
 * Os dois juntos são o desejado: o navegador cobre o que o servidor não vê,
 * o servidor cobre o que bloqueador e iOS derrubam, e o mesmo id nos dois
 * lados faz a deduplicação acontecer.
 *
 * NUNCA INJETA POR CIMA DE QUEM JÁ ESTÁ LÁ. Antes de escrever qualquer
 * coisa, olha a última varredura do AdSpirit_Pixel_Conflito:
 *
 *   já tem a MESMA tag   → não injeta (seria evento dobrado)
 *   já tem OUTRA tag     → não injeta e não corrige (é decisão de gente)
 *   tem GTM na página    → não injeta (o contêiner pode ter a tag dentro,
 *                          e daqui não dá pra ver o que ele carrega)
 *   não tem nada         → injeta
 *
 * O silêncio é sempre o lado seguro: medir duas vezes estraga o dado e a
 * otimização da campanha, enquanto não medir é visível e corrigível.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Tags {

    const SETTING = 'tags_navegador_enabled';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Prioridade 6: logo depois do pixel do AdSpirit (que roda em 5),
        // pra manter a ordem previsível no <head>.
        add_action('wp_head', AdSpirit_Safe_Hook::action(array($this, 'injetar'), 'tags_navegador'), 6);
    }

    public static function is_enabled() {
        $c = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();
        // Ligado por padrão: é o "instala e conecta" que o plugin promete.
        // A trava real não é este toggle, e sim a varredura — mesmo ligado,
        // não injeta onde já existe tag.
        return ($c[self::SETTING] ?? '1') === '1';
    }

    /**
     * O que a última varredura viu na página.
     *
     * Vazio (nunca varreu) NÃO é tratado como "não tem nada": sem saber, o
     * risco de dobrar existe, e dobrar é o dano caro. Espera a varredura.
     *
     * @return array|null null quando não há varredura utilizável.
     */
    private static function pagina() {
        if (!class_exists('AdSpirit_Pixel_Conflito')) return null;
        $r = AdSpirit_Pixel_Conflito::relatorio();
        if (!is_array($r) || empty($r)) return null;
        return array(
            'meta' => isset($r['meta_na_pagina']) && is_array($r['meta_na_pagina']) ? $r['meta_na_pagina'] : array(),
            'ga4'  => isset($r['ga4_na_pagina']) && is_array($r['ga4_na_pagina']) ? $r['ga4_na_pagina'] : array(),
            'gtm'  => trim((string) ($r['gtm'] ?? '')),
        );
    }

    /**
     * Decide, por tag, se injeta — e guarda o porquê pra tela explicar.
     *
     * @return array{injeta:bool, motivo:string}
     */
    public static function decidir($tipo, $id) {
        $id = trim((string) $id);
        if ($id === '') {
            return array('injeta' => false, 'motivo' => 'Nenhum identificador escolhido no AdSpirit para esta marca.');
        }
        if (!self::is_enabled()) {
            return array('injeta' => false, 'motivo' => 'Desligado nas configurações deste site.');
        }
        $p = self::pagina();
        if ($p === null) {
            return array('injeta' => false, 'motivo' => 'Esperando a primeira varredura da página — sem saber o que já existe, injetar arriscaria contar em dobro.');
        }
        if ($p['gtm'] !== '') {
            return array('injeta' => false, 'motivo' => 'A página usa o Gerenciador de Tags (' . $p['gtm'] . '), e daqui não dá pra ver o que ele carrega. A tag pode já estar lá dentro.');
        }
        $existentes = $tipo === 'meta' ? $p['meta'] : $p['ga4'];
        foreach ($existentes as $ja) {
            if (strcasecmp(trim((string) $ja), $id) === 0) {
                return array('injeta' => false, 'motivo' => 'Já está na página — o site não precisa de duas.');
            }
        }
        if (!empty($existentes)) {
            return array('injeta' => false, 'motivo' => 'A página já tem outra tag deste tipo (' . implode(', ', $existentes) . '). Duas medindo ao mesmo tempo dobram a conversão; resolva qual fica antes de ligar esta.');
        }
        return array('injeta' => true, 'motivo' => 'Não havia nada na página.');
    }

    /** Ids escolhidos no AdSpirit e já sincronizados pra cá. */
    private static function ids() {
        $capi = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_capi_meta() : array();
        $ga4  = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_ga4() : array();
        return array(
            'meta' => trim((string) ($capi['pixel_id'] ?? '')),
            'ga4'  => trim((string) ($ga4['measurement_id'] ?? '')),
        );
    }

    public function injetar() {
        if (is_admin()) return;
        $ids = self::ids();

        $meta = self::decidir('meta', $ids['meta']);
        $ga4  = self::decidir('ga4', $ids['ga4']);
        if (!$meta['injeta'] && !$ga4['injeta']) return;

        echo "\n<!-- AdSpirit Connector: tags de navegador -->\n";

        if ($meta['injeta']) {
            $pixel = esc_js($ids['meta']);
            ?>
<script>
!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?
n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;
n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;
t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,
document,'script','https://connect.facebook.net/en_US/fbevents.js');
fbq('init','<?php echo $pixel; ?>');fbq('track','PageView');
</script>
<noscript><img height="1" width="1" style="display:none" alt=""
src="https://www.facebook.com/tr?id=<?php echo esc_attr($ids['meta']); ?>&ev=PageView&noscript=1"></noscript>
            <?php
        }

        if ($ga4['injeta']) {
            $mid = esc_attr($ids['ga4']);
            ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?php echo $mid; ?>"></script>
<script>
window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}
gtag('js',new Date());gtag('config','<?php echo esc_js($ids['ga4']); ?>');
</script>
            <?php
        }
        echo "\n";
    }

    /** Resumo pra tela: o que está injetado, o que não, e por quê. */
    public static function estado() {
        $ids = self::ids();
        return array(
            'meta' => array_merge(array('id' => $ids['meta']), self::decidir('meta', $ids['meta'])),
            'ga4'  => array_merge(array('id' => $ids['ga4']), self::decidir('ga4', $ids['ga4'])),
        );
    }
}
