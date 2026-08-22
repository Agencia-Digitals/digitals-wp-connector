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

    /**
     * O estado de cada elo. É o que a aba de Conexão desenha.
     */
    public static function estado() {
        $core = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();
        $rel = class_exists('AdSpirit_Pixel_Conflito') ? AdSpirit_Pixel_Conflito::relatorio() : array();
        $tem_gtm = !empty($rel['gtm']);
        $itens = array();

        // 1. A conexão em si — sem ela, nada mais importa.
        $conectado = class_exists('AdSpirit_Connect') && AdSpirit_Connect::is_connected();
        $itens[] = self::item(
            'conexao', 'Ligação com o AdSpirit',
            'A chave que autoriza este site a falar com a sua conta.',
            $conectado ? 'nosso' : 'ausente',
            $conectado
                ? sprintf('Conectado à marca %s.', $core['brand_slug'] ?? '—')
                : 'Este site ainda não foi conectado.'
        );

        // 2. Pixel do AdSpirit — é ele que atribui a origem de cada lead.
        $total = (int) ($rel['pixel_total'] ?? 0);
        $nosso = (int) ($rel['pixel_do_connector'] ?? 0);
        $fora  = (int) ($rel['pixel_de_fora'] ?? 0);
        if ($nosso > 0) {
            $sit = 'nosso'; $det = 'O connector injeta o pixel nesta página.';
        } elseif ($fora > 0) {
            $sit = 'de_outro';
            $onde = '';
            foreach ((array) ($rel['snippets'] ?? array()) as $sn) {
                if (!empty($sn['ativo']) && !empty($sn['nosso'])) {
                    $onde = sprintf(' Está em %s, no trecho "%s".', $sn['origem'], $sn['nome']); break;
                }
            }
            $det = 'O pixel já está na página, colado fora do connector.' . $onde;
        } elseif ($total > 0) {
            $sit = 'de_outro'; $det = 'O pixel está na página, por outra fonte.';
        } else {
            $sit = 'ausente'; $det = 'Nenhum pixel do AdSpirit foi encontrado na home.';
        }
        $itens[] = self::item('pixel', 'Pixel do AdSpirit',
            'Liga cada visita ao anúncio que a trouxe — é o que dá origem ao lead.',
            $sit, $det, array('pode_substituir' => $sit === 'de_outro' && $fora > 0));

        // 3–5. Ferramentas de medição.
        $itens[] = self::medicao_item('meta', 'Pixel da Meta',
            'Mede e reporta conversões pro Facebook e Instagram.',
            class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_capi_meta() : array(),
            'pixel_id', (array) ($rel['meta_na_pagina'] ?? array()), $tem_gtm);

        $itens[] = self::medicao_item('ga4', 'Google Analytics 4',
            'Mede sessões e conversões no Analytics.',
            class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_ga4() : array(),
            'measurement_id', (array) ($rel['ga4_na_pagina'] ?? array()), $tem_gtm);

        $itens[] = self::medicao_item('clarity', 'Gravações de sessão',
            'Mapa de calor e gravação do que o visitante faz.',
            class_exists('AdSpirit_Clarity') ? AdSpirit_Clarity::get_settings() : array(),
            'project_id', (array) ($rel['clarity_na_pagina'] ?? array()), $tem_gtm);

        // 6. Formulários — o elo que prova entrega de ponta a ponta.
        $itens[] = self::item('formularios', 'Entrega de leads',
            'Cada formulário enviado chega no AdSpirit.',
            $conectado ? 'nosso' : 'ausente',
            $conectado
                ? 'Os formulários do site enviam pro AdSpirit.'
                : 'Sem conexão, nenhum lead é entregue.'
        );

        return $itens;
    }

    /**
     * Um item de medição: compara o que o connector tem configurado com o que
     * está de fato na página.
     */
    private static function medicao_item($chave, $nome, $oque, $config, $campo, $na_pagina, $tem_gtm) {
        $meu = trim((string) ($config[$campo] ?? ''));
        $ligado = ($config['enabled'] ?? '0') === '1';

        if ($ligado && $meu !== '') {
            return self::item($chave, $nome, $oque, 'nosso',
                sprintf('O AdSpirit cuida disto (%s).', $meu));
        }
        if ($na_pagina) {
            return self::item($chave, $nome, $oque, 'de_outro',
                sprintf('Já está na página (%s), por fora do AdSpirit.', implode(', ', $na_pagina)),
                array('pode_substituir' => true, 'valor_na_pagina' => $na_pagina));
        }
        if ($tem_gtm) {
            return self::item($chave, $nome, $oque, 'invisivel',
                'Este site usa Tag Manager, e daqui não dá pra ver o que ele injeta. Confira no Tag Assistant.');
        }
        return self::item($chave, $nome, $oque, 'ausente',
            'Ninguém está medindo isto neste site.');
    }

    /** Resumo pra uma frase só no topo da tela. */
    public static function resumo() {
        $itens = self::estado();
        $c = array('nosso' => 0, 'de_outro' => 0, 'ausente' => 0, 'invisivel' => 0);
        foreach ($itens as $i) {
            if (isset($c[$i['situacao']])) $c[$i['situacao']]++;
        }
        return $c;
    }
}
