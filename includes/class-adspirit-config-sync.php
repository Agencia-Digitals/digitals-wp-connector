<?php
/**
 * AdSpirit Connector — Configuração vinda do AdSpirit.
 *
 * O time conecta as contas uma vez no AdSpirit. Daqui pra frente é o site que
 * vai buscar a identidade de medição — pixel da Meta, GA4, Clarity, Google
 * Ads, domínios da jornada — e se configura sozinho. Ninguém cola ID em
 * wp-admin, e um site novo já nasce medindo certo.
 *
 * Puxa GET /api/wp/tracking-config?brand_slug=X com o mesmo x-cf7-secret que
 * o connect já entregou. Manda no pedido o carimbo que tem em cache; se nada
 * mudou o CRM responde 304 e nada é reescrito.
 *
 * O que chega do AdSpirit manda. Os campos correspondentes ficam travados na
 * interface — quem precisa mexer, muda na origem. Sites que ainda não migraram
 * podem soltar o controle com o filtro `adspirit_config_sync_ativo`.
 *
 * Storage:
 *   - adspirit_connector_config_carimbo: config_updated_at do último apply
 *   - adspirit_connector_config_sync_at: unix time da última tentativa OK
 *   - adspirit_connector_config_sync_error: última mensagem de erro
 *   - adspirit_connector_config_geridos: blocos que o AdSpirit está governando
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Config_Sync {

    const OPTION_CARIMBO = 'adspirit_connector_config_carimbo';
    const OPTION_SYNC_AT = 'adspirit_connector_config_sync_at';
    const OPTION_SYNC_ERR = 'adspirit_connector_config_sync_error';
    const OPTION_GERIDOS = 'adspirit_connector_config_geridos';
    const OPTION_MODO = 'adspirit_connector_config_modo';
    /** Saúde da medição vista pelo AdSpirit (último evento, volume 30d). */
    const OPTION_SAUDE = 'adspirit_connector_medicao_saude';
    const OPTION_COMPARACAO = 'adspirit_connector_config_comparacao';
    const CRON = 'adspirit_connector_config_sync';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    /** Desligar devolve o controle dos campos pro wp-admin do site. */
    public static function ativo() {
        return (bool) apply_filters('adspirit_config_sync_ativo', true);
    }

    /**
     * 'observando' = olha e relata, não escreve nada.
     * 'aplicando'  = o AdSpirit manda nos campos que ele conhece.
     *
     * O padrão é decidido UMA vez, na primeira execução, pelo estado do site:
     * site que já tinha medição configurada entra observando, porque ali há o
     * que perder e alguém precisa olhar antes. Site novo, sem nada, entra
     * aplicando — não há risco de apagar o que não existe.
     *
     * É essa escolha que torna seguro atualizar o plugin em site antigo: a
     * atualização não muda medição nenhuma até um humano aprovar.
     */
    public static function modo() {
        $m = get_option(self::OPTION_MODO, '');
        if ($m === 'observando' || $m === 'aplicando') return $m;
        return 'observando'; // sem decisão gravada, o seguro é olhar
    }

    private function definir_modo_inicial() {
        if (get_option(self::OPTION_MODO, '') !== '') return;
        $site = $this->estado_do_site();
        $tem_algo = false;
        foreach ($site as $campo => $v) {
            if ($campo === 'pixel_token') continue; // o token vem do connect, não conta
            if ($v !== '') { $tem_algo = true; break; }
        }
        update_option(self::OPTION_MODO, $tem_algo ? 'observando' : 'aplicando', false);
    }

    private function __construct() {
        // Assim que o site conecta, já busca a config — o dev não precisa
        // voltar pra preencher nada.
        add_action(
            'adspirit_connector_connected',
            AdSpirit_Safe_Hook::action(array($this, 'depois_de_conectar'), 'config_sync_connect')
        );

        add_action('init', AdSpirit_Safe_Hook::action(array($this, 'agendar'), 'config_sync_agendar'));
        add_action(self::CRON, AdSpirit_Safe_Hook::action(array($this, 'buscar'), 'config_sync_cron'));

        // Botão manual na tab Conexão.
        add_action(
            'adspirit_connector_save_medicao',
            AdSpirit_Safe_Hook::action(array($this, 'sync_manual'), 'config_sync_save')
        );
        add_action(
            'adspirit_connector_save_medicao',
            AdSpirit_Safe_Hook::action(array($this, 'assumir'), 'config_sync_assumir')
        );
        add_action(
            'adspirit_connector_save_medicao',
            AdSpirit_Safe_Hook::action(array($this, 'observar'), 'config_sync_observar')
        );
        add_action(
            'adspirit_connector_render_tab_medicao',
            AdSpirit_Safe_Hook::action(array($this, 'render_painel'), 'config_sync_render'),
            5
        );
    }

    public function agendar() {
        if (!wp_next_scheduled(self::CRON)) {
            wp_schedule_event(time() + 300, 'hourly', self::CRON);
        }
    }

    public function depois_de_conectar() {
        $this->buscar();
    }

    // ─────────────────────────────────────────────────────────
    // Busca e aplicação
    // ─────────────────────────────────────────────────────────

    /**
     * Vai no CRM e aplica. Retorna 'aplicado', 'sem-mudanca' ou false.
     */
    public function buscar($forcar = false) {
        if (!self::ativo()) return false;

        $core = AdSpirit_Settings::get_core();
        $endpoint = isset($core['endpoint_url']) ? (string) $core['endpoint_url'] : '';
        $brand = isset($core['brand_slug']) ? (string) $core['brand_slug'] : '';
        $secret = isset($core['secret']) ? (string) $core['secret'] : '';

        if (!$endpoint || !$brand || !$secret) {
            update_option(self::OPTION_SYNC_ERR, 'Site ainda não conectado ao AdSpirit.', false);
            return false;
        }

        $url = rtrim($endpoint, '/') . '/api/wp/tracking-config?brand_slug=' . rawurlencode($brand);
        $carimbo_local = (string) get_option(self::OPTION_CARIMBO, '');
        if (!$forcar && $carimbo_local !== '') {
            $url .= '&since=' . rawurlencode($carimbo_local);
        }

        $resposta = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'x-cf7-secret' => $secret,
                'x-brand-slug' => $brand,
                'User-Agent'   => 'AdSpirit-Connector/' . (defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : 'unknown'),
                'Accept'       => 'application/json',
            ),
        ));

        if (is_wp_error($resposta)) {
            update_option(self::OPTION_SYNC_ERR, 'Erro de rede: ' . $resposta->get_error_message(), false);
            return false;
        }

        $codigo = (int) wp_remote_retrieve_response_code($resposta);

        if ($codigo === 304) {
            update_option(self::OPTION_SYNC_AT, time(), false);
            update_option(self::OPTION_SYNC_ERR, '', false);
            return 'sem-mudanca';
        }

        $dados = json_decode((string) wp_remote_retrieve_body($resposta), true);

        if ($codigo !== 200 || !is_array($dados) || empty($dados['tracking'])) {
            $erro = (is_array($dados) && !empty($dados['error'])) ? $dados['error'] : ('HTTP ' . $codigo);
            update_option(self::OPTION_SYNC_ERR, 'AdSpirit recusou: ' . $erro, false);
            return false;
        }

        $this->definir_modo_inicial();
        // O AdSpirit conta se os eventos estão chegando — o site dispara e
        // não recebe resposta, então sozinho ele nunca saberia que parou.
        // Guardado mesmo em modo observação: é leitura, não configuração.
        if (isset($dados['medicao_saude']) && is_array($dados['medicao_saude'])) {
            update_option(self::OPTION_SAUDE, array(
                'ultimo_evento_em' => (string) ($dados['medicao_saude']['ultimo_evento_em'] ?? ''),
                'eventos_30d' => (int) ($dados['medicao_saude']['eventos_30d'] ?? 0),
                'lido_em' => time(),
            ), false);
        }

        $comparacao = $this->comparar($dados['tracking']);
        update_option(self::OPTION_COMPARACAO, $comparacao, false);

        if (self::modo() !== 'aplicando') {
            // Observando: registra o que aconteceria e sai sem tocar em nada.
            // Não grava o carimbo — assim, quando alguém aprovar, a próxima
            // busca traz a config inteira em vez de um 304.
            update_option(self::OPTION_SYNC_AT, time(), false);
            update_option(self::OPTION_SYNC_ERR, '', false);
            return 'observando';
        }

        $geridos = $this->aplicar($dados['tracking']);

        update_option(self::OPTION_CARIMBO, sanitize_text_field((string) ($dados['config_updated_at'] ?? '')), false);
        update_option(self::OPTION_GERIDOS, $geridos, false);
        update_option(self::OPTION_SYNC_AT, time(), false);
        update_option(self::OPTION_SYNC_ERR, '', false);

        return 'aplicado';
    }

    /**
     * O que o site tem hoje, campo a campo, no vocabulário do AdSpirit.
     */
    private function estado_do_site() {
        $core = AdSpirit_Settings::get_core();
        $capi = AdSpirit_Settings::get_capi_meta();
        $ga4 = AdSpirit_Settings::get_ga4();
        $clarity = class_exists('AdSpirit_Clarity') ? AdSpirit_Clarity::get_settings() : array();
        return array(
            'pixel_token' => trim((string) ($core['pixel_token'] ?? '')),
            'meta_pixel' => trim((string) ($capi['pixel_id'] ?? '')),
            'ga4' => trim((string) ($ga4['measurement_id'] ?? '')),
            'clarity' => trim((string) ($clarity['project_id'] ?? '')),
        );
    }

    /** Os mesmos campos, como o AdSpirit os conhece. */
    private function estado_do_adspirit($t) {
        return array(
            'pixel_token' => trim((string) ($t['pixel_token'] ?? '')),
            'meta_pixel' => trim((string) ($t['meta']['pixel_id'] ?? '')),
            'ga4' => trim((string) ($t['ga4']['measurement_id'] ?? '')),
            'clarity' => trim((string) ($t['clarity']['project_id'] ?? '')),
        );
    }

    private static function rotulo($campo) {
        $r = array(
            'pixel_token' => 'Pixel do AdSpirit',
            'meta_pixel' => 'Pixel da Meta',
            'ga4' => 'Google Analytics 4',
            'clarity' => 'Microsoft Clarity',
        );
        return isset($r[$campo]) ? $r[$campo] : $campo;
    }

    /**
     * Compara os dois lados e diz o que aconteceria. É o que a tela mostra em
     * modo observação, e é o que decide o que pode ser escrito em modo ativo.
     *
     * Três situações que importam:
     *   igual     — nada a fazer.
     *   adotar    — o site tem, o AdSpirit não. O valor do site é a verdade e
     *               precisa subir; NUNCA apagamos o que já funciona.
     *   trocar    — os dois têm, e são diferentes. Só aí o AdSpirit manda.
     *   preencher — o AdSpirit tem, o site não. Escrita segura.
     */
    /**
     * A medição parou de chegar no AdSpirit?
     *
     * Devolve os dias sem evento, ou null quando não dá pra afirmar: site
     * que nunca mandou evento (instalação nova) e leitura velha demais não
     * são "parou", são "não sei" — e alarme por não saber é o que faz o
     * painel perder credibilidade.
     *
     * O corte é 3 dias porque a marca pode ter pausado campanha no fim de
     * semana; menos que isso acenderia toda segunda de manhã.
     */
    public static function dias_sem_evento() {
        $s = get_option(self::OPTION_SAUDE, array());
        if (!is_array($s) || empty($s['ultimo_evento_em'])) return null;
        // Leitura de mais de 2 dias: o próprio sync está parado, e o número
        // que temos não diz nada sobre agora.
        if (empty($s['lido_em']) || (time() - (int) $s['lido_em']) > 2 * DAY_IN_SECONDS) return null;
        // Site que nunca mediu não "parou de medir".
        if ((int) ($s['eventos_30d'] ?? 0) === 0) return null;

        $ts = strtotime((string) $s['ultimo_evento_em']);
        if (!$ts) return null;
        $dias = (int) floor((time() - $ts) / DAY_IN_SECONDS);
        return $dias >= 3 ? $dias : null;
    }

    private function comparar($t) {
        $site = $this->estado_do_site();
        $crm = $this->estado_do_adspirit($t);
        $linhas = array();
        foreach ($site as $campo => $valor_site) {
            $valor_crm = isset($crm[$campo]) ? $crm[$campo] : '';
            if ($valor_site === $valor_crm) {
                $situacao = 'igual';
            } elseif ($valor_site !== '' && $valor_crm === '') {
                $situacao = 'adotar';
            } elseif ($valor_site === '' && $valor_crm !== '') {
                $situacao = 'preencher';
            } else {
                $situacao = 'trocar';
            }
            $linhas[] = array(
                'campo' => $campo,
                'rotulo' => self::rotulo($campo),
                'no_site' => $valor_site,
                'no_adspirit' => $valor_crm,
                'situacao' => $situacao,
            );
        }
        return $linhas;
    }

    /**
     * Escreve nas mesmas options que as telas do plugin já usam — assim os
     * módulos de medição não precisam saber que a config veio de fora.
     *
     * REGRA DURA: campo vazio no AdSpirit NUNCA apaga valor que existe no
     * site. Vazio ali significa "o AdSpirit não cuida disso", não "desligue".
     * Sem essa regra, instalar o connector num site que já media apagaria a
     * medição dele em silêncio — o pior tipo de erro, porque nada quebra e
     * ninguém percebe até o relatório do mês vir vazio.
     */
    private function aplicar($t) {
        $geridos = array();
        $comparacao = $this->comparar($t);
        $por_campo = array();
        foreach ($comparacao as $l) $por_campo[$l['campo']] = $l;

        $pode_escrever = function ($campo) use ($por_campo) {
            $s = isset($por_campo[$campo]) ? $por_campo[$campo]['situacao'] : 'igual';
            return $s === 'preencher' || $s === 'trocar';
        };

        // Pixel próprio (first-party) — token e liga/desliga.
        if ($pode_escrever('pixel_token')) {
            AdSpirit_Settings::update_core(array(
                'pixel_token'   => $por_campo['pixel_token']['no_adspirit'],
                'pixel_enabled' => !empty($t['pixel_ativo']) ? '1' : '0',
            ));
            $geridos[] = 'pixel';
        }

        // Meta — pixel do navegador e CAPI usam o mesmo ID.
        if ($pode_escrever('meta_pixel')) {
            AdSpirit_Settings::update_capi_meta(array(
                'pixel_id'     => $por_campo['meta_pixel']['no_adspirit'],
                'access_token' => (string) ($t['meta']['access_token'] ?? ''),
                'enabled'      => !empty($t['meta']['capi_ativo']) ? '1' : '0',
            ));
            $geridos[] = 'meta';
        }

        // GA4.
        if ($pode_escrever('ga4')) {
            AdSpirit_Settings::update_ga4(array(
                'measurement_id' => $por_campo['ga4']['no_adspirit'],
                'api_secret'     => (string) ($t['ga4']['api_secret'] ?? ''),
                'enabled'        => !empty($t['ga4']['capi_ativo']) ? '1' : '0',
            ));
            $geridos[] = 'ga4';
        }

        // Clarity — o próprio módulo recusa Project ID fora do formato.
        if ($pode_escrever('clarity') && class_exists('AdSpirit_Clarity')) {
            $projeto = $por_campo['clarity']['no_adspirit'];
            AdSpirit_Clarity::update_settings(array(
                'project_id' => $projeto,
                'enabled'    => AdSpirit_Clarity::is_valid_project_id($projeto) ? '1' : '0',
            ));
            $geridos[] = 'clarity';
        }

        // Domínios da jornada — o AdSpirit já sabe quais são; o site só
        // precisa saber pra carimbar o visitante na travessia. Lista vazia
        // também não apaga o que já existe.
        if (isset($t['dominios_vinculados']) && is_array($t['dominios_vinculados'])) {
            $limpos = array();
            foreach ($t['dominios_vinculados'] as $d) {
                $d = sanitize_text_field((string) $d);
                if ($d !== '') $limpos[] = $d;
            }
            if ($limpos) {
                AdSpirit_Settings::update_cross_domain(array(
                    'domains' => implode("\n", $limpos),
                    'enabled' => '1',
                ));
                $geridos[] = 'cross_domain';
            }
        }

        return $geridos;
    }

    // ─────────────────────────────────────────────────────────
    // Consulta pelas telas
    // ─────────────────────────────────────────────────────────

    /** Um bloco governado pelo AdSpirit não se edita aqui. */
    public static function gerido($bloco) {
        if (!self::ativo()) return false;
        $lista = get_option(self::OPTION_GERIDOS, array());
        return is_array($lista) && in_array($bloco, $lista, true);
    }

    public static function sync_at() { return (int) get_option(self::OPTION_SYNC_AT, 0); }

    public static function comparacao() {
        $c = get_option(self::OPTION_COMPARACAO, array());
        return is_array($c) ? $c : array();
    }

    /** Campos que o site tem e o AdSpirit não — precisam subir. */
    public static function para_adotar() {
        $r = array();
        foreach (self::comparacao() as $l) {
            if (($l['situacao'] ?? '') === 'adotar') $r[] = $l;
        }
        return $r;
    }

    public static function erro() {
        $v = get_option(self::OPTION_SYNC_ERR, '');
        return is_string($v) ? $v : '';
    }

    // ─────────────────────────────────────────────────────────
    // Interface
    // ─────────────────────────────────────────────────────────

    /** Sai da observação e deixa o AdSpirit assumir. Decisão explícita. */
    public function assumir($post) {
        if (empty($post['assumir_config'])) return;
        if (!current_user_can('manage_options')) return;
        update_option(self::OPTION_MODO, 'aplicando', false);
        delete_option(self::OPTION_CARIMBO); // força trazer tudo, não um 304
        $r = $this->buscar(true);
        add_settings_error('adspirit_connector_config', 'assumiu',
            $r ? 'O AdSpirit passou a cuidar da medição deste site.'
               : 'Mudei pro modo ativo, mas a busca falhou: ' . esc_html(self::erro()),
            $r ? 'updated' : 'error');
    }

    /** Volta a só observar. Nada do que já foi escrito é desfeito. */
    public function observar($post) {
        if (empty($post['observar_config'])) return;
        if (!current_user_can('manage_options')) return;
        update_option(self::OPTION_MODO, 'observando', false);
        add_settings_error('adspirit_connector_config', 'observando',
            'Voltou pro modo observação. O que já foi escrito continua como está.', 'updated');
    }

    public function sync_manual($post) {
        if (empty($post['sync_config'])) return;
        $r = $this->buscar(true);
        if ($r) {
            add_settings_error(
                'adspirit_connector_config',
                'ok',
                $r === 'aplicado'
                    ? 'Configuração atualizada a partir do AdSpirit.'
                    : 'Configuração já estava em dia com o AdSpirit.',
                'updated'
            );
        } else {
            add_settings_error(
                'adspirit_connector_config',
                'fail',
                'Não foi possível buscar: ' . esc_html(self::erro()),
                'error'
            );
        }
    }

    public function render_painel() {
        if (!class_exists('AdSpirit_Connect') || !AdSpirit_Connect::is_connected()) return;

        $quando = self::sync_at();
        $erro = self::erro();
        $geridos = get_option(self::OPTION_GERIDOS, array());
        $quantos = is_array($geridos) ? count($geridos) : 0;

        $selo = $quando > 0
            ? sprintf('<span class="as-badge">Atualizado há %s · %d ajustes</span>', esc_html(human_time_diff($quando, time())), (int) $quantos)
            : '<span class="as-badge">Ainda não buscado</span>';

        AdSpirit_Menu::card_open(
            'Medição configurada pelo AdSpirit',
            'As contas conectadas no AdSpirit definem o que este site mede: pixel da Meta, GA4, Clarity e os domínios da jornada. O site busca sozinho a cada hora — esses campos ficam travados aqui porque a origem é lá.',
            $selo
        );

        if ($erro) {
            echo '<div class="as-notice danger"><p><strong>Última tentativa falhou:</strong> ' . esc_html($erro) . '</p></div>';
        }

        $modo = self::modo();
        $comparacao = self::comparacao();

        if ($modo === 'observando') {
            echo '<div class="as-notice warning"><p><strong>Modo observação.</strong> '
               . 'Nada neste site foi alterado. A tabela abaixo mostra o que aconteceria '
               . 'se o AdSpirit assumisse — confira antes de decidir.</p></div>';
        }

        if ($comparacao) {
            echo '<table class="widefat striped" style="margin-bottom:12px"><thead><tr>'
               . '<th>Campo</th><th>Neste site</th><th>No AdSpirit</th><th>O que acontece</th>'
               . '</tr></thead><tbody>';
            foreach ($comparacao as $l) {
                $explica = array(
                    'igual' => 'Nada muda.',
                    'adotar' => 'O site tem, o AdSpirit não. Fica como está — cadastre esse valor no AdSpirit.',
                    'preencher' => 'O AdSpirit preenche o que falta aqui.',
                    'trocar' => 'Valores diferentes. O do AdSpirit passa a valer.',
                );
                $sit = $l['situacao'];
                echo '<tr><td><strong>' . esc_html($l['rotulo']) . '</strong></td>'
                   . '<td><code>' . esc_html($l['no_site'] !== '' ? $l['no_site'] : '—') . '</code></td>'
                   . '<td><code>' . esc_html($l['no_adspirit'] !== '' ? $l['no_adspirit'] : '—') . '</code></td>'
                   . '<td>' . esc_html(isset($explica[$sit]) ? $explica[$sit] : $sit) . '</td></tr>';
            }
            echo '</tbody></table>';
        }

        AdSpirit_Menu::form_open('medicao');
        if ($modo === 'observando') {
            echo '<input type="hidden" name="assumir_config" value="1">';
            echo '<p class="submit" style="margin-top:0;">'
               . '<button type="submit" class="button button-primary">Deixar o AdSpirit assumir</button>'
               . '<span class="as-field-help" style="margin-left:10px;">Só depois disso o plugin escreve algo. '
               . 'Valor que só existe aqui nunca é apagado.</span></p></form>';
        } else {
            echo '<input type="hidden" name="observar_config" value="1">';
            echo '<p class="submit" style="margin-top:0;">'
               . '<button type="submit" class="button">Voltar a só observar</button>'
               . '<span class="as-field-help" style="margin-left:10px;">Para de escrever. '
               . 'O que já foi escrito continua como está.</span></p></form>';
        }

        AdSpirit_Menu::form_open('medicao');
        ?>
        <input type="hidden" name="sync_config" value="1">
        <p class="submit" style="margin-top:0;">
            <button type="submit" class="button button-primary">Buscar configuração agora</button>
            <span class="as-field-help" style="margin-left:10px;">
                Use depois de conectar uma conta nova no AdSpirit, pra não esperar a próxima hora.
            </span>
        </p>
        </form>
        <?php
        AdSpirit_Menu::card_close();
    }
}
