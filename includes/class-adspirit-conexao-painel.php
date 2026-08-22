<?php
/**
 * AdSpirit Connector — O painel produtizado da aba de Conexão.
 *
 * A promessa é one-click: instala o connector, conecta, e tudo funciona
 * puxando do AdSpirit. Esta tela existe pra PROVAR isso, item a item, sem
 * pedir que ninguém entenda de pixel.
 *
 * A exceção é o site que já tem algo. Aí a tela não finge que está tudo certo
 * nem grita que está errado: explica o que já existe, diz de onde vem, e
 * oferece trocar pelo nosso. Nunca troca sozinha.
 *
 * A tela técnica (valores, chaves, conflito) continua em Medição do site.
 * Aqui é só "está funcionando?".
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Conexao_Painel {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Prioridade 4: o veredito vem antes das credenciais.
        add_action(
            'adspirit_connector_render_tab_connection',
            AdSpirit_Safe_Hook::action(array($this, 'render'), 'conexao_painel'),
            4
        );
        add_action(
            'adspirit_connector_save_connection',
            AdSpirit_Safe_Hook::action(array($this, 'assumir_item'), 'conexao_assumir')
        );
    }

    /** Ícone e cor por situação — a tela inteira se lê pela coluna da esquerda. */
    private function marca($situacao) {
        switch ($situacao) {
            case 'nosso':     return array('✓', '#1F6B4A', 'funcionando');
            case 'de_outro':  return array('!', '#8A5A00', 'já existe no site');
            case 'invisivel': return array('?', '#5B5F6B', 'não dá pra ver daqui');
            default:          return array('—', '#A3282A', 'falta configurar');
        }
    }

    public function render() {
        if (!class_exists('AdSpirit_Handshake')) return;

        $itens = AdSpirit_Handshake::estado();
        $r = AdSpirit_Handshake::resumo();
        $tudo_certo = $r['ausente'] === 0 && $r['de_outro'] === 0;

        $selo = $tudo_certo
            ? '<span class="as-badge" style="border-color:#A8D3BC;background:#E6F3EC;color:#1F6B4A">tudo funcionando</span>'
            : sprintf('<span class="as-badge">%d de %d no AdSpirit</span>', $r['nosso'], count($itens));

        AdSpirit_Menu::card_open(
            'Está tudo funcionando?',
            $tudo_certo
                ? 'Sim. Cada item abaixo foi verificado neste site agora.'
                : 'A lista abaixo mostra o que o AdSpirit já cuida e o que ainda não. Nada aqui é alterado sem você mandar.',
            $selo
        );

        echo '<table class="widefat" style="border:0"><tbody>';
        foreach ($itens as $i) {
            list($icone, $cor, $rotulo) = $this->marca($i['situacao']);
            echo '<tr>';
            echo '<td style="width:2.4rem;text-align:center;vertical-align:top;padding-top:14px">'
               . '<span style="display:inline-flex;width:22px;height:22px;border-radius:50%;align-items:center;'
               . 'justify-content:center;font-weight:700;color:#fff;background:' . esc_attr($cor) . '">'
               . esc_html($icone) . '</span></td>';
            echo '<td style="vertical-align:top">'
               . '<strong>' . esc_html($i['nome']) . '</strong> '
               . '<span style="color:' . esc_attr($cor) . ';font-size:11px;text-transform:uppercase;letter-spacing:.04em">'
               . esc_html($rotulo) . '</span>'
               . '<br><span class="as-field-help">' . esc_html($i['oque']) . '</span>'
               . '<br><span class="as-field-help" style="color:#3D4C4E">' . esc_html($i['detalhe']) . '</span>'
               . '</td>';

            echo '<td style="width:12rem;vertical-align:top;text-align:right;padding-top:12px">';
            if (!empty($i['pode_substituir'])) {
                $this->botao_substituir($i);
            }
            echo '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        if ($r['de_outro'] > 0) {
            echo '<div class="as-notice"><p><strong>Sobre o que já existe.</strong> '
               . 'Não é problema — o site foi montado assim antes do AdSpirit chegar, e continua '
               . 'medindo. Trocar pelo nosso faz o valor passar a vir do AdSpirit, e aí muda num '
               . 'lugar só pra todos os sites. Enquanto não trocar, nada é alterado.</p></div>';
        }
        if ($r['invisivel'] > 0) {
            echo '<div class="as-notice"><p><strong>Sobre o Tag Manager.</strong> '
               . 'O que ele injeta só aparece depois do JavaScript rodar, e esta verificação lê o '
               . 'HTML do servidor. Prefiro dizer que não enxergo a dizer que falta.</p></div>';
        }

        AdSpirit_Menu::card_close();
    }

    private function botao_substituir($item) {
        AdSpirit_Menu::form_open('connection');
        echo '<input type="hidden" name="assumir_item" value="' . esc_attr($item['chave']) . '">';
        echo '<button type="submit" class="button">Usar o do AdSpirit</button>';
        echo '</form>';
    }

    /**
     * Troca um item pelo que o AdSpirit tem. Sempre explícito, um de cada vez.
     */
    public function assumir_item($post) {
        $chave = isset($post['assumir_item']) ? sanitize_key($post['assumir_item']) : '';
        if ($chave === '') return;
        if (!current_user_can('manage_options')) return;

        // O pixel tem caminho próprio: existe um trecho colado à mão pra
        // desligar, e o módulo de conflito sabe exatamente qual é.
        if ($chave === 'pixel' && class_exists('AdSpirit_Pixel_Conflito')) {
            $r = AdSpirit_Pixel_Conflito::relatorio();
            $alvo = isset($r['trecho_pra_desligar']) ? $r['trecho_pra_desligar'] : null;
            if (is_array($alvo) && !empty($alvo['id'])) {
                global $wpdb;
                $wpdb->update($alvo['tabela'], array($alvo['coluna_ativo'] => 0),
                    array('id' => (int) $alvo['id']), array('%d'), array('%d'));
                AdSpirit_Settings::update_core(array('pixel_enabled' => '1'));
                wp_cache_flush();
                do_action('litespeed_purge_all');
                AdSpirit_Pixel_Conflito::instance()->verificar();
                add_settings_error('adspirit_conexao', 'ok', sprintf(
                    'Pronto: o trecho "%s" foi desligado e o AdSpirit assumiu o pixel.',
                    esc_html($alvo['nome'])), 'updated');
                return;
            }
            AdSpirit_Settings::update_core(array('pixel_enabled' => '1'));
            add_settings_error('adspirit_conexao', 'ok',
                'O AdSpirit passou a injetar o pixel. A cópia antiga não pôde ser desligada daqui — confira de onde ela vem.', 'updated');
            return;
        }

        // Medição: o AdSpirit precisa conhecer o valor pra poder assumir.
        $mapa = array(
            'meta' => array('AdSpirit_Settings', 'get_capi_meta', 'update_capi_meta', 'pixel_id', 'Pixel da Meta'),
            'ga4' => array('AdSpirit_Settings', 'get_ga4', 'update_ga4', 'measurement_id', 'Google Analytics 4'),
            'clarity' => array('AdSpirit_Clarity', 'get_settings', 'update_settings', 'project_id', 'Gravações de sessão'),
        );
        if (!isset($mapa[$chave])) return;
        list($classe, $ler, $gravar, $campo, $nome) = $mapa[$chave];
        if (!class_exists($classe)) return;

        $atual = call_user_func(array($classe, $ler));
        if (trim((string) ($atual[$campo] ?? '')) === '') {
            add_settings_error('adspirit_conexao', 'sem_valor', sprintf(
                'O AdSpirit ainda não tem o valor de %s pra esta marca. Cadastre lá primeiro — aí este botão passa a funcionar.',
                esc_html($nome)), 'error');
            return;
        }
        call_user_func(array($classe, $gravar), array('enabled' => '1'));
        add_settings_error('adspirit_conexao', 'ok', sprintf(
            '%s passou a ser cuidado pelo AdSpirit. Se ainda houver uma tag antiga no Tag Manager, remova de lá pra não contar duas vezes.',
            esc_html($nome)), 'updated');
    }
}
