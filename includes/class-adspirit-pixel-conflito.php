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

    /** Trecho que a última varredura apontou como a cópia colada à mão. */
    private $trecho_pra_desligar = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('init', AdSpirit_Safe_Hook::action(array($this, 'agendar'), 'pixel_conflito_agendar'));
        add_action(self::CRON, AdSpirit_Safe_Hook::action(array($this, 'verificar'), 'pixel_conflito_cron'));

        // Botão manual + painel na tab Conexão.
        add_action(
            'adspirit_connector_save_medicao',
            AdSpirit_Safe_Hook::action(array($this, 'scan_manual'), 'pixel_conflito_save')
        );
        add_action(
            'adspirit_connector_save_medicao',
            AdSpirit_Safe_Hook::action(array($this, 'desligar_trecho'), 'pixel_conflito_desligar')
        );
        add_action(
            'adspirit_connector_render_tab_medicao',
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
     * Procura o pixel dentro dos "gerenciadores de código" — Code Snippets e
     * WPCode. É onde o pixel mais costuma estar colado à mão, e é o lugar que
     * ninguém lembra de olhar: não é o tema, não é um plugin de pixel, é um
     * trecho solto que alguém salvou há dois anos.
     *
     * Dizer o NOME do trecho é o que transforma o alerta em coisa acionável:
     * "está no snippet DigitalsOS Tag" resolve em trinta segundos; "procure no
     * site" vira meia hora de caçada.
     */
    private function snippets_com_pixel() {
        global $wpdb;
        $achados = array();

        $core = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();
        $nosso_token = trim((string) ($core['pixel_token'] ?? ''));
        $nosso_endpoint = trim((string) ($core['endpoint_url'] ?? ''));
        $nosso_endpoint = $nosso_endpoint ? parse_url($nosso_endpoint, PHP_URL_HOST) : '';

        $tabelas = array(
            $wpdb->prefix . 'snippets' => array('Code Snippets', 'name', 'code', 'active'),
            $wpdb->prefix . 'wpcode'   => array('WPCode', 'title', 'code', 'status'),
        );

        foreach ($tabelas as $tabela => $cols) {
            list($rotulo, $col_nome, $col_codigo, $col_ativo) = $cols;
            // Nome de tabela não vem de input do usuário — sai do prefixo do
            // próprio WordPress — mas confirma que existe antes de consultar.
            if ($wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $tabela)) !== $tabela) continue;

            $linhas = $wpdb->get_results(
                "SELECT `id`, `{$col_nome}` AS nome, `{$col_ativo}` AS ativo, `{$col_codigo}` AS codigo
                   FROM `{$tabela}`",
                ARRAY_A
            );
            if (!$linhas) continue;

            foreach ($linhas as $l) {
                $codigo = (string) $l['codigo'];
                if (strpos($codigo, 'pixel.js') === false
                    && !preg_match("/fbq\s*\(\s*['\"]init/", $codigo)
                    && strpos($codigo, 'facebook.com/tr') === false) {
                    continue;
                }
                // Aponta pro mesmo lugar que o connector apontaria? Um trecho
                // com o nosso endpoint E o nosso token é equivalente ao que
                // emitiríamos — aí faz sentido ceder. Com host ou token
                // diferente, ele está alimentando outro lugar (ou lugar
                // nenhum), e ceder cegaria a marca.
                $mesmo_destino = false;
                if (strpos($codigo, 'pixel.js') !== false) {
                    $mesmo_destino = $nosso_token
                        && $nosso_endpoint
                        && strpos($codigo, $nosso_token) !== false
                        && strpos($codigo, $nosso_endpoint) !== false;
                }

                $achados[] = array(
                    'origem' => $rotulo,
                    'tabela' => $tabela,
                    'id' => isset($l['id']) ? (int) $l['id'] : 0,
                    'coluna_ativo' => $col_ativo,
                    'nome' => (string) $l['nome'],
                    'ativo' => (bool) $l['ativo'],
                    'nosso' => strpos($codigo, 'pixel.js') !== false,
                    'mesmo_destino' => $mesmo_destino,
                );
            }
        }

        return $achados;
    }

    /**
     * Lê o pixel que os plugins conhecidos têm guardado. Saber o ID muda o
     * aviso de "pode estar injetando algo" para "está injetando exatamente o
     * mesmo pixel que você" — que é a diferença entre uma nota e um erro.
     */
    private function pixels_configurados_em_plugins() {
        $achados = array();

        // PixelYourSite (grátis e pro compartilham a option).
        $pys = get_option('pys_facebook', array());
        if (is_array($pys) && !empty($pys['pixel_id'])) {
            $id = is_array($pys['pixel_id']) ? reset($pys['pixel_id']) : $pys['pixel_id'];
            if ($id) $achados['PixelYourSite'] = (string) $id;
        }

        // Plugin oficial da Meta.
        $fb = get_option('facebook_config', array());
        if (is_array($fb) && !empty($fb['pixel_id'])) {
            $achados['Meta Pixel (plugin oficial)'] = (string) $fb['pixel_id'];
        }
        $fbwc = get_option('wc_facebook_pixel_id', '');
        if ($fbwc) $achados['Facebook for WooCommerce'] = (string) $fbwc;

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

        // O contêiner do GTM raramente aparece como URL: o trecho oficial monta
        // o endereço em JavaScript. Procurar só por `gtm.js?id=` faz a gente
        // não achar GTM em site que tem — e aí um item coberto pelo Tag Manager
        // é reportado como "ausente", que é justamente a leitura errada que
        // esta varredura existe pra evitar. Procura o identificador solto.
        $tem_gtm = (bool) preg_match('/\b(GTM-[A-Z0-9]{4,})\b/', $html, $mg);
        $gtm_id = $tem_gtm ? $mg[1] : '';

        // O que mais está medindo nesta página, venha de onde vier. A tela de
        // conexão precisa disso pra dizer "já existe" em vez de "não
        // configurado" — que era a leitura errada que o painel dava.
        preg_match_all('/\b(G-[A-Z0-9]{8,})\b/', $html, $mga);
        preg_match_all("/gtag\s*\(\s*['\"]config['\"]\s*,\s*['\"](G-[A-Z0-9]+)['\"]/", $html, $mgb);
        $ga4_na_pagina = array_values(array_unique(array_merge($mga[1], $mgb[1])));

        preg_match_all('/clarity\.ms\/tag\/([a-z0-9]+)/', $html, $mc);
        $clarity_na_pagina = array_values(array_unique($mc[1]));

        preg_match_all('/\b(AW-[0-9]{6,})\b/', $html, $maw);
        $google_ads_na_pagina = array_values(array_unique($maw[1]));

        $capi = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_capi_meta() : array();
        $nosso_pixel = isset($capi['pixel_id']) ? trim((string) $capi['pixel_id']) : '';

        $alertas = array();
        $no_estudio = defined('ADSPIRIT_PERFIL') && ADSPIRIT_PERFIL === 'estudio';

        // Onde o trecho colado à mão mora, quando dá pra saber.
        $snippets = $this->snippets_com_pixel();
        $onde_esta = '';
        $destino_diferente = false;
        $trecho_pra_desligar = null;
        foreach ($snippets as $sn) {
            if (!$sn['ativo'] || !$sn['nosso']) continue;
            $onde_esta = sprintf('%s, no trecho "%s"', $sn['origem'], $sn['nome']);
            $destino_diferente = empty($sn['mesmo_destino']);
            $trecho_pra_desligar = $sn;
            break;
        }
        $this->trecho_pra_desligar = $trecho_pra_desligar;

        // 1. Pixel do AdSpirit colado fora do connector.
        //
        // Este é o caso que vai aparecer em escala quando o connector chegar
        // nos sites antigos: o pixel já estava colado à mão (ou por plugin) e
        // ninguém percebe que o connector passou a colar de novo.
        if ($de_fora > 0) {
            $alertas[] = array(
                'nivel' => 'erro',
                'texto' => $assinadas > 0
                    ? sprintf('O pixel do AdSpirit aparece %d vezes na home: %d pelo connector e %d colada à mão. Cada visita conta em dobro.', $total_pixel, $assinadas, $de_fora)
                    : sprintf('Há %d pixel(s) do AdSpirit colado(s) fora do connector nesta página.', $de_fora),
                'acao' => $onde_esta
                    ? ($destino_diferente
                        ? 'Está em ' . $onde_esta . ', e aponta pra um destino que não é o atual — provavelmente sobrou de uma configuração antiga. O connector continua injetando a dele pra não deixar a marca sem medição, mas apague essa aí: enquanto existir, cada visita conta duas vezes.'
                        : 'Está em ' . $onde_esta . '. Desative ou apague de lá — enquanto a cópia existir, o connector não injeta a dele, pra não contar em dobro.')
                    : 'Procure no tema, num bloco de código do builder ou num plugin de headers. Enquanto a cópia de fora existir, o connector para de injetar a dele — melhor não medir do que medir em dobro.',
            );
        }

        // 2. Ligado nas configurações, mas ausente da página. No estúdio isso é
        //    esperado: o site ainda não foi aprovado, não é hora de medir.
        if (!$no_estudio && $total_pixel === 0) {
            $core = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();
            if (($core['pixel_enabled'] ?? '0') === '1') {
                $alertas[] = array(
                    'nivel' => 'aviso',
                    'texto' => 'O pixel está ligado nas configurações, mas não apareceu na home.',
                    'acao' => 'Pode ser cache de página servindo uma cópia antiga, ou o tema não estar chamando wp_head(). Limpe o cache e verifique de novo.',
                );
            }
        }

        // 3. O pixel que o AdSpirit usa no CAPI está repetido na página.
        //
        // Duplicata do NOSSO é erro. Pixel de terceiro é tratado logo abaixo
        // (3b) — continua não sendo erro, mas deixou de ser ignorado.
        $vezes_nosso = 0;
        $outros_ids  = array();
        foreach (array_merge($m1[1], $m2[1]) as $id) {
            if ($nosso_pixel && $id === $nosso_pixel) { $vezes_nosso++; continue; }
            if ($id !== '' && !in_array($id, $outros_ids, true)) $outros_ids[] = $id;
        }

        // 3b. Pixel de OUTRO id na página, junto com o nosso CAPI.
        //
        // Antes isto era ignorado de propósito ("pixel de terceiro é comum e
        // legítimo"). É verdade que ter um não é erro — mas a combinação
        // importa e ninguém enxergava: o navegador reporta pro pixel que está
        // na página, o servidor reporta pro pixel do CAPI, e se os dois ids
        // forem diferentes NÃO HÁ DEDUPLICAÇÃO. Os dois contam a mesma
        // conversão, e nenhum fica com a história completa.
        //
        // Por isso o alerta não diz "apague": diz o que confirmar. Um pixel
        // de parceiro pode ser intencional — o que não pode é ser o nosso
        // sem a gente saber.
        if (!empty($outros_ids)) {
            $lista = implode(', ', array_slice($outros_ids, 0, 4));
            if ($nosso_pixel && $vezes_nosso > 0) {
                $alertas[] = array(
                    'nivel' => 'aviso',
                    'texto' => sprintf(
                        'Além do pixel %s (o que o AdSpirit usa), a página tem outro pixel da Meta: %s.',
                        $nosso_pixel, $lista
                    ),
                    'acao' => 'Se o outro for de um parceiro, tudo bem — deixe como está. Se for da própria '
                        . 'marca, cada visita está sendo contada nos dois e nenhum dos dois vê a jornada inteira.',
                );
            } elseif ($nosso_pixel) {
                $alertas[] = array(
                    'nivel' => 'erro',
                    'texto' => sprintf(
                        'A página tem o pixel %s, mas o AdSpirit envia as conversões pro pixel %s. São diferentes.',
                        $lista, $nosso_pixel
                    ),
                    'acao' => 'Sem o mesmo id nos dois lados a deduplicação não acontece: o navegador conta '
                        . 'num pixel e o servidor no outro, então a mesma conversão aparece duas vezes e o '
                        . 'Meta otimiza em cima de número inflado. Iguale o id do CAPI ao que está na página, '
                        . 'ou troque o da página pelo do CAPI.',
                );
            } else {
                $alertas[] = array(
                    'nivel' => 'aviso',
                    'texto' => sprintf('A página tem pixel da Meta (%s), mas o AdSpirit não tem CAPI configurado.', $lista),
                    'acao' => 'Configurando o CAPI com esse mesmo id, as conversões passam a ser enviadas '
                        . 'também pelo servidor — o que sobrevive a bloqueador e a iOS.',
                );
            }
        }
        // O <noscript> legítimo repete o mesmo id do script: 2 ocorrências é o
        // normal de UMA instalação. Duplicata de verdade começa em 3.
        if ($nosso_pixel && $vezes_nosso > 2) {
            $alertas[] = array(
                'nivel' => 'erro',
                'texto' => sprintf('O pixel %s, que é o que o AdSpirit usa, aparece %d vezes na página.', $nosso_pixel, $vezes_nosso),
                'acao' => 'Duas fontes estão colando o mesmo pixel — provavelmente o connector e um plugin ou tag que já existia. Deixe uma só.',
            );
        }

        // 4. O CAPI mira num pixel que não está na página.
        //
        // Aqui sim há dano: o servidor reporta pra um pixel que o navegador não
        // alimenta, então não há evento de browser pra parear e a deduplicação
        // não acontece.
        if ($nosso_pixel && $vezes_nosso === 0) {
            $alertas[] = array(
                'nivel' => $tem_gtm ? 'aviso' : 'erro',
                'texto' => sprintf('O AdSpirit envia conversões pro pixel %s, que não apareceu na página.', $nosso_pixel),
                'acao' => $tem_gtm
                    ? 'Pode ser que o Tag Manager injete esse pixel — daqui não dá pra ver. Confirme no Tag Assistant; se o contêiner usa outro pixel, acerte para que os dois lados usem o mesmo.'
                    : 'Sem evento de navegador no mesmo pixel não há o que parear, e o servidor conta sozinho. Coloque o mesmo pixel na página, ou corrija o ID no AdSpirit.',
            );
        }

        // 5. Outros pixels na página — informação, não problema.
        $terceiros = array();
        foreach ($na_pagina as $id) {
            if ($nosso_pixel && $id === $nosso_pixel) continue;
            $terceiros[] = $id;
        }
        $terceiros = array_values(array_unique($terceiros));
        if ($terceiros) {
            $alertas[] = array(
                'nivel' => 'nota',
                'texto' => sprintf(
                    'Há %s na página que o AdSpirit não gerencia: %s.',
                    count($terceiros) === 1 ? 'outro pixel da Meta' : 'outros pixels da Meta',
                    implode(', ', $terceiros)
                ),
                'acao' => $nosso_pixel
                    ? 'Normal quando existe pixel de parceiro ou de agência anterior. Só vira problema se algum deles for, na verdade, o pixel oficial da marca — aí é ele que devia estar cadastrado aqui.'
                    : 'Se um destes é o pixel oficial da marca, cadastre o mesmo ID no AdSpirit pra que as conversões do servidor cheguem no lugar certo.',
            );
        }

        // 5b. Trechos de código soltos que injetam medição.
        foreach ($snippets as $sn) {
            if ($sn['nosso']) continue; // já coberto pelo alerta 1
            $alertas[] = array(
                'nivel' => $sn['ativo'] ? 'aviso' : 'nota',
                'texto' => sprintf(
                    '%s: o trecho "%s"%s injeta medição.',
                    $sn['origem'], $sn['nome'], $sn['ativo'] ? '' : ' (desativado)'
                ),
                'acao' => $sn['ativo']
                    ? 'Confira se ele não está colando o mesmo pixel que o AdSpirit usa. Trecho solto é o esconderijo mais comum de pixel esquecido.'
                    : 'Está desativado, então não dispara. Só não se esqueça dele se alguém religar.',
            );
        }

        // 6. Tag Manager — fonte que não dá pra inspecionar daqui.
        if ($tem_gtm) {
            $alertas[] = array(
                'nivel' => 'nota',
                'texto' => sprintf('Este site tem um contêiner do Tag Manager (%s).', $gtm_id),
                'acao' => 'O que o Tag Manager injeta só aparece depois do JavaScript rodar, então esta verificação não enxerga. Confira as tags de pixel lá dentro antes de ligar a injeção pelo AdSpirit.',
            );
        }

        // 7. Plugins que injetam pixel — com o ID quando dá pra ler.
        $plugins = $this->plugins_suspeitos();
        $pixels_de_plugin = $this->pixels_configurados_em_plugins();
        if ($pixels_de_plugin) {
            foreach ($pixels_de_plugin as $origem => $id) {
                $mesmo = $nosso_pixel && $id === $nosso_pixel;
                $alertas[] = array(
                    'nivel' => $mesmo ? 'erro' : 'nota',
                    'texto' => $mesmo
                        ? sprintf('%s está configurado com o pixel %s — o mesmo que o AdSpirit usa.', $origem, $id)
                        : sprintf('%s está configurado com o pixel %s.', $origem, $id),
                    'acao' => $mesmo
                        ? 'Os dois vão injetar o mesmo pixel e cada visita conta em dobro. Escolha quem cuida: desligue a injeção nesse plugin ou no AdSpirit.'
                        : 'Pixel diferente do que o AdSpirit usa. Só confira se é proposital.',
                );
            }
        } elseif ($plugins) {
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
            // Amostra do HTML pra AdSpirit_Deteccao trabalhar sem baixar a
            // home de novo. Cortada: só o <head> e o começo do corpo importam,
            // e guardar a página inteira incharia wp_options.
            'html_amostra' => mb_substr($html, 0, 220000),
            'ga4_na_pagina' => $ga4_na_pagina,
            'clarity_na_pagina' => $clarity_na_pagina,
            'google_ads_na_pagina' => $google_ads_na_pagina,
            'meta_na_pagina' => $na_pagina,
            'plugins' => $plugins,
            'snippets' => $snippets,
            'trecho_pra_desligar' => $trecho_pra_desligar,
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
        if (empty($r['pixel_de_fora'])) return $deve;

        // Ceder só faz sentido quando a cópia de fora aponta pro MESMO destino
        // que a nossa apontaria — aí ela é equivalente e duplicar seria contar
        // duas vezes. Quando o destino é outro (host antigo, token que não
        // existe mais), ceder deixa a marca sendo medida por uma tag morta.
        // Achado no dev em 2026-08-21: era exatamente esse o caso.
        foreach ((array) ($r['snippets'] ?? array()) as $sn) {
            if (!empty($sn['ativo']) && !empty($sn['nosso']) && empty($sn['mesmo_destino'])) {
                return $deve; // a de fora não serve; a nossa continua
            }
        }
        return false;
    }

    // ─────────────────────────────────────────────────────────
    // Interface
    // ─────────────────────────────────────────────────────────

    /**
     * Desliga o trecho que a varredura identificou. Só age sobre a linha que o
     * próprio relatório apontou — não aceita id vindo do formulário — pra que
     * um POST forjado não consiga desativar qualquer coisa do site.
     */
    public function desligar_trecho($post) {
        if (empty($post['desligar_trecho'])) return;
        if (!current_user_can('manage_options')) return;

        $r = self::relatorio();
        $alvo = isset($r['trecho_pra_desligar']) ? $r['trecho_pra_desligar'] : null;
        if (!is_array($alvo) || empty($alvo['id']) || empty($alvo['tabela'])) {
            add_settings_error('adspirit_connector_pixel_conflito', 'sem_alvo',
                'Não há trecho identificado pra desligar. Rode a verificação primeiro.', 'error');
            return;
        }

        global $wpdb;
        $ok = $wpdb->update(
            $alvo['tabela'],
            array($alvo['coluna_ativo'] => 0),
            array('id' => (int) $alvo['id']),
            array('%d'),
            array('%d')
        );

        if ($ok === false) {
            add_settings_error('adspirit_connector_pixel_conflito', 'falhou',
                'Não consegui desligar o trecho. Desative pelo painel do ' . esc_html($alvo['origem']) . '.', 'error');
            return;
        }

        wp_cache_flush();
        // Purga o cache de página, senão a home continua servindo a versão com
        // as duas tags e a próxima varredura acusa problema que já não existe.
        if (function_exists('do_action')) do_action('litespeed_purge_all');
        $this->verificar();

        add_settings_error('adspirit_connector_pixel_conflito', 'desligado',
            sprintf('Trecho "%s" desativado. O connector voltou a cuidar do pixel.', esc_html($alvo['nome'])),
            'updated');
    }

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

        $alvo = isset($r['trecho_pra_desligar']) ? $r['trecho_pra_desligar'] : null;
        if (is_array($alvo) && !empty($alvo['id'])) {
            AdSpirit_Menu::form_open('medicao');
            echo '<input type="hidden" name="desligar_trecho" value="1">';
            echo '<p class="submit" style="margin-top:0;">';
            echo '<button type="submit" class="button button-primary">Desligar o trecho "'
                . esc_html($alvo['nome']) . '"</button>';
            echo '<span class="as-field-help" style="margin-left:10px;">Desativa em '
                . esc_html($alvo['origem']) . ' e devolve o pixel ao connector. Dá pra religar lá se precisar.</span>';
            echo '</p></form>';
        }

        AdSpirit_Menu::form_open('medicao');
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
