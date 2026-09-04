<?php
/**
 * AdSpirit Connector — desempenho da página, sem tocar no conteúdo salvo.
 *
 * O módulo trabalha na SAÍDA: intercepta o HTML já montado e ajusta o que dá
 * pra ajustar sem editar o construtor. A razão é cicatriz: em 30/08 uma
 * edição do conteúdo do Oxygen por fora do editor derrubou a seção do herói
 * em produção, com campanha no ar — o Oxygen clássico assina cada seção e
 * conteúdo alterado por fora quebra a assinatura.
 *
 * O QUE ELE FAZ HOJE
 *
 *   · trava a geometria do herói. O `#background-div` é um <div> vazio de
 *     tela cheia que nasce com altura 0 e depois ocupa a tela — um salto de
 *     0 pra tela inteira dá CLS 1,0 sozinho. A correção declara a MESMA
 *     geometria antes, em CSS no <head>: não muda o visual, só garante que
 *     valha desde o primeiro quadro.
 *   · some com shortcode órfão de plugin desativado.
 *   · dispensa biblioteca de carrossel em página que não tem carrossel.
 *
 * O QUE SAIU, E POR QUE (2.79.0)
 *
 * O adiamento do vídeo de fundo. Ele tirava o vídeo do caminho crítico e o
 * LCP medido caía pra menos de 1s — mas a nota do PageSpeed não se moveu, e
 * a entrada do herói passou a ser: tela vazia, imagem, e o vídeo entrando
 * depois. Isso lê como falha, não como cinema. Trocar a impressão da
 * primeira tela por uma métrica que nem melhorou é troca ruim, e a decisão é
 * do Pedro (03/09).
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
        // Depois de uma atualização, a cópia cacheada ainda descreve o
        // comportamento ANTIGO. Enquanto ela viver, quem visita continua
        // vendo o que acabou de ser desfeito — foi o caso do adiamento do
        // vídeo, removido na 2.79.0. Uma limpeza única por versão resolve.
        add_action('wp_loaded',
            AdSpirit_Safe_Hook::action(array($this, 'limpar_cache_apos_atualizacao'), 'perf_purge'));
    }

    public static function config() {
        $padrao = array(
            // Cada peça liga sozinha. Quem quiser desligar uma sem perder a
            // outra consegue — e um efeito colateral inesperado não obriga a
            // derrubar o módulo inteiro.
            'travar_hero' => '1',
            'limpar_shortcode_orfao' => '1',
            'dispensar_carrossel' => '1',
        );
        $c = get_option(self::OPTION, array());
        return array_merge($padrao, is_array($c) ? $c : array());
    }

    /**
     * Limpa o cache de página uma vez a cada versão nova do plugin.
     *
     * Só chama a purga do próprio LiteSpeed. Deliberadamente NÃO apaga
     * arquivo nenhum: em 30/08 uma limpeza que removia o CSS por página do
     * Oxygen clássico derrubou o layout do site em produção, porque o
     * Oxygen clássico só regenera esse arquivo quando o construtor salva.
     */
    public function limpar_cache_apos_atualizacao() {
        $atual = defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : '';
        if ($atual === '') return;
        $cfg = self::config();
        if (($cfg['versao_limpa'] ?? '') === $atual) return;
        update_option(self::OPTION, array_merge($cfg, array('versao_limpa' => $atual)), false);
        if (has_action('litespeed_purge_all')) do_action('litespeed_purge_all');
    }

    public function ligar_buffer() {
        if (is_admin() || is_feed() || is_embed()) return;
        // Editor do Oxygen e pré-visualizações ficam de fora.
        if (isset($_GET['ct_builder']) || isset($_GET['oxygen_iframe'])) return;
        if (defined('SHOW_CT_BUILDER') && SHOW_CT_BUILDER) return;
        if (defined('REST_REQUEST') && REST_REQUEST) return;

        $cfg = self::config();
        $alguma = false;
        foreach (array('travar_hero', 'limpar_shortcode_orfao', 'dispensar_carrossel') as $peca) {
            if (($cfg[$peca] ?? '0') === '1') { $alguma = true; break; }
        }
        if (!$alguma) return;

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
            if ($cfg['travar_hero'] === '1') $html = $this->travar_hero($html);
            if ($cfg['limpar_shortcode_orfao'] === '1') $html = $this->limpar_shortcode_orfao($html);
            if (($cfg['dispensar_carrossel'] ?? '1') === '1') $html = $this->dispensar_carrossel($html);
            return $html;
        } catch (\Throwable $e) {
            if (class_exists('AdSpirit_Crash_Tracker')) {
                AdSpirit_Crash_Tracker::record('performance', $e->getMessage(), $e->getFile(), $e->getLine());
            }
            return $html;
        }
    }

    /**
     * Some com shortcode que ficou órfão quando um plugin foi desativado.
     *
     * Shortcode sem plugin que o atenda não some: o WordPress imprime o
     * texto cru. Na desativação do Contact Form 7 (31/08) a home passou a
     * exibir `[contact-form-7 id="..." title="Leads principal"]` como frase,
     * logo abaixo da chamada do formulário — bem no lugar onde o visitante
     * decide se preenche.
     *
     * Some com o texto, não com o bloco: o elemento que o continha fica no
     * lugar, então o espaçamento da página não muda. E não toca no conteúdo
     * salvo — quem reativar o plugin volta a ver o formulário, sem precisar
     * desfazer nada aqui.
     *
     * Deliberadamente restrito a shortcodes conhecidos e sem efeito colateral
     * (formulário). Não é um "limpador de shortcode" genérico: sumir com algo
     * que alguém queria ver é pior do que o problema.
     */
    private function limpar_shortcode_orfao($html) {
        if (strpos($html, '[') === false) return $html;
        $orfaos = apply_filters('adspirit_shortcodes_orfaos', array(
            'contact-form-7', 'contact-form', 'wpcf7',
        ));
        $mudou = false;
        foreach ($orfaos as $tag) {
            // Só remove se o shortcode NÃO estiver registrado — plugin ativo
            // significa que ele deve renderizar normalmente.
            if (shortcode_exists($tag)) continue;
            $novo = preg_replace('/\[' . preg_quote($tag, '/') . '\b[^\]]*\]/i', '', $html);
            if (is_string($novo) && $novo !== $html) { $html = $novo; $mudou = true; }
        }
        return $html;
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

    /**
     * Deixa de carregar a biblioteca de carrossel em página que não tem
     * carrossel.
     *
     * O oxy-ninja enfileira o Splide em TODAS as páginas — biblioteca, CSS e
     * a extensão de auto-scroll, três requisições. Verificado em 01/09 num
     * navegador de verdade, em cinco páginas do site: a biblioteca carrega,
     * e zero elementos a usam. É peso que nunca vira pixel na tela.
     *
     * A remoção é condicionada a PROVA DE NÃO-USO, não a uma lista de
     * páginas: se a marcação do carrossel aparecer, ou se algum script
     * chamar a API, o módulo sai de cena sozinho. Assim o dia em que alguém
     * puser um carrossel numa página, ele funciona — sem ninguém lembrar de
     * mexer aqui.
     */
    private function dispensar_carrossel($html) {
        if (stripos($html, 'splide') === false) return $html;

        // Prova de uso — qualquer uma basta pra não mexer.
        if (preg_match('/class\s*=\s*("[^"]*|\'[^\']*)\bsplide/i', $html)) return $html;
        if (preg_match('/\bnew\s+Splide\b|\bSplide\s*\(/', $html)) return $html;

        $antes = $html;
        $novo = preg_replace(
            '#<script\b[^>]*\bsrc=(["\'])[^"\']*splide[^"\']*\1[^>]*>\s*</script>#i', '', $html);
        if (is_string($novo)) $html = $novo;
        $novo = preg_replace(
            '#<link\b[^>]*\bhref=(["\'])[^"\']*splide[^"\']*\1[^>]*>#i', '', $html);
        if (is_string($novo)) $html = $novo;

        return $html === '' ? $antes : $html;
    }

}
