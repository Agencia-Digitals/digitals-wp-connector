<?php
/**
 * Digitals Studio — a aba de construção do connector.
 *
 * Só existe num endereço nosso. No domínio do cliente o arquivo nem é
 * carregado, então a aba não aparece nem por acidente.
 *
 * O que mora aqui é o trabalho de fazer o site: converter o builder antigo,
 * conferir o que já foi convertido, ver o que o AdSpirit consegue operar
 * sozinho. Nada disso tem a ver com medir ou captar lead — por isso é aba
 * separada, e não mais um painel espremido na tela de conexão.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class Digitals_Studio_Aba {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_filter(
            'adspirit_connector_tabs',
            AdSpirit_Safe_Hook::filter(array($this, 'registrar_aba'), 'studio_aba_registrar')
        );
        add_action(
            'adspirit_connector_render_tab_studio',
            AdSpirit_Safe_Hook::action(array($this, 'render'), 'studio_aba_render')
        );
    }

    public function registrar_aba($abas) {
        if (!is_array($abas)) return $abas;
        $abas['studio'] = 'Studio';
        return $abas;
    }

    /** Conversor, quando carregado. */
    private function oxygen() {
        return class_exists('Digitals_Studio_Oxygen') ? new Digitals_Studio_Oxygen() : null;
    }

    public function render() {
        $this->painel_ambiente();
        $this->painel_oxygen();
        $this->painel_abilities();
    }

    // ─────────────────────────────────────────────────────────

    private function painel_ambiente() {
        AdSpirit_Menu::card_open(
            'Onde este site está',
            'As ferramentas desta aba só existem em endereço nosso. É o endereço que decide, não uma opção — assim um site clonado daqui pra produção do cliente perde as ferramentas sozinho, sem ninguém lembrar de desmarcar nada.',
            '<span class="as-badge">' . esc_html(AdSpirit_Ambiente::host()) . '</span>'
        );
        echo '<p class="as-field-help">' . esc_html(AdSpirit_Ambiente::descricao()) . '</p>';
        AdSpirit_Menu::card_close();
    }

    private function painel_oxygen() {
        $oxy = $this->oxygen();

        AdSpirit_Menu::card_open(
            'Oxygen classic → Oxygen 6',
            'Converte as árvores do builder antigo preservando o desenho original. Nunca altera os dados legados: o que existia continua lá, e dá pra voltar atrás post a post.',
            $oxy ? '' : '<span class="as-badge">motor ausente</span>'
        );

        if (!$oxy) {
            echo '<div class="as-notice danger"><p>O motor de conversão não carregou. '
               . 'Confira se o pacote instalado é o de estúdio.</p></div>';
            AdSpirit_Menu::card_close();
            return;
        }

        $inv = null;
        try {
            $inv = $oxy->ability_inventory(array());
        } catch (Throwable $e) {
            echo '<div class="as-notice danger"><p>Não consegui ler o inventário: '
               . esc_html($e->getMessage()) . '</p></div>';
        }

        if (is_array($inv)) {
            $itens = isset($inv['posts']) && is_array($inv['posts']) ? $inv['posts'] : array();
            $convertidos = 0;
            foreach ($itens as $i) {
                if (!empty($i['converted_at']) || !empty($i['convertido'])) $convertidos++;
            }
            $total = count($itens);
            $faltam = max(0, $total - $convertidos);

            echo '<div class="as-stats" style="display:flex;gap:22px;margin-bottom:10px">';
            printf('<div><strong style="font-size:20px">%d</strong><br><span class="as-field-help">com dados do Oxygen classic</span></div>', $total);
            printf('<div><strong style="font-size:20px">%d</strong><br><span class="as-field-help">já convertidos</span></div>', $convertidos);
            printf('<div><strong style="font-size:20px">%d</strong><br><span class="as-field-help">restantes</span></div>', $faltam);
            echo '</div>';

            if ($total === 0) {
                echo '<p class="as-field-help">Nenhum conteúdo com Oxygen classic neste site — nada a converter.</p>';
            }
        }

        echo '<p class="as-field-help">A conversão em si roda pelas abilities (abaixo), '
           . 'uma de cada vez e com diagnóstico, porque converter tudo de uma vez '
           . 'esconde qual página quebrou.</p>';

        AdSpirit_Menu::card_close();
    }

    private function painel_abilities() {
        AdSpirit_Menu::card_open(
            'O que o AdSpirit consegue operar aqui',
            'Operações que o time pode disparar deste site sem abrir o painel. Cada uma tem escopo declarado — não é execução de comando solto.',
            ''
        );

        if (!function_exists('wp_get_abilities')) {
            echo '<div class="as-notice"><p>Esta versão do WordPress não tem a API de Abilities. '
               . 'As ferramentas desta aba continuam funcionando pelo painel.</p></div>';
            AdSpirit_Menu::card_close();
            return;
        }

        $nossas = array();
        foreach (wp_get_abilities() as $a) {
            $nome = $a->get_name();
            if (strpos($nome, 'digitals-studio/') !== 0) continue;
            $nossas[] = array($nome, method_exists($a, 'get_label') ? $a->get_label() : '');
        }

        if (!$nossas) {
            echo '<p class="as-field-help">Nenhuma operação registrada.</p>';
        } else {
            echo '<table class="widefat striped"><tbody>';
            foreach ($nossas as $n) {
                echo '<tr><td style="width:38%"><code>' . esc_html($n[0]) . '</code></td>'
                   . '<td>' . esc_html($n[1]) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        AdSpirit_Menu::card_close();
    }
}
