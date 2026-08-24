<?php
/**
 * AdSpirit Connector — monitor das fontes de dados deste site.
 *
 * Responde, por fonte, as três perguntas que um diagnóstico precisa:
 *
 *   1. CONECTADA?  temos credencial válida, e de onde ela veio
 *   2. FUNCIONAL?  a última verificação ATIVA passou
 *   3. GERANDO?    quanto dado saiu daqui, e quando foi o último
 *
 * Por que verificação ATIVA. O envio pro Meta e pro GA4 é fire-and-forget
 * (`blocking => false`) pra não atrasar o submit do formulário — decisão
 * certa, já que capturar o lead vem antes de tudo. O preço é que ninguém lê
 * a resposta: um token revogado falha em silêncio por semanas.
 *
 * Então o monitor não espera o tráfego real provar nada. Ele bate na API de
 * cada fonte com uma chamada barata e de leitura, guarda o resultado, e é
 * isso que a tela mostra.
 *
 * REGRA: fonte que não dá pra verificar é reportada como NÃO VERIFICÁVEL,
 * com o motivo. Nunca como saudável. Um verde falso num painel de
 * diagnóstico é pior que não ter painel — porque desliga a suspeita.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Fontes {

    const OPTION_CHECKS = 'adspirit_connector_fontes_check';
    const CRON          = 'adspirit_connector_fontes_scan';
    const TTL_CHECK     = 21600; // 6h — credencial não muda de hora em hora

    // Veredito de cada fonte.
    const OK          = 'ok';
    const FALHA       = 'falha';
    const SEM_CONFIG  = 'sem_config';
    const DESLIGADA   = 'desligada';
    const NAO_TESTAVEL = 'nao_testavel';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('init', AdSpirit_Safe_Hook::action(array($this, 'agendar'), 'fontes_agendar'));
        add_action(self::CRON, AdSpirit_Safe_Hook::action(array($this, 'verificar_todas'), 'fontes_cron'));
        add_action('admin_post_adspirit_fontes_scan',
            AdSpirit_Safe_Hook::action(array($this, 'scan_manual'), 'fontes_scan_manual'));
    }

    public function agendar() {
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 600, 'twicedaily', self::CRON);
        }
    }

    public static function unschedule() {
        wp_clear_scheduled_hook(self::CRON);
    }

    // ─────────────────────────────────────────────────────────
    // Verificação ativa
    // ─────────────────────────────────────────────────────────

    /** Roda todas e guarda. Retorna o relatório. */
    public function verificar_todas() {
        $rel = array(
            'em' => time(),
            'meta'     => self::checar_meta(),
            'ga4'      => self::checar_ga4(),
            'adspirit' => self::checar_adspirit(),
        );
        update_option(self::OPTION_CHECKS, $rel, false);
        return $rel;
    }

    public function scan_manual() {
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) wp_die('Sem permissão.');
        check_admin_referer('adspirit_fontes_scan');
        $this->verificar_todas();
        wp_safe_redirect(add_query_arg(
            array('page' => AdSpirit_Menu::PAGE_SLUG, 'tab' => 'medicao', 'fontes' => 'ok'),
            admin_url('admin.php')
        ));
        exit;
    }

    /** Último relatório; roda uma vez se nunca rodou ou se venceu. */
    public static function relatorio($forcar = false) {
        $rel = get_option(self::OPTION_CHECKS, array());
        $velho = !is_array($rel) || empty($rel['em']) || (time() - (int) $rel['em']) > self::TTL_CHECK;
        if ($forcar || $velho) {
            $rel = self::instance()->verificar_todas();
        }
        return is_array($rel) ? $rel : array();
    }

    /**
     * Meta: lê o próprio pixel pelo Graph. Chamada de LEITURA — não cria
     * evento, não suja o dado do cliente.
     *
     * O que dá pra concluir daqui é menos do que parece, e o veredito é
     * calibrado nisso (ver os casos dentro da função): 190 prova que o
     * token morreu; 100 é o comportamento NORMAL de um token de CAPI e não
     * prova nada contra ele.
     */
    private static function checar_meta() {
        $cfg = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_capi_meta() : array();
        if (($cfg['enabled'] ?? '0') !== '1') return array('estado' => self::DESLIGADA);
        $pixel = trim((string) ($cfg['pixel_id'] ?? ''));
        $token = trim((string) ($cfg['access_token'] ?? ''));
        if ($pixel === '' || $token === '') {
            return array('estado' => self::SEM_CONFIG, 'detalhe' => 'Falta o ID do pixel ou o token.');
        }
        $url = 'https://graph.facebook.com/v22.0/' . rawurlencode($pixel)
             . '?fields=name&access_token=' . rawurlencode($token);
        $resp = wp_remote_get($url, array('timeout' => 8));
        if (is_wp_error($resp)) {
            return array('estado' => self::FALHA, 'detalhe' => 'Não deu pra falar com a Meta: ' . $resp->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        $body = json_decode((string) wp_remote_retrieve_body($resp), true);
        if ($code === 200) {
            return array('estado' => self::OK, 'detalhe' => 'Pixel ' . $pixel
                . (!empty($body['name']) ? ' (' . $body['name'] . ')' : '') . ' respondeu.');
        }
        $err = isset($body['error']['code']) ? (int) $body['error']['code'] : 0;
        $msg = isset($body['error']['message']) ? (string) $body['error']['message'] : ('HTTP ' . $code);

        // 190 é conclusivo: o token não vale mais.
        if ($err === 190) {
            return array('estado' => self::FALHA,
                'detalhe' => 'O token de acesso expirou ou foi revogado. Gere outro na Meta e atualize no AdSpirit.');
        }

        // 100 "Missing Permission" NÃO é problema. Testado contra as contas
        // reais em 2026-08-24: token de CAPI válido, que envia conversão
        // normalmente, recebe 100 nesta leitura — ele tem permissão de
        // ESCREVER evento, não de LER o cadastro do pixel.
        //
        // A primeira versão desta checagem tratava isso como falha e teria
        // pintado de vermelho duas contas que estão funcionando. Provar que
        // o envio funciona exigiria mandar um evento de verdade pro pixel de
        // produção — o que sujaria o dado do cliente pra responder uma
        // pergunta de diagnóstico. Não vale a troca.
        if ($err === 100) {
            return array('estado' => self::NAO_TESTAVEL,
                'detalhe' => 'O token existe e responde, mas só permite ENVIAR conversão — não deixa '
                    . 'consultar o pixel daqui, o que é o normal pra um token de CAPI. Dá pra afirmar '
                    . 'que ele não foi revogado; confirmar a entrega exige olhar os Eventos de Teste no '
                    . 'Gerenciador da Meta.');
        }
        return array('estado' => self::FALHA, 'detalhe' => $msg);
    }

    /**
     * GA4: usa o endpoint de DEBUG do Measurement Protocol. Ele valida
     * credencial e formato e devolve os problemas — sem registrar o evento
     * na propriedade, então testar não polui relatório.
     */
    private static function checar_ga4() {
        $cfg = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_ga4() : array();
        if (($cfg['enabled'] ?? '0') !== '1') return array('estado' => self::DESLIGADA);
        $mid = trim((string) ($cfg['measurement_id'] ?? ''));
        $sec = trim((string) ($cfg['api_secret'] ?? ''));
        if ($mid === '' || $sec === '') {
            return array('estado' => self::SEM_CONFIG, 'detalhe' => 'Falta o ID de medição ou o segredo da API.');
        }
        $url = 'https://www.google-analytics.com/debug/mp/collect?measurement_id='
             . rawurlencode($mid) . '&api_secret=' . rawurlencode($sec);
        $resp = wp_remote_post($url, array(
            'timeout' => 8,
            'headers' => array('Content-Type' => 'application/json'),
            'body'    => wp_json_encode(array(
                'client_id' => 'adspirit.healthcheck',
                'events'    => array(array('name' => 'health_check', 'params' => new stdClass())),
            )),
        ));
        if (is_wp_error($resp)) {
            return array('estado' => self::FALHA, 'detalhe' => 'Não deu pra falar com o Google: ' . $resp->get_error_message());
        }
        $body = json_decode((string) wp_remote_retrieve_body($resp), true);
        $msgs = isset($body['validationMessages']) && is_array($body['validationMessages'])
            ? $body['validationMessages'] : array();
        if (empty($msgs)) {
            return array('estado' => self::OK, 'detalhe' => 'O Google aceitou o envio de teste em ' . $mid . '.');
        }
        $txt = isset($msgs[0]['description']) ? (string) $msgs[0]['description'] : 'Envio recusado.';
        return array('estado' => self::FALHA, 'detalhe' => $txt);
    }

    /** AdSpirit: o próprio CRM responde? Usa o ping que a conexão já expõe. */
    private static function checar_adspirit() {
        $core = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();
        $base = trim((string) ($core['endpoint_url'] ?? ''));
        $slug = trim((string) ($core['brand_slug'] ?? ''));
        $sec  = trim((string) ($core['secret'] ?? ''));
        if ($base === '' || $slug === '' || $sec === '') {
            return array('estado' => self::SEM_CONFIG, 'detalhe' => 'Site ainda não conectado.');
        }
        $resp = wp_remote_get(
            rtrim($base, '/') . '/api/wp/tracking-config?brand_slug=' . rawurlencode($slug),
            array('timeout' => 8, 'headers' => array('x-cf7-secret' => $sec))
        );
        if (is_wp_error($resp)) {
            return array('estado' => self::FALHA, 'detalhe' => 'O AdSpirit não respondeu: ' . $resp->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($resp);
        if ($code === 200 || $code === 304) {
            return array('estado' => self::OK, 'detalhe' => 'Respondeu como marca ' . $slug . '.');
        }
        if ($code === 401 || $code === 403) {
            return array('estado' => self::FALHA, 'detalhe' => 'A chave deste site foi recusada (HTTP ' . $code . '). Reconecte.');
        }
        return array('estado' => self::FALHA, 'detalhe' => 'O AdSpirit respondeu HTTP ' . $code . '.');
    }

    // ─────────────────────────────────────────────────────────
    // Volume: quanto dado cada fonte gerou
    // ─────────────────────────────────────────────────────────

    /** Leads na janela, e quando foi o último. Null se não há tabela. */
    private static function volume_leads($dias = 30) {
        if (!class_exists('AdSpirit_Lead_Store') || !AdSpirit_Lead_Store::available()) return null;
        global $wpdb;
        $t = AdSpirit_Lead_Store::table_name();
        $desde = gmdate('Y-m-d H:i:s', time() - $dias * DAY_IN_SECONDS);
        $total = (int) $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$t} WHERE created_at >= %s AND source <> 'qualifier_partial'", $desde));
        $ultimo = $wpdb->get_var(
            "SELECT created_at FROM {$t} WHERE source <> 'qualifier_partial' ORDER BY created_at DESC LIMIT 1");
        return array(
            'total' => $total,
            'ultimo' => $ultimo ? strtotime((string) $ultimo . ' UTC') : null,
        );
    }

    /**
     * Quadro completo pra tela: uma linha por fonte, já pronta pra render.
     */
    public static function quadro() {
        $rel  = self::relatorio();
        $core = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();
        $vol  = self::volume_leads();
        $capi = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_capi_meta() : array();
        $ga4  = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_ga4() : array();
        $clar = class_exists('AdSpirit_Clarity') ? AdSpirit_Clarity::get_settings() : array();

        // Quem governa a credencial: o AdSpirit (config-sync) ou este site.
        $geridos = (array) get_option('adspirit_connector_config_geridos', array());
        $origem = function ($chave) use ($geridos) {
            return in_array($chave, $geridos, true)
                ? 'vem do AdSpirit' : 'configurado neste site';
        };

        $linhas = array();

        $linhas[] = array(
            'nome'    => 'AdSpirit',
            'papel'   => 'Recebe os leads e a jornada de cada visitante.',
            'check'   => $rel['adspirit'] ?? array('estado' => self::NAO_TESTAVEL),
            'credencial' => !empty($core['brand_slug']) ? 'marca ' . $core['brand_slug'] : null,
            'origem'  => 'conexão do site',
            'volume'  => $vol === null ? null : array(
                'numero' => $vol['total'],
                'rotulo' => 'lead' . ($vol['total'] === 1 ? '' : 's') . ' em 30 dias',
                'ultimo' => $vol['ultimo'],
            ),
        );

        $linhas[] = array(
            'nome'    => 'Meta',
            'papel'   => 'Recebe as conversões pelo servidor (CAPI), pra otimizar os anúncios.',
            'check'   => $rel['meta'] ?? array('estado' => self::NAO_TESTAVEL),
            'credencial' => !empty($capi['pixel_id']) ? 'pixel ' . $capi['pixel_id'] : null,
            'origem'  => $origem('meta'),
            'volume'  => null, // envio é fire-and-forget: não há contagem confiável
            'nota_volume' => 'O envio não espera resposta pra não atrasar o formulário, '
                . 'então não dá pra contar entregas aqui. A verificação acima é que garante a conexão.',
        );

        $linhas[] = array(
            'nome'    => 'Google Analytics 4',
            'papel'   => 'Recebe as conversões pelo servidor, junto com o que o site já mede.',
            'check'   => $rel['ga4'] ?? array('estado' => self::NAO_TESTAVEL),
            'credencial' => !empty($ga4['measurement_id']) ? $ga4['measurement_id'] : null,
            'origem'  => $origem('ga4'),
            'volume'  => null,
            'nota_volume' => 'Mesmo caso da Meta: envio sem espera de resposta.',
        );

        $proj = trim((string) ($clar['project_id'] ?? ''));
        $linhas[] = array(
            'nome'    => 'Microsoft Clarity',
            'papel'   => 'Grava sessões e mapas de calor das páginas.',
            'check'   => $proj === ''
                ? array('estado' => self::SEM_CONFIG, 'detalhe' => 'Sem projeto configurado.')
                : array('estado' => self::NAO_TESTAVEL,
                        'detalhe' => 'A Clarity não oferece um jeito de verificar isso daqui. '
                                   . 'O script está sendo injetado; confirme no painel dela se as sessões chegam.'),
            'credencial' => $proj !== '' ? 'projeto ' . $proj : null,
            'origem'  => $origem('clarity'),
            'volume'  => null,
        );

        return array('linhas' => $linhas, 'verificado_em' => $rel['em'] ?? null);
    }

    /** Rótulo curto do veredito. */
    public static function rotulo($estado) {
        switch ($estado) {
            case self::OK:           return 'Conectada e respondendo';
            case self::FALHA:        return 'Com problema';
            case self::SEM_CONFIG:   return 'Não configurada';
            case self::DESLIGADA:    return 'Desligada';
            case self::NAO_TESTAVEL: return 'Não dá pra verificar daqui';
            default:                 return 'Desconhecido';
        }
    }

    /** Cor do veredito, no vocabulário do design system. */
    public static function tom($estado) {
        switch ($estado) {
            case self::OK:         return 'ok';
            case self::FALHA:      return 'danger';
            case self::SEM_CONFIG: return 'warn';
            default:               return 'muted';
        }
    }
}
