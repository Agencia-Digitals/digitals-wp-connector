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

    /** Duas letras pro chip quando não há logo. */
    private function iniciais($nome) {
        $limpo = trim(preg_replace('/[^A-Za-zÀ-ÿ ]/u', '', $nome));
        $partes = preg_split('/\s+/', $limpo);
        if (count($partes) >= 2) return mb_strtoupper(mb_substr($partes[0], 0, 1) . mb_substr($partes[1], 0, 1));
        return mb_strtoupper(mb_substr($limpo, 0, 2));
    }

    private function card($i) {
        $ativo = $i['situacao'] === 'nosso';
        $marca = in_array($i['marca'], array('meta','google','hotjar','clarity','adspirit','vazio'), true) ? $i['marca'] : 'outro';
        $rotulo_chip = $marca === 'adspirit' ? 'AS' : $this->iniciais(!empty($i['fornecedor']) ? $i['fornecedor'] : $i['nome']);

        $classe = 'as-card' . ($ativo ? ' as-card--ativo' : '');
        $pe_classe = $ativo ? 'as-card-pe as-card-pe--ativo'
            : (!empty($i['pode_substituir']) ? 'as-card-pe as-card-pe--acao' : 'as-card-pe');

        $interno = '<span class="as-card-topo">'
            . '<span class="as-chip as-chip--' . esc_attr($marca) . '">' . esc_html($rotulo_chip) . '</span>'
            . '<span style="min-width:0">'
            . '<span class="as-card-nome">' . esc_html($i['nome']) . '</span>'
            . '<span class="as-card-cat">' . esc_html($i['cat']) . '</span>'
            . '</span></span>'
            . '<span class="' . esc_attr($pe_classe) . '">' . esc_html($i['pe']) . '</span>';

        // Card que oferece troca é um botão de verdade; o resto é só leitura.
        if (!empty($i['pode_substituir'])) {
            AdSpirit_Menu::form_open('connection');
            echo '<input type="hidden" name="assumir_item" value="' . esc_attr($i['chave']) . '">';
            echo '<button type="submit" class="' . esc_attr($classe) . '" title="Passar '
               . esc_attr($i['nome']) . ' pro AdSpirit">' . $interno . '</button>';
            echo '</form>';
            return;
        }
        echo '<div class="' . esc_attr($classe) . '">' . $interno . '</div>';
    }

    private function faixa($rotulo, $itens, $dica, $vazio = '') {
        if (!$itens) {
            if ($vazio === '') return;
            echo '<div class="as-faixa"><h4>' . esc_html($rotulo) . '</h4>';
            echo '<div class="as-vazio">' . esc_html($vazio) . '</div></div>';
            return;
        }
        echo '<div class="as-faixa">';
        echo '<h4>' . esc_html($rotulo) . '<span class="as-conta">' . count($itens) . '</span></h4>';
        echo '<p>' . esc_html($dica) . '</p>';
        echo '<div class="as-cards">';
        foreach ($itens as $i) $this->card($i);
        echo '</div>';
        foreach ($itens as $i) {
            if (empty($i['alerta'])) continue;
            echo '<div class="as-notice warning" style="margin-top:10px"><p><strong>'
               . esc_html($i['nome']) . ':</strong> ' . esc_html($i['alerta']) . '</p></div>';
        }
        echo '</div>';
    }

    public function render() {
        if (!class_exists('AdSpirit_Handshake')) return;

        $g = AdSpirit_Handshake::por_situacao();
        $tudo_certo = !$g['de_outro'] && !$g['falta'];

        AdSpirit_Menu::card_open(
            $tudo_certo ? 'Tudo passando pelo AdSpirit' : 'O que este site já faz',
            $tudo_certo
                ? 'Verificado neste site agora. Não há nada pendente.'
                : 'Verificado neste site agora — não é o que está configurado aqui, é o que está de fato acontecendo na página.',
            ''
        );

        $this->faixa(
            'Pelo AdSpirit', $g['nosso'],
            'O AdSpirit cuida. O valor vem da sua conta e vale pra todos os sites.'
        );

        $this->faixa(
            'Já existe no site', $g['de_outro'],
            'Outra ferramenta faz isto, e funciona. Passar pro AdSpirit centraliza o controle — clique no card pra trocar.'
        );

        $this->faixa(
            'Ninguém faz', $g['falta'],
            'Nada mede isto neste site hoje.',
            'Nada faltando.'
        );

        $this->faixa(
            'Não consegui verificar', $g['sem_leitura'],
            'Não deu pra ler a home deste site — pode ser cache, senha de acesso ou bloqueio. '
            . 'Enquanto isso, prefiro não afirmar nada sobre estes itens.'
        );

        // Aviso de opção que promete e não entrega.
        $core = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();
        if (($core['pixel_firstparty'] ?? '0') === '1') {
            echo '<div class="as-notice warning"><p><strong>Servir o rastreador pelo endereço deste site está ligado, '
               . 'mas foi neutralizado.</strong> Nessa configuração o rastreador era carregado sem a chave da marca e '
               . 'parava de reportar — o site ficava sem medição, sem nenhum aviso. Enquanto isso não é corrigido, o '
               . 'rastreador continua vindo do AdSpirit, que funciona. Você não precisa fazer nada.</p></div>';
        }

        AdSpirit_Menu::card_close();
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
