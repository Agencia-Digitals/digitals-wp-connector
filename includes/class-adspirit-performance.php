<?php
/**
 * AdSpirit Connector — desempenho da página, sem tocar no conteúdo salvo.
 *
 * POR QUE ESTE MÓDULO EXISTE, E POR QUE ASSIM
 *
 * A home da Digitals tirou 20/100 no PageSpeed em celular. Duas métricas
 * respondiam por metade da nota, e as duas nasciam do mesmo herói:
 *
 *   · LCP 15,2 s — o maior elemento da tela É o vídeo de fundo. A página só
 *     "termina de pintar" quando o arquivo inteiro chega. Ele é autoplay e
 *     sem `preload` declarado, então o navegador baixa tudo competindo com o
 *     que precisa aparecer primeiro.
 *   · CLS 1,001 — um único salto: um <div> decorativo de tela cheia
 *     (`width:100vw; height:100vh`) nasce com altura 0 e depois ocupa a tela.
 *     Elemento que cresce do nada pra tela inteira dá CLS 1,0 sozinho.
 *
 * A correção óbvia seria editar a página no Oxygen. Não é o que fazemos aqui,
 * e a razão é cicatriz: em 30/08 uma edição do conteúdo do construtor por
 * fora do editor derrubou a seção do herói em produção, com campanha no ar.
 * O Oxygen clássico assina cada seção; conteúdo alterado por fora quebra a
 * assinatura e a seção some.
 *
 * Então este módulo trabalha na SAÍDA: intercepta o HTML já montado e ajusta
 * atributos. O conteúdo salvo continua intocado, o editor continua abrindo
 * igual, e desligar é remover o filtro — não há o que "desfazer" no banco.
 *
 * O QUE ELE NÃO FAZ, DE PROPÓSITO
 *
 * Não adia script de medição. Perder dado de campanha pra ganhar ponto numa
 * auditoria é troca ruim, e a decisão é do Pedro (31/08). Não mexe em
 * animação: o blur de entrada do logotipo é design, não defeito.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Performance {

    const OPTION = 'adspirit_connector_performance';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Só no front, e nunca dentro do editor do Oxygen — lá o HTML é
        // matéria-prima do builder, não página pra visitante.
        add_action('template_redirect',
            AdSpirit_Safe_Hook::action(array($this, 'ligar_buffer'), 'perf_buffer'));
    }

    public static function config() {
        $padrao = array(
            // Cada peça liga sozinha. Quem quiser desligar uma sem perder a
            // outra consegue — e um efeito colateral inesperado não obriga a
            // derrubar o módulo inteiro.
            'adiar_video' => '1',
            'travar_hero' => '1',
        );
        $c = get_option(self::OPTION, array());
        return array_merge($padrao, is_array($c) ? $c : array());
    }

    public function ligar_buffer() {
        if (is_admin() || is_feed() || is_embed()) return;
        // Editor do Oxygen e pré-visualizações ficam de fora.
        if (isset($_GET['ct_builder']) || isset($_GET['oxygen_iframe'])) return;
        if (defined('SHOW_CT_BUILDER') && SHOW_CT_BUILDER) return;
        if (defined('REST_REQUEST') && REST_REQUEST) return;

        $cfg = self::config();
        if ($cfg['adiar_video'] !== '1' && $cfg['travar_hero'] !== '1') return;

        ob_start(array($this, 'processar'));
    }

    /**
     * Recebe o HTML pronto e devolve ajustado.
     *
     * Fail-soft de verdade: qualquer exceção devolve o HTML ORIGINAL. Uma
     * otimização não pode ser motivo pra página não carregar — é o oposto do
     * que ela existe pra fazer.
     */
    public function processar($html) {
        try {
            if (!is_string($html) || strlen($html) < 500) return $html;
            if (stripos($html, '<html') === false) return $html;

            $cfg = self::config();
            if ($cfg['adiar_video'] === '1') $html = $this->adiar_video($html);
            if ($cfg['travar_hero'] === '1') $html = $this->travar_hero($html);
            return $html;
        } catch (\Throwable $e) {
            if (class_exists('AdSpirit_Crash_Tracker')) {
                AdSpirit_Crash_Tracker::record('performance', $e->getMessage(), $e->getFile(), $e->getLine());
            }
            return $html;
        }
    }

    /**
     * Faz o vídeo de fundo parar de disputar a abertura da página.
     *
     * Três mudanças no elemento, todas reversíveis por serem só atributos:
     *
     *   preload="none"  — o navegador não baixa nada até decidir tocar.
     *   poster=...      — dá o que mostrar enquanto isso. Sem poster, o
     *                     espaço fica preto e o LCP continua sendo o vídeo.
     *   loading do src  — o `src` sai do <source> e vira `data-src`, e um
     *                     trecho mínimo de JS devolve depois que a página
     *                     carregou. É isso que tira o vídeo do caminho
     *                     crítico de verdade; só `preload="none"` não basta
     *                     com `autoplay`, porque o autoplay força o download.
     *
     * O poster sai de `adspirit_performance_poster` (filtro) ou da opção
     * `poster_url`. Sem poster configurado, ainda vale a pena: o vídeo deixa
     * de bloquear, e o fundo da seção aparece no lugar.
     */
    private function adiar_video($html) {
        if (stripos($html, '<video') === false) return $html;

        $poster = apply_filters('adspirit_performance_poster', (string) (self::config()['poster_url'] ?? ''));

        $novo = preg_replace_callback(
            '#<video\b([^>]*)>(.*?)</video>#is',
            function ($m) use ($poster) {
                $attrs = $m[1];
                $miolo = $m[2];

                // Só mexe em vídeo de fundo (autoplay). Vídeo que a pessoa
                // dá play é outro caso: adiar ali não ganha nada e pode
                // atrapalhar quem clica rápido.
                if (stripos($attrs, 'autoplay') === false) return $m[0];
                // Idempotente: se já passou por aqui, não mexe de novo.
                if (stripos($attrs, 'data-adspirit-defer') !== false) return $m[0];

                $attrs = preg_replace('/\s+preload\s*=\s*("[^"]*"|\'[^\']*\'|\S+)/i', '', $attrs);
                $attrs .= ' preload="none" data-adspirit-defer="1"';
                if ($poster !== '' && stripos($attrs, 'poster=') === false) {
                    $attrs .= ' poster="' . esc_url($poster) . '"';
                }

                // src → data-src, tanto no <source> quanto no próprio <video>.
                $miolo = preg_replace('/<source\b([^>]*?)\ssrc=/i', '<source$1 data-src=', $miolo);
                $attrs = preg_replace('/\ssrc=/i', ' data-src=', $attrs);

                return '<video' . $attrs . '>' . $miolo . '</video>';
            },
            $html
        );
        if (!is_string($novo)) return $html;
        if ($novo === $html) return $html;

        return $this->injetar_script($novo);
    }

    /**
     * Devolve o src depois que a página carregou. Fica no rodapé, sem
     * dependência de biblioteca, e não faz nada se não houver vídeo adiado.
     */
    private function injetar_script($html) {
        $js = <<<'JS'
<script>(function(){function g(){document.querySelectorAll('video[data-adspirit-defer]').forEach(function(v){
var s=v.getAttribute('data-src');if(s){v.setAttribute('src',s);v.removeAttribute('data-src');}
v.querySelectorAll('source[data-src]').forEach(function(o){o.setAttribute('src',o.getAttribute('data-src'));o.removeAttribute('data-src');});
v.removeAttribute('data-adspirit-defer');try{v.load();var p=v.play();if(p&&p.catch)p.catch(function(){});}catch(e){}});}
if(document.readyState==='complete'){setTimeout(g,1);}else{window.addEventListener('load',function(){setTimeout(g,1);});}})();</script>
JS;
        $pos = strripos($html, '</body>');
        if ($pos === false) return $html . $js;
        return substr($html, 0, $pos) . $js . substr($html, $pos);
    }

    /**
     * Impede que a camada decorativa do herói nasça sem altura.
     *
     * O `#background-div` é um <div> vazio de tela cheia, só pra pôr um
     * pattern de fundo. A regra que dá altura a ele vem no CSS por página;
     * até ela valer, o elemento mede zero — e o pulo de 0 pra tela inteira é
     * o CLS de 1,0 inteiro.
     *
     * A correção é declarar a mesma geometria ANTES, em CSS embutido no
     * <head>: não muda o visual (é exatamente o que a folha já diz), só
     * garante que valha desde o primeiro quadro. Deliberadamente NÃO toca em
     * logotipo nem em animação de entrada.
     */
    private function travar_hero($html) {
        if (strpos($html, 'background-div') === false) return $html;
        if (strpos($html, 'adspirit-perf-cls') !== false) return $html;

        $css = '<style id="adspirit-perf-cls">'
             . '#background-div{width:100vw;height:100vh;position:absolute;top:0;left:0}'
             . '</style>';

        $pos = stripos($html, '</head>');
        if ($pos === false) return $html;
        return substr($html, 0, $pos) . $css . substr($html, $pos);
    }
}
