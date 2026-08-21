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
            'adspirit_connector_save_connection',
            AdSpirit_Safe_Hook::action(array($this, 'sync_manual'), 'config_sync_save')
        );
        add_action(
            'adspirit_connector_render_tab_connection',
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

        $geridos = $this->aplicar($dados['tracking']);

        update_option(self::OPTION_CARIMBO, sanitize_text_field((string) ($dados['config_updated_at'] ?? '')), false);
        update_option(self::OPTION_GERIDOS, $geridos, false);
        update_option(self::OPTION_SYNC_AT, time(), false);
        update_option(self::OPTION_SYNC_ERR, '', false);

        return 'aplicado';
    }

    /**
     * Escreve nas mesmas options que as telas do plugin já usam — assim os
     * módulos de medição não precisam saber que a config veio de fora.
     *
     * Campo vazio no AdSpirit desliga o módulo em vez de deixar rodando com
     * um ID antigo que ninguém lembra de onde veio.
     */
    private function aplicar($t) {
        $geridos = array();

        // Pixel próprio (first-party) — token e liga/desliga.
        $token = isset($t['pixel_token']) ? sanitize_text_field((string) $t['pixel_token']) : '';
        if ($token !== '') {
            AdSpirit_Settings::update_core(array(
                'pixel_token'   => $token,
                'pixel_enabled' => !empty($t['pixel_ativo']) ? '1' : '0',
            ));
            $geridos[] = 'pixel';
        }

        // Meta — pixel do navegador e CAPI usam o mesmo ID.
        if (isset($t['meta']) && is_array($t['meta'])) {
            $pixel_id = sanitize_text_field((string) ($t['meta']['pixel_id'] ?? ''));
            AdSpirit_Settings::update_capi_meta(array(
                'pixel_id'     => $pixel_id,
                'access_token' => (string) ($t['meta']['access_token'] ?? ''),
                'enabled'      => !empty($t['meta']['capi_ativo']) ? '1' : '0',
            ));
            $geridos[] = 'meta';
        }

        // GA4.
        if (isset($t['ga4']) && is_array($t['ga4'])) {
            AdSpirit_Settings::update_ga4(array(
                'measurement_id' => sanitize_text_field((string) ($t['ga4']['measurement_id'] ?? '')),
                'api_secret'     => (string) ($t['ga4']['api_secret'] ?? ''),
                'enabled'        => !empty($t['ga4']['capi_ativo']) ? '1' : '0',
            ));
            $geridos[] = 'ga4';
        }

        // Clarity — o próprio módulo recusa Project ID fora do formato, então
        // só liga quando o valor passa na validação dele.
        if (isset($t['clarity']) && is_array($t['clarity']) && class_exists('AdSpirit_Clarity')) {
            $projeto = sanitize_text_field((string) ($t['clarity']['project_id'] ?? ''));
            $valido = $projeto !== '' && AdSpirit_Clarity::is_valid_project_id($projeto);
            AdSpirit_Clarity::update_settings(array(
                'project_id' => $projeto,
                'enabled'    => $valido ? '1' : '0',
            ));
            $geridos[] = 'clarity';
        }

        // Domínios da jornada — o AdSpirit já sabe quais são; o site só
        // precisa saber pra carimbar o visitante na travessia.
        if (isset($t['dominios_vinculados']) && is_array($t['dominios_vinculados'])) {
            $limpos = array();
            foreach ($t['dominios_vinculados'] as $d) {
                $d = sanitize_text_field((string) $d);
                if ($d !== '') $limpos[] = $d;
            }
            AdSpirit_Settings::update_cross_domain(array(
                'domains' => implode("\n", $limpos),
                'enabled' => $limpos ? '1' : '0',
            ));
            $geridos[] = 'cross_domain';
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

    public static function erro() {
        $v = get_option(self::OPTION_SYNC_ERR, '');
        return is_string($v) ? $v : '';
    }

    // ─────────────────────────────────────────────────────────
    // Interface
    // ─────────────────────────────────────────────────────────

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

        AdSpirit_Menu::form_open('connection');
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
