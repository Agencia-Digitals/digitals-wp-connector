<?php
/**
 * AdSpirit Connector — Medição do site.
 *
 * Existe porque a aba de Conexão virou depósito. O trabalho dela é um só —
 * "este site está ligado ao AdSpirit, e com qual marca" — e eu tinha pendurado
 * ali mais duas perguntas que não são essa: quem manda na medição, e se há
 * pixel repetido. Três assuntos, seis cards, oito botões na mesma tela.
 *
 * Aqui cada aba volta a responder uma pergunta:
 *
 *   Conexão  → este site fala com o AdSpirit?
 *   Medição  → o que ele mede, quem manda nisso, e há conflito?
 *
 * As telas de detalhe (Conversões Meta, Conversões Google, Gravações, Rastreio
 * entre sites) continuam onde estavam. Esta aba é o mapa: mostra o estado de
 * cada uma e leva pra lá. Quem chega sabendo o que quer vai direto; quem chega
 * perdido começa por aqui.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Medicao {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_filter(
            'adspirit_connector_tabs',
            AdSpirit_Safe_Hook::filter(array($this, 'registrar_aba'), 'medicao_aba')
        );
        // Prioridade tardia: só dá pra reagrupar depois que todo módulo já
        // registrou a aba dele.
        add_filter(
            'adspirit_connector_tabs',
            AdSpirit_Safe_Hook::filter(array($this, 'reagrupar'), 'medicao_reagrupar'),
            99
        );
        // Prioridade alta: o mapa vem depois dos painéis de estado, que são o
        // que a pessoa precisa ver primeiro.
        add_action(
            'adspirit_connector_render_tab_medicao',
            AdSpirit_Safe_Hook::action(array($this, 'render_mapa'), 'medicao_mapa'),
            50
        );
    }

    public function registrar_aba($abas) {
        if (!is_array($abas)) return $abas;
        // Entra logo antes das telas de detalhe, como capa do grupo.
        $novo = array();
        foreach ($abas as $slug => $label) {
            if ($slug === 'capi-meta' && !isset($novo['medicao'])) {
                $novo['medicao'] = 'Medição do site';
            }
            $novo[$slug] = $label;
        }
        if (!isset($novo['medicao'])) $novo['medicao'] = 'Medição do site';
        return $novo;
    }

    /**
     * Junta a família da medição no menu.
     *
     * Cada módulo registra a aba dele quando é carregado, então a ordem do
     * menu acabava sendo a ordem de carregamento: "Gravações" caía na posição
     * 22, a vinte itens das outras três telas que fazem a mesma coisa. Quem
     * procura não adivinha isso — procura onde faz sentido.
     */
    public function reagrupar($abas) {
        if (!is_array($abas) || !isset($abas['medicao'])) return $abas;

        $familia = array('medicao', 'capi-meta', 'ga4', 'clarity', 'behavioral', 'cross-domain');
        $presentes = array();
        foreach ($familia as $slug) {
            if (isset($abas[$slug])) { $presentes[$slug] = $abas[$slug]; unset($abas[$slug]); }
        }
        if (!$presentes) return $abas;

        // Reinsere o bloco inteiro onde a capa estava — logo depois de
        // Anti-spam, antes das telas de detalhe soltas.
        $novo = array();
        $inserido = false;
        foreach ($abas as $slug => $label) {
            $novo[$slug] = $label;
            if (!$inserido && $slug === 'antispam') {
                foreach ($presentes as $s => $l) $novo[$s] = $l;
                $inserido = true;
            }
        }
        if (!$inserido) foreach ($presentes as $s => $l) $novo[$s] = $l;
        return $novo;
    }

    /** Estado de cada ferramenta, com link pra tela dela. */
    private function ferramentas() {
        $capi = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_capi_meta() : array();
        $ga4  = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_ga4() : array();
        $clr  = class_exists('AdSpirit_Clarity') ? AdSpirit_Clarity::get_settings() : array();
        $cd   = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_cross_domain() : array();

        return array(
            array(
                'aba' => 'capi-meta', 'nome' => 'Conversões Meta',
                'oque' => 'Manda lead e evento pros anúncios do Facebook e Instagram.',
                'valor' => trim((string) ($capi['pixel_id'] ?? '')),
                'ligado' => ($capi['enabled'] ?? '0') === '1',
            ),
            array(
                'aba' => 'ga4', 'nome' => 'Conversões Google',
                'oque' => 'Manda lead e evento pro Google Analytics 4.',
                'valor' => trim((string) ($ga4['measurement_id'] ?? '')),
                'ligado' => ($ga4['enabled'] ?? '0') === '1',
            ),
            array(
                'aba' => 'clarity', 'nome' => 'Gravações de sessão',
                'oque' => 'Mapa de calor e gravação do que o visitante faz.',
                'valor' => trim((string) ($clr['project_id'] ?? '')),
                'ligado' => ($clr['enabled'] ?? '0') === '1',
            ),
            array(
                'aba' => 'behavioral', 'nome' => 'Comportamento no site',
                'oque' => 'Rolagem, cliques e engajamento anexados a cada lead.',
                'valor' => '',
                'ligado' => (get_option('adspirit_connector_behavioral', array())['enabled'] ?? '0') === '1',
            ),
            array(
                'aba' => 'cross-domain', 'nome' => 'Rastreio entre sites',
                'oque' => 'Mantém a jornada quando o visitante troca de domínio.',
                'valor' => trim((string) ($cd['domains'] ?? '')) !== '' ? 'configurado' : '',
                'ligado' => ($cd['enabled'] ?? '0') === '1',
            ),
        );
    }

    public function render_mapa() {
        $url = admin_url('admin.php?page=' . (defined('AdSpirit_Menu::PAGE_SLUG') ? AdSpirit_Menu::PAGE_SLUG : 'adspirit-connector'));

        AdSpirit_Menu::card_open(
            'O que este site mede',
            'Cada linha leva pra tela onde se ajusta aquilo. Quando o AdSpirit está no comando, os valores vêm de lá e não precisam ser digitados aqui.',
            ''
        );

        echo '<table class="widefat striped"><tbody>';
        foreach ($this->ferramentas() as $f) {
            $link = esc_url(add_query_arg('adspirit_tab', $f['aba'], $url));
            $estado = $f['ligado']
                ? '<span style="color:var(--as-ok,#1F6B4A);font-weight:600">ligado</span>'
                : ($f['valor'] !== ''
                    ? '<span style="color:var(--as-warn,#8A5A00)">configurado, desligado</span>'
                    : '<span style="color:#8A95A0">não configurado</span>');
            echo '<tr>';
            echo '<td style="width:34%"><a href="' . $link . '"><strong>' . esc_html($f['nome']) . '</strong></a>'
               . '<br><span class="as-field-help">' . esc_html($f['oque']) . '</span></td>';
            echo '<td style="width:30%"><code>' . esc_html($f['valor'] !== '' ? $f['valor'] : '—') . '</code></td>';
            echo '<td>' . $estado . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';

        AdSpirit_Menu::card_close();
    }
}
