<?php
/**
 * AdSpirit Connector — Estado real do elo entre o site e o AdSpirit.
 *
 * Nasceu de um erro meu. O painel anterior perguntava "o connector está
 * configurado pra fazer X?" e escrevia "não configurado" quando a resposta era
 * não. Só que num site que já media antes do plugin chegar — GTM injetando
 * pixel, tag colada à mão — tudo funciona e nenhuma chave do connector está
 * ligada. O painel dizia "quebrado" sobre um site perfeito.
 *
 * A pergunta certa é outra: **isto está acontecendo?** E, quando está, **quem
 * faz?** Três respostas possíveis por item:
 *
 *   nosso     — o AdSpirit cuida. É o estado que a gente quer.
 *   de_outro  — já existe no site, por outra fonte. Não é problema; é escolha.
 *   ausente   — ninguém faz. Aqui sim falta configurar.
 *
 * O `ausente` é o que o one-click resolve sozinho: se o AdSpirit conhece o
 * valor e ninguém mais está fazendo, o connector liga sem perguntar. O
 * `de_outro` nunca é mexido em silêncio — a tela explica o que já existe e
 * oferece trocar.
 *
 * LIMITE DECLARADO: o que o Tag Manager injeta só existe depois do JavaScript
 * rodar, e daqui lemos HTML. Havendo GTM, itens que ele possa estar cobrindo
 * ficam marcados como "não dá pra ver daqui" em vez de "ausente" — dizer que
 * falta o que talvez exista é o mesmo erro de antes, ao contrário.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Handshake {

    /** Um item do elo, já pronto pra tela. */
    private static function item($chave, $nome, $oque, $situacao, $detalhe, $extra = array()) {
        return array_merge(array(
            'chave' => $chave,
            'nome' => $nome,
            'oque' => $oque,
            'situacao' => $situacao,   // nosso | de_outro | ausente | invisivel | erro
            'detalhe' => $detalhe,
        ), $extra);
    }

    /** Os trabalhos que a tela mostra, na ordem, com a linguagem de quem usa. */
    private static function trabalhos() {
        return array(
            'adspirit'   => array('Origem dos leads', 'ATRIBUIÇÃO', 'Liga cada visita ao anúncio que a trouxe.'),
            'meta'       => array('Anúncios da Meta', 'MEDIÇÃO', 'Mede e reporta conversões pro Facebook e Instagram.'),
            'google-ads' => array('Google Ads', 'MEDIÇÃO', 'Mede e reporta conversões pros anúncios do Google.'),
            'analytics'  => array('Analytics', 'MEDIÇÃO', 'Sessões, origem de tráfego e comportamento.'),
            'gravacao'   => array('Gravação de sessão', 'COMPORTAMENTO', 'Mapa de calor e vídeo do que o visitante faz.'),
            'gerenciador'=> array('Gerenciador de tags', 'INFRAESTRUTURA', 'Onde muitas medições ficam penduradas.'),
        );
    }

    /** Quem o AdSpirit governa hoje, por trabalho. */
    private static function nossos() {
        $capi = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_capi_meta() : array();
        $ga4  = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_ga4() : array();
        $clr  = class_exists('AdSpirit_Clarity') ? AdSpirit_Clarity::get_settings() : array();
        $core = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();
        return array(
            'meta' => ($capi['enabled'] ?? '0') === '1' ? trim((string) $capi['pixel_id']) : '',
            'analytics' => ($ga4['enabled'] ?? '0') === '1' ? trim((string) $ga4['measurement_id']) : '',
            'gravacao' => ($clr['enabled'] ?? '0') === '1' ? trim((string) $clr['project_id']) : '',
            'adspirit' => ($core['pixel_enabled'] ?? '0') === '1' ? trim((string) $core['pixel_token']) : '',
        );
    }

    /**
     * O estado de cada elo, já agrupado por situação — que é como a tela
     * desenha: faixa por estado, cards dentro.
     */
    public static function estado() {
        $rel = class_exists('AdSpirit_Pixel_Conflito') ? AdSpirit_Pixel_Conflito::relatorio() : array();
        $html = isset($rel['html_amostra']) ? (string) $rel['html_amostra'] : '';

        // Sem amostra da página não há o que analisar — e a versão anterior,
        // nesse caso, respondia "ninguém mede isto". Reportar ausência sem
        // dado é pior que não reportar: o site estava medindo tudo e a tela
        // dizia que não. Acontecia em todo site que atualizou de uma versão
        // que ainda não guardava a amostra.
        //
        // Então: sem amostra, varre agora. É uma requisição só, uma vez.
        if ($html === '' && class_exists('AdSpirit_Pixel_Conflito')) {
            $rel = AdSpirit_Pixel_Conflito::instance()->verificar();
            $html = isset($rel['html_amostra']) ? (string) $rel['html_amostra'] : '';
        }
        $sem_dado = ($html === '');
        $mede = class_exists('AdSpirit_Deteccao') ? AdSpirit_Deteccao::quem_mede($html) : array();
        $nossos = self::nossos();
        $conectado = class_exists('AdSpirit_Connect') && AdSpirit_Connect::is_connected();
        $core = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();

        $itens = array();

        // A ligação e a entrega de leads são o motivo do plugin existir.
        // Não são "opções" — ou funcionam, ou o plugin não serve pra nada.
        $itens[] = array(
            'chave' => 'conexao', 'nome' => 'Ligação com o AdSpirit', 'cat' => 'CONTA',
            'oque' => 'A chave que autoriza este site a falar com a sua conta.',
            'marca' => 'adspirit',
            'situacao' => $conectado ? 'nosso' : 'falta',
            'pe' => $conectado ? ('Marca ' . ($core['brand_slug'] ?: '—')) : 'Conectar agora',
        );
        $itens[] = array(
            'chave' => 'leads', 'nome' => 'Entrega de leads', 'cat' => 'CONTA',
            'oque' => 'Todo formulário enviado chega no AdSpirit.',
            'marca' => 'adspirit',
            'situacao' => $conectado ? 'nosso' : 'falta',
            'pe' => $conectado ? 'Ativa' : 'Depende da ligação',
        );

        foreach (self::trabalhos() as $chave => $t) {
            list($nome, $cat, $oque) = $t;
            $meu = isset($nossos[$chave]) ? $nossos[$chave] : '';
            $fora = isset($mede[$chave]) ? $mede[$chave] : array();

            if ($meu !== '') {
                $itens[] = array(
                    'chave' => $chave, 'nome' => $nome, 'cat' => $cat, 'oque' => $oque,
                    'marca' => 'adspirit', 'situacao' => 'nosso',
                    'pe' => 'Pelo AdSpirit',
                );
                continue;
            }
            if ($fora) {
                $f = $fora[0];
                $outros = count($fora) > 1 ? sprintf(' +%d', count($fora) - 1) : '';
                $quem = $f['fornecedor'] . ($f['fonte'] ? ', ' . $f['fonte'] : '') . $outros;

                // O caso que mais dói e menos aparece: o navegador reporta pra
                // um pixel por uma ferramenta, e o AdSpirit reporta pro MESMO
                // pixel pelo servidor. Se os dois lados não parearem o evento,
                // cada visita conta duas vezes.
                $capi = ($chave === 'meta' && class_exists('AdSpirit_Settings'))
                    ? trim((string) (AdSpirit_Settings::get_capi_meta()['pixel_id'] ?? '')) : '';
                $mesmo_id = $capi !== '' && $f['id'] !== '' && $capi === $f['id'];
                $itens[] = array(
                    'chave' => $chave, 'nome' => $nome, 'cat' => $cat, 'oque' => $oque,
                    'marca' => $f['marca'], 'situacao' => 'de_outro',
                    'fornecedor' => $quem,
                    'id' => $f['id'],
                    'pe' => $mesmo_id ? 'Também no AdSpirit — conferir' : $quem,
                    'alerta' => $mesmo_id
                        ? sprintf('%s injeta o pixel %s no navegador, e o AdSpirit envia conversões pro mesmo pixel pelo servidor. Se os dois não parearem o evento, cada visita conta duas vezes.', $quem, $f['id'])
                        : '',
                    'pode_substituir' => in_array($chave, array('adspirit', 'meta', 'analytics', 'gravacao'), true),
                );
                continue;
            }
            // Gerenciador de tags ausente não é falta — é escolha de arquitetura.
            if ($chave === 'gerenciador') continue;
            // Sem leitura da página, "ninguém mede" seria chute. Diz que não
            // conseguiu olhar, que é a verdade.
            $itens[] = array(
                'chave' => $chave, 'nome' => $nome, 'cat' => $cat, 'oque' => $oque,
                'marca' => 'vazio', 'situacao' => $sem_dado ? 'sem_leitura' : 'falta',
                'pe' => $sem_dado ? 'Não consegui ler a página' : 'Ninguém mede isto',
            );
        }
        return $itens;
    }

    /** Agrupado por situação, na ordem em que a tela desenha. */
    public static function por_situacao() {
        $g = array('nosso' => array(), 'de_outro' => array(), 'falta' => array(), 'sem_leitura' => array());
        foreach (self::estado() as $i) {
            $g[$i['situacao']][] = $i;
        }
        return $g;
    }

    public static function resumo() {
        $g = self::por_situacao();
        return array('nosso' => count($g['nosso']), 'de_outro' => count($g['de_outro']),
                     'falta' => count($g['falta']), 'sem_leitura' => count($g['sem_leitura']));
    }
}
