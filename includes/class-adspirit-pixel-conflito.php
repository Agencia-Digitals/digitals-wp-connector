<?php
/**
 * AdSpirit Connector — Conflito de pixel.
 *
 * Site que já mede há anos costuma ter pixel colado em mais de um lugar: no
 * tema, num plugin de "headers and footers", dentro do Tag Manager. Quando o
 * AdSpirit passa a cuidar da medição, ninguém lembra do que já estava lá — e o
 * resultado é evento contado duas vezes, ou pior, evento indo pro pixel errado.
 *
 * Este módulo olha a própria home do site e responde três perguntas:
 *
 *   1. O nosso pixel.js está sendo carregado mais de uma vez?
 *   2. Existe pixel da Meta na página que não fomos nós que colocamos?
 *   3. O pixel que está na página é o MESMO que o AdSpirit usa no CAPI?
 *
 * A terceira é a que mais dói e a menos visível: navegador reportando pro
 * pixel A e servidor pro pixel B não deduplica — os dois contam, e nenhum dos
 * dois fica com a história completa.
 *
 * LIMITE HONESTO: o que o Tag Manager injeta só existe depois do JavaScript
 * rodar, e daqui a gente lê HTML. Quando há contêiner GTM, o módulo diz que
 * existe uma fonte que ele não consegue inspecionar, em vez de dar um "tudo
 * certo" que não pode garantir.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Pixel_Conflito {

    const OPTION_RELATORIO = 'adspirit_connector_pixel_conflito';
    const CRON = 'adspirit_connector_pixel_conflito_scan';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('init', AdSpirit_Safe_Hook::action(array($this, 'agendar'), 'pixel_conflito_agendar'));
        add_action(self::CRON, AdSpirit_Safe_Hook::action(array($this, 'verificar'), 'pixel_conflito_cron'));

        // Botão manual + painel na tab Conexão.
        add_action(
            'adspirit_connector_save_connection',
            AdSpirit_Safe_Hook::action(array($this, 'scan_manual'), 'pixel_conflito_save')
        );
        add_action(
            'adspirit_connector_render_tab_connection',
            AdSpirit_Safe_Hook::action(array($this, 'render_painel'), 'pixel_conflito_render'),
            6
        );

        // Não injetar por cima de quem já está lá.
        add_filter(
            'adspirit_pixel_injector_deve_injetar',
            AdSpirit_Safe_Hook::filter(array($this, 'evitar_duplicata'), 'pixel_conflito_guarda')
        );
    }

    public function agendar() {
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 900, 'daily', self::CRON);
        }
    }

    // ─────────────────────────────────────────────────────────
    // Leitura da página
    // ─────────────────────────────────────────────────────────

    /**
     * Busca a home pelo lado de fora. Query param só pra furar cache de página
     * — plugin de cache serviria uma cópia velha e o relatório mentiria.
     */
    private function baixar_home() {
        $url = add_query_arg('adspirit_scan', time(), home_url('/'));
        $r = wp_remote_get($url, array(
            'timeout' => 20,
            'sslverify' => false, // site pode ter certificado interno no staging
            'headers' => array('User-Agent' => 'AdSpirit-Connector/pixel-scan'),
        ));
        if (is_wp_error($r)) return array(null, $r->get_error_message());
        $codigo = (int) wp_remote_retrieve_response_code($r);
        if ($codigo !== 200) return array(null, 'A home respondeu HTTP ' . $codigo);
        return array((string) wp_remote_retrieve_body($r), null);
    }

    /** Plugins conhecidos por injetar pixel — dizer o nome poupa caçada. */
    private function plugins_suspeitos() {
        $mapa = array(
            'official-facebook-pixel/facebook-pixel.php' => 'Meta Pixel (plugin oficial)',
            'facebook-for-woocommerce/facebook-for-woocommerce.php' => 'Facebook for WooCommerce',
            'pixelyoursite/facebook-pixel-master.php' => 'PixelYourSite',
            'pixelyoursite-pro/pixelyoursite-pro.php' => 'PixelYourSite Pro',
            'insert-headers-and-footers/ihaf.php' => 'Insert Headers and Footers',
            'header-footer-code-manager/header-footer-code-manager.php' => 'Header Footer Code Manager',
            'duracelltomi-google-tag-manager/duracelltomi-google-tag-manager-for-wordpress.php' => 'GTM4WP',
            'google-site-kit/google-site-kit.php' => 'Google Site Kit',
        );
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $achados = array();
        foreach ($mapa as $arquivo => $nome) {
            if (is_plugin_active($arquivo)) $achados[] = $nome;
        }
        return $achados;
    }

    /**
     * Roda o exame e guarda o resultado. Retorna o relatório.
     */
    public function verificar() {
        list($html, $erro) = $this->baixar_home();
        if ($html === null) {
            $relatorio = array(
                'quando' => time(),
                'erro' => $erro,
                'alertas' => array(),
            );
            update_option(self::OPTION_RELATORIO, $relatorio, false);
            return $relatorio;
        }

        // Pixels da Meta visíveis no HTML — inline e o <noscript> de fallback.
        preg_match_all("/fbq\s*\(\s*['\"]init['\"]\s*,\s*['\"](\d{8,20})['\"]/", $html, $m1);
        preg_match_all('/facebook\.com\/tr\?id=(\d{8,20})/', $html, $m2);
        $na_pagina = array_values(array_unique(array_merge($m1[1], $m2[1])));

        // Quantas vezes o pixel do AdSpirit entra — e, das que entram, quantas
        // são nossas. O injetor assina o que emite com um comentário; tudo que
        // aparece sem assinatura foi colado à mão em algum lugar.
        //
        // A distinção importa: contar o total levaria o guarda abaixo a se
        // desligar e religar em ciclo (suprime → some uma → volta a injetar →
        // duas de novo). Contando só as de fora, o estado é estável.
        $total_pixel = preg_match_all('#/pixel\.js\?t=|adspirit-pixel-proxy|/adspirit-pixel/#', $html);
        $assinadas = preg_match_all('/AdSpirit Connector pixel/', $html);
        $de_fora = max(0, $total_pixel - $assinadas);

        $tem_gtm = (bool) preg_match('/googletagmanager\.com\/gtm\.js\?id=(GTM-[A-Z0-9]+)/', $html, $mg);
        $gtm_id = $tem_gtm ? $mg[1] : '';

        $capi = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_capi_meta() : array();
        $nosso_pixel = isset($capi['pixel_id']) ? trim((string) $capi['pixel_id']) : '';

        $alertas = array();

        // 1. Pixel do AdSpirit colado fora do connector.
        if ($de_fora > 0) {
            $alertas[] = array(
                'nivel' => 'erro',
                'texto' => $assinadas > 0
                    ? sprintf('O pixel do AdSpirit aparece %d vezes na home: %d pelo connector e %d colada à mão. Cada visita conta em dobro.', $total_pixel, $assinadas, $de_fora)
                    : sprintf('Há %d pixel(s) do AdSpirit colado(s) fora do connector nesta página.', $de_fora),
                'acao' => 'Procure no tema, num bloco de código do builder ou num plugin de headers. Enquanto a cópia de fora existir, o connector para de injetar a dele — melhor não medir do que medir em dobro.',
            );
        }

        // 2. Mesmo com uma só, se o connector está desligado a página fica sem.
        if ($de_fora === 0 && $assinadas === 0 && $total_pixel === 0) {
            $core = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();
            if (($core['pixel_enabled'] ?? '0') === '1') {
                $alertas[] = array(
                    'nivel' => 'aviso',
                    'texto' => 'O pixel está ligado nas configurações, mas não apareceu na home.',
                    'acao' => 'Pode ser cache de página servindo uma cópia antiga, ou o tema não estar chamando wp_head(). Limpe o cache e verifique de novo.',
                );
            }
        }

        // 3. Pixel da Meta que não é o nosso.
        foreach ($na_pagina as $id) {
            if ($nosso_pixel && $id === $nosso_pixel) continue;
            $alertas[] = array(
                'nivel' => $nosso_pixel ? 'erro' : 'aviso',
                'texto' => $nosso_pixel
                    ? sprintf('A página dispara o pixel %s, mas o AdSpirit envia as conversões pro pixel %s.', $id, $nosso_pixel)
                    : sprintf('Há um pixel da Meta (%s) na página que não veio do AdSpirit.', $id),
                'acao' => $nosso_pixel
                    ? 'Navegador reportando pra um pixel e servidor pra outro não deduplica: os dois contam pela metade. Acerte para que os dois usem o mesmo.'
                    : 'Se esse é o pixel da marca, cadastre o mesmo ID no AdSpirit pra que as conversões do servidor cheguem no lugar certo.',
            );
        }

        // 4. Mesmo pixel repetido no HTML.
        $repetidos = array_diff_assoc($m1[1], array_unique($m1[1]));
        foreach (array_unique($repetidos) as $id) {
            $alertas[] = array(
                'nivel' => 'erro',
                'texto' => sprintf('O pixel %s é iniciado mais de uma vez no HTML da home.', $id),
                'acao' => 'Duas fontes estão colando o mesmo pixel. Deixe uma só.',
            );
        }

        // 5. Tag Manager — fonte que não dá pra inspecionar daqui.
        if ($tem_gtm) {
            $alertas[] = array(
                'nivel' => 'nota',
                'texto' => sprintf('Este site tem um contêiner do Tag Manager (%s).', $gtm_id),
                'acao' => 'O que o Tag Manager injeta só aparece depois do JavaScript rodar, então esta verificação não enxerga. Confira as tags de pixel lá dentro antes de ligar a injeção pelo AdSpirit.',
            );
        }

        // 6. Plugins que costumam injetar.
        $plugins = $this->plugins_suspeitos();
        if ($plugins) {
            $alertas[] = array(
                'nivel' => 'nota',
                'texto' => 'Plugins ativos que também podem injetar medição: ' . implode(', ', $plugins) . '.',
                'acao' => 'Cada um deles pode estar colando pixel próprio. Vale conferir antes de duplicar aqui.',
            );
        }

        $relatorio = array(
            'quando' => time(),
            'erro' => null,
            'pixels_na_pagina' => $na_pagina,
            'nosso_pixel' => $nosso_pixel,
            'pixel_total' => (int) $total_pixel,
            'pixel_do_connector' => (int) $assinadas,
            'pixel_de_fora' => (int) $de_fora,
            'gtm' => $gtm_id,
            'plugins' => $plugins,
            'alertas' => $alertas,
        );
        update_option(self::OPTION_RELATORIO, $relatorio, false);
        return $relatorio;
    }

    public static function relatorio() {
        $r = get_option(self::OPTION_RELATORIO, array());
        return is_array($r) ? $r : array();
    }

    /**
     * Guarda contra dobrar o pixel: se a última varredura achou uma cópia
     * colada fora do connector, o injetor fica quieto até alguém remover.
     *
     * O critério é "existe cópia de fora", não "existem duas no total" — senão
     * o guarda se desligaria assim que suprimisse a nossa, a página voltaria a
     * ter uma só, e ele religaria em ciclo.
     */
    public function evitar_duplicata($deve) {
        $r = self::relatorio();
        return empty($r['pixel_de_fora']) ? $deve : false;
    }

    // ─────────────────────────────────────────────────────────
    // Interface
    // ─────────────────────────────────────────────────────────

    public function scan_manual($post) {
        if (empty($post['scan_pixel'])) return;
        $r = $this->verificar();
        $n = isset($r['alertas']) ? count($r['alertas']) : 0;
        add_settings_error(
            'adspirit_connector_pixel_conflito',
            'scan',
            $n === 0
                ? 'Verificação feita: nenhuma duplicata de pixel encontrada.'
                : sprintf('Verificação feita: %d ponto(s) pra olhar.', $n),
            $n === 0 ? 'updated' : 'error'
        );
    }

    public function render_painel() {
        $r = self::relatorio();
        $alertas = isset($r['alertas']) ? $r['alertas'] : array();
        $quando = isset($r['quando']) ? (int) $r['quando'] : 0;

        $selo = $quando
            ? sprintf('<span class="as-badge">Verificado há %s</span>', esc_html(human_time_diff($quando, time())))
            : '<span class="as-badge">Nunca verificado</span>';

        AdSpirit_Menu::card_open(
            'Pixel duplicado',
            'Site que já mede há anos costuma ter pixel colado em mais de um lugar. Aqui a gente olha a home e avisa quando há mais de uma fonte, ou quando o pixel da página não é o mesmo que o AdSpirit usa.',
            $selo
        );

        if (!empty($r['erro'])) {
            echo '<div class="as-notice danger"><p><strong>Não deu pra ler a home:</strong> ' . esc_html($r['erro']) . '</p></div>';
        }

        if ($quando && !$alertas && empty($r['erro'])) {
            echo '<div class="as-notice"><p>Nenhuma duplicata encontrada na última verificação.</p></div>';
        }

        foreach ($alertas as $a) {
            $classe = $a['nivel'] === 'erro' ? 'danger' : ($a['nivel'] === 'aviso' ? 'warning' : '');
            echo '<div class="as-notice ' . esc_attr($classe) . '">';
            echo '<p><strong>' . esc_html($a['texto']) . '</strong><br>';
            echo '<span class="as-field-help">' . esc_html($a['acao']) . '</span></p>';
            echo '</div>';
        }

        AdSpirit_Menu::form_open('connection');
        ?>
        <input type="hidden" name="scan_pixel" value="1">
        <p class="submit" style="margin-top:0;">
            <button type="submit" class="button">Verificar agora</button>
            <span class="as-field-help" style="margin-left:10px;">
                Roda sozinho uma vez por dia. Use o botão depois de mexer em tag ou plugin de medição.
            </span>
        </p>
        </form>
        <?php
        AdSpirit_Menu::card_close();
    }
}
