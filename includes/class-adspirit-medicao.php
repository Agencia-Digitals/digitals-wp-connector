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

    /**
     * Tags de navegador: o que o AdSpirit mandou colocar na página, e o
     * que o plugin decidiu fazer com isso.
     *
     * A decisão importa mais que o estado: "não injetei porque a página já
     * tem" é uma informação útil, e some se a tela mostrar só ligado/
     * desligado.
     */
    private function render_tags() {
        $e = AdSpirit_Tags::estado();
        $rotulos = array('meta' => 'Pixel da Meta', 'ga4' => 'Google Analytics 4');
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">No site</span>Tags de navegador</h2>
        <p class="as-section-help">
            O AdSpirit escolhe qual identificador é desta marca e manda pra cá. O plugin só
            coloca na página o que ainda não está lá — nunca por cima de tag existente, porque
            duas medindo ao mesmo tempo dobram a conversão.
        </p>
        <ul class="as-cards">
            <?php foreach ($q['linhas'] as $f) :
                $estado = $f['check']['estado'] ?? 'nao_testavel';
                $tom = AdSpirit_Fontes::tom($estado);
            ?>
                <li class="as-card-fonte">
                    <div class="as-card-topo">
                        <span class="as-card-marca"><?php echo AdSpirit_Fontes::marca($f['marca'] ?? ''); ?></span>
                        <span class="as-status as-status--<?php echo esc_attr($tom); ?>">
                            <span class="as-status-dot" aria-hidden="true"></span><?php
                            echo esc_html(AdSpirit_Fontes::rotulo($estado));
                        ?></span>
                    </div>
                    <h3 class="as-card-nome"><?php echo esc_html($f['nome']); ?></h3>
                    <p class="as-card-papel"><?php echo esc_html($f['papel']); ?></p>

                    <?php if (!empty($f['volume'])) : ?>
                        <p class="as-card-numero">
                            <strong><?php echo esc_html($f['volume']['numero']); ?></strong>
                            <?php echo esc_html($f['volume']['rotulo']); ?>
                        </p>
                    <?php endif; ?>

                    <?php if (!empty($f['check']['detalhe'])) : ?>
                        <p class="as-card-detalhe<?php echo $tom === 'danger' ? ' ruim' : ''; ?>"><?php
                            echo esc_html($f['check']['detalhe']);
                        ?></p>
                    <?php elseif (!empty($f['nota_volume'])) : ?>
                        <p class="as-card-detalhe"><?php echo esc_html($f['nota_volume']); ?></p>
                    <?php endif; ?>

                    <div class="as-card-rodape">
                        <span><?php
                            echo !empty($f['credencial'])
                                ? esc_html($f['credencial'])
                                : '<span class="vazio">sem identificação</span>';
                        ?></span>
                        <?php if (!empty($f['aba'])) : ?>
                            <a href="<?php echo esc_url(add_query_arg('adspirit_tab', $f['aba'], $url)); ?>">Gerenciar &rarr;</a>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    /**
     * Monitor das fontes: conectada? funcional? gerando dado?
     *
     * O veredito vem de verificação ATIVA (o plugin bate na API de cada
     * fonte), não de "a caixinha está marcada". Fonte que não dá pra
     * verificar aparece como tal, com o motivo — nunca como saudável.
     */
    private function render_monitor() {
        $url = admin_url('admin.php?page=' . (defined('AdSpirit_Menu::PAGE_SLUG') ? AdSpirit_Menu::PAGE_SLUG : 'adspirit-connector'));
        $q = AdSpirit_Fontes::quadro();
        $quando = !empty($q['verificado_em'])
            ? human_time_diff((int) $q['verificado_em'], time()) . ' atrás'
            : 'ainda não verificado';
        ?>
        <div class="as-monitor-topo">
            <div>
                <h2 class="as-section" style="margin:0;"><span class="as-kicker-inline">Diagnóstico</span>Fontes de dados</h2>
                <p class="as-section-help" style="margin:4px 0 0;">
                    Verificado <?php echo esc_html($quando); ?>. Cada fonte é testada de verdade —
                    o plugin pergunta pra ela, em vez de supor pela configuração.
                </p>
            </div>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                <input type="hidden" name="action" value="adspirit_fontes_scan">
                <?php wp_nonce_field('adspirit_fontes_scan'); ?>
                <button type="submit" class="button">Verificar agora</button>
            </form>
        </div>

        <ul class="as-fontes">
            <?php foreach ($q['linhas'] as $f) :
                $estado = $f['check']['estado'] ?? 'nao_testavel';
                $tom = AdSpirit_Fontes::tom($estado);
            ?>
                <li class="as-fonte">
                    <div class="as-fonte-head">
                        <span class="as-fonte-nome"><?php echo esc_html($f['nome']); ?></span>
                        <span class="as-status as-status--<?php echo esc_attr($tom); ?>">
                            <span class="as-status-dot" aria-hidden="true"></span><?php
                            echo esc_html(AdSpirit_Fontes::rotulo($estado));
                        ?></span>
                    </div>
                    <p class="as-fonte-papel"><?php echo esc_html($f['papel']); ?></p>

                    <?php if (!empty($f['check']['detalhe'])) : ?>
                        <p class="as-fonte-detalhe<?php echo $tom === 'danger' ? ' ruim' : ''; ?>"><?php
                            echo esc_html($f['check']['detalhe']);
                        ?></p>
                    <?php endif; ?>

                    <?php if (!empty($f['volume'])) : ?>
                        <div class="as-recurso-metrica">
                            <span class="as-recurso-num"><?php echo esc_html($f['volume']['numero']); ?></span>
                            <span class="as-recurso-num-rot"><?php echo esc_html($f['volume']['rotulo']); ?></span>
                        </div>
                        <?php if (!empty($f['volume']['ultimo'])) : ?>
                            <p class="as-fonte-detalhe">Último há <?php
                                echo esc_html(human_time_diff((int) $f['volume']['ultimo'], time()));
                            ?>.</p>
                        <?php endif; ?>
                    <?php elseif (!empty($f['nota_volume'])) : ?>
                        <p class="as-fonte-detalhe"><?php echo esc_html($f['nota_volume']); ?></p>
                    <?php endif; ?>

                    <dl class="as-recurso-meta">
                        <?php if (!empty($f['credencial'])) : ?>
                            <div><dt>Identificação</dt><dd><?php echo esc_html($f['credencial']); ?></dd></div>
                        <?php endif; ?>
                        <?php if (!empty($f['origem'])) : ?>
                            <div><dt>Quem configura</dt><dd><?php echo esc_html($f['origem']); ?></dd></div>
                        <?php endif; ?>
                    </dl>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php
    }

    public function render_mapa() {
        $url = admin_url('admin.php?page=' . (defined('AdSpirit_Menu::PAGE_SLUG') ? AdSpirit_Menu::PAGE_SLUG : 'adspirit-connector'));

        // Monitor primeiro: quem abre esta aba quer saber se está tudo de
        // pé. O mapa de "quem ajusta o quê" vem depois — é referência, não
        // diagnóstico.
        if (class_exists('AdSpirit_Fontes')) $this->render_monitor();
        if (class_exists('AdSpirit_Tags')) $this->render_tags();

        AdSpirit_Menu::card_open(
            'O que este site mede',
            'Cada linha leva pra tela onde se ajusta aquilo. Quando o AdSpirit está no comando, os valores vêm de lá e não precisam ser digitados aqui.',
            ''
        );

        // Este quadro lista o que o CONNECTOR gerencia. Se o site também usa
        // Tag Manager ou Site Kit, existe medição que não passa por aqui e
        // que esta tela não enxerga — dizer isso antes da tabela evita a
        // leitura errada de "não configurado" como "não está medindo".
        // (Incidente 29/08: diagnóstico concluiu GA4/Clarity desligados num
        // site que media tudo pelo GTM.)
        $rel = class_exists('AdSpirit_Pixel_Conflito')
            ? get_option(AdSpirit_Pixel_Conflito::OPTION_RELATORIO, array())
            : array();
        if (is_array($rel) && !empty($rel['varredura_cega'])) {
            $fontes = !empty($rel['cega_por']) && is_array($rel['cega_por'])
                ? implode(', ', array_map('sanitize_text_field', $rel['cega_por']))
                : 'Tag Manager';
            echo '<div class="as-notice info" style="margin-bottom:12px"><p><strong>Este site também mede por fora do connector.</strong> '
               . 'Detectamos ' . esc_html($fontes) . '. O que passa por ali é injetado depois, pelo navegador, '
               . 'e não aparece nesta tela — então "não configurado" abaixo significa <em>não configurado aqui</em>, '
               . 'e não que o canal esteja desligado. Confira no Tag Manager antes de ligar algo em duplicado.</p></div>';
        }

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
