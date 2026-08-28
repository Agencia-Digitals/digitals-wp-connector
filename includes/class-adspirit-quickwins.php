<?php
/**
 * AdSpirit Connector — Quick wins (v1.4 → v1.5):
 *   1. Auto-update: checa releases do GitHub (sem precisar plugin-update-checker
 *      externo — implementação minimal direto)
 *   2. Test event: botão na Visão geral que POSTa lead mock pro CRM
 *   3. Reverse text trap: anti-spam camada 6 (entropia + sem palavras PT-BR)
 *   4. Email de saúde: wp_cron diário que alerta se 0 leads em 24h
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Quickwins {
    const UPDATE_TRANSIENT = 'adspirit_connector_update_check';
    const UPDATE_INTERVAL = 21600; // 6h
    const HEALTH_CRON_HOOK = 'adspirit_connector_health_check';
    const GITHUB_REPO = 'Agencia-Digitals/digitals-wp-connector';

    private static $instance = null;
    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // 1. Auto-update
        // Connector 3.0 (Pedro 2026-08-18): o plugin se auto-opta no
        // auto-update NATIVO do WP — a frota atrasava crônico (sites 8
        // versões atrás) porque ninguém clica em "Atualizar". Passa pelo
        // pipeline padrão do WP (respeita host que desliga auto-updates);
        // escape hatch via filtro adspirit_connector_auto_update.
        add_filter('auto_update_plugin',
            AdSpirit_Safe_Hook::filter(array($this, 'opt_in_auto_update'), 'qw_auto_update'), 10, 2);
        add_filter('pre_set_site_transient_update_plugins',
            AdSpirit_Safe_Hook::filter(array($this, 'check_for_update'), 'qw_update_check'));
        // 1b. Atualizou ESTE plugin → derruba o cache da release na hora,
        // pra checagem pós-update já oferecer a próxima se existir.
        add_action('upgrader_process_complete',
            AdSpirit_Safe_Hook::action(array($this, 'flush_update_cache_after_upgrade'), 'qw_upgrade_flush'), 10, 2);
        add_filter('plugins_api',
            AdSpirit_Safe_Hook::filter(array($this, 'plugin_info'), 'qw_plugin_info'), 10, 3);
        // 1c. Checksum do pacote: quando o manifest do CRM declara sha256,
        // o zip baixado é verificado ANTES de instalar. Mismatch aborta o
        // update (WP_Error) — a versão instalada fica intacta.
        add_filter('upgrader_pre_download',
            AdSpirit_Safe_Hook::filter(array($this, 'verify_package_checksum'), 'qw_checksum'), 10, 3);

        // 2. Test event button — registrado como admin-post
        add_action('admin_post_adspirit_send_test_event',
            AdSpirit_Safe_Hook::action(array($this, 'send_test_event'), 'qw_test_event'));

        // 2b. Force update check (zera transient + dispara re-check WP)
        add_action('admin_post_adspirit_force_update_check',
            AdSpirit_Safe_Hook::action(array($this, 'force_update_check'), 'qw_force_check'));

        // 4. Email health cron
        add_action('init', AdSpirit_Safe_Hook::action(array($this, 'schedule_cron'), 'qw_schedule'));
        add_action(self::HEALTH_CRON_HOOK,
            AdSpirit_Safe_Hook::action(array($this, 'run_health_check'), 'qw_health_check'));
    }

    // ========== 1. AUTO-UPDATE via GitHub Releases ==========

    /**
     * Auto-opta ESTE plugin no auto-update do WP (aprovado Pedro 2026-08-18).
     * Só toca o próprio basename — nunca muda a decisão pros outros plugins.
     */
    public function opt_in_auto_update($update, $item) {
        if (is_object($item) && isset($item->plugin)
            && $item->plugin === plugin_basename(ADSPIRIT_CONNECTOR_FILE)) {
            return (bool) apply_filters('adspirit_connector_auto_update', true);
        }
        return $update;
    }

    /** Logo do plugin pra tela de Plugins / Atualizações / modal de detalhes. */
    private function icon_urls() {
        $svg = ADSPIRIT_CONNECTOR_URL . 'assets/adspirit-mark.svg';
        return array('svg' => $svg, '1x' => $svg, '2x' => $svg, 'default' => $svg);
    }

    /** upgrader_process_complete: se o pacote atualizado inclui este plugin,
     *  esvazia o cache interno da release. */
    public function flush_update_cache_after_upgrade($upgrader, $hook_extra) {
        if (!is_array($hook_extra) || ($hook_extra['type'] ?? '') !== 'plugin') return;
        $me = plugin_basename(ADSPIRIT_CONNECTOR_FILE);
        $plugins = (array) ($hook_extra['plugins'] ?? array());
        if (!empty($hook_extra['plugin'])) $plugins[] = $hook_extra['plugin'];
        if (in_array($me, $plugins, true)) {
            delete_transient(self::UPDATE_TRANSIENT);
        }
    }

    public function check_for_update($transient) {
        if (empty($transient->checked)) return $transient;

        $plugin_basename = plugin_basename(ADSPIRIT_CONNECTOR_FILE);
        $current = ADSPIRIT_CONNECTOR_VERSION;

        $cached = get_transient(self::UPDATE_TRANSIENT);
        $fetched_at = is_array($cached) ? (int) ($cached['fetched_at'] ?? 0) : 0;
        $has_newer = is_array($cached) && !empty($cached['version'])
            && version_compare($cached['version'], $current, '>');
        // Cache SEM update pra oferecer (pós-update, ou instalada já é a
        // última) envelhece em 15 min — releases publicadas em sequência
        // aparecem na checagem seguinte, sem o "atualizar duas vezes".
        // O cache de 6h só vale enquanto ele ainda oferece algo.
        if (!$has_newer && (time() - $fetched_at) > 900) {
            $cached = false;
        }
        if ($cached === false) {
            $fresh = $this->fetch_best_release();
            $cached = is_array($fresh) ? $fresh : array();
            $cached['fetched_at'] = time(); // falha de rede vira backoff de 15 min
            set_transient(self::UPDATE_TRANSIENT, $cached, self::UPDATE_INTERVAL);
        }
        if (empty($cached['version'])) return $transient;

        if (version_compare($cached['version'], $current, '>')) {
            $transient->response[$plugin_basename] = (object) array(
                'slug' => 'adspirit-connector',
                'plugin' => $plugin_basename,
                'new_version' => $cached['version'],
                'url' => $cached['url'],
                'package' => $cached['zip'],
                'tested' => '6.7',
                'requires_php' => '7.4',
                'icons' => $this->icon_urls(),
            );
        }
        return $transient;
    }

    public function plugin_info($result, $action, $args) {
        if ($action !== 'plugin_information') return $result;
        if (empty($args->slug) || $args->slug !== 'adspirit-connector') return $result;

        $cached = get_transient(self::UPDATE_TRANSIENT);
        if (!$cached) $cached = $this->fetch_best_release();
        if (!$cached) return $result;

        return (object) array(
            'name' => 'AdSpirit Connector',
            'slug' => 'adspirit-connector',
            'version' => $cached['version'],
            'tested' => '6.7',
            'requires_php' => '7.4',
            'author' => 'Agência Digitals',
            'homepage' => 'https://crm.agenciadigitals.com.br',
            'short_description' => 'Conecta o site WP ao CRM AdSpirit. CF7 real-time, anti-spam, CAPI, GA4, multi-step, telemetria.',
            'sections' => array(
                'description' => 'Plugin oficial da Agência Digitals.',
                'changelog' => $cached['body'] ?? '',
            ),
            'icons' => $this->icon_urls(),
            'download_link' => $cached['zip'],
        );
    }

    /**
     * Connector 3.0 — fonte dupla de update. Consulta o manifest hospedado
     * no CRM E o GitHub, e oferece A MAIOR versão das duas. Por quê max e
     * não prioridade: manifest defasado nunca mascara release novo do
     * GitHub, e GitHub fora do ar/rate-limited nunca trava a frota. Quando
     * o repo virar privado, o manifest vira a única fonte viva — e o ritual
     * de release passa a exigir sync do zip+manifest no CRM.
     */
    private function fetch_best_release() {
        $github = $this->fetch_latest_release();
        $crm    = $this->fetch_crm_manifest();
        if (!is_array($github)) return $crm;
        if (!is_array($crm)) return $github;
        return version_compare((string) $crm['version'], (string) $github['version'], '>') ? $crm : $github;
    }

    /**
     * Manifest de update servido pelo próprio CRM ({endpoint}/downloads/
     * manifest.json). Formato: {version, package, url, changelog}. Retorna
     * o mesmo shape do fetch_latest_release() ou null (fail-soft).
     */
    private function fetch_crm_manifest() {
        $core = AdSpirit_Settings::get_core();
        if (empty($core['endpoint_url'])) return null;
        $url = rtrim((string) $core['endpoint_url'], '/') . '/downloads/manifest.json';
        $resp = wp_remote_get($url, array(
            'timeout' => 8,
            'headers' => array('User-Agent' => 'AdSpirit-Connector/' . ADSPIRIT_CONNECTOR_VERSION),
        ));
        if (is_wp_error($resp)) return null;
        if ((int) wp_remote_retrieve_response_code($resp) !== 200) return null;
        $data = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($data) || empty($data['version']) || empty($data['package'])) return null;
        return array(
            'version' => ltrim((string) $data['version'], 'v'),
            'url'     => (string) ($data['url'] ?? $core['endpoint_url']),
            'zip'     => (string) $data['package'],
            'body'    => (string) ($data['changelog'] ?? ''),
            // v2.30: checksum do zip (opcional no manifest). Com ele, o
            // download é verificado antes de instalar — vital com auto-update
            // na frota. Sem o campo, comportamento idêntico ao de sempre.
            'sha256'  => strtolower(trim((string) ($data['sha256'] ?? ''))),
        );
    }

    private function fetch_latest_release() {
        $url = 'https://api.github.com/repos/' . self::GITHUB_REPO . '/releases/latest';
        $resp = wp_remote_get($url, array(
            'timeout' => 8,
            'headers' => array('User-Agent' => 'AdSpirit-Connector/' . ADSPIRIT_CONNECTOR_VERSION),
        ));
        if (is_wp_error($resp)) return null;
        $code = wp_remote_retrieve_response_code($resp);
        if ($code !== 200) return null;
        $body = wp_remote_retrieve_body($resp);
        $data = json_decode($body, true);
        if (!is_array($data) || empty($data['tag_name'])) return null;

        $version = ltrim((string) $data['tag_name'], 'v');
        $zip = '';
        if (!empty($data['assets']) && is_array($data['assets'])) {
            foreach ($data['assets'] as $asset) {
                if (!empty($asset['browser_download_url']) && stripos((string) $asset['name'], '.zip') !== false) {
                    $zip = (string) $asset['browser_download_url'];
                    break;
                }
            }
        }
        return array(
            'version' => $version,
            'url' => (string) ($data['html_url'] ?? ''),
            'zip' => $zip ?: ((string) ($data['zipball_url'] ?? '')),
            'body' => (string) ($data['body'] ?? ''),
        );
    }

    /**
     * upgrader_pre_download: se o pacote é o NOSSO zip e temos sha256 do
     * manifest, baixa e confere o hash. Retorno false = WP baixa normal
     * (qualquer outro plugin, ou manifest sem checksum — fail-open).
     */
    public function verify_package_checksum($reply, $package, $upgrader = null, $hook_extra = null) {
        if ($reply !== false) return $reply; // outro filtro já resolveu
        if (!is_string($package) || $package === '') return $reply;
        $cached = get_transient(self::UPDATE_TRANSIENT);
        if (!is_array($cached) || empty($cached['sha256']) || empty($cached['zip'])) return $reply;
        if ($package !== $cached['zip']) return $reply;

        if (!function_exists('download_url')) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
        }
        $file = download_url($package, 300);
        if (is_wp_error($file)) return $file;
        $hash = strtolower((string) hash_file('sha256', $file));
        if ($hash !== $cached['sha256']) {
            @unlink($file);
            return new WP_Error('adspirit_checksum_mismatch', sprintf(
                'AdSpirit Connector: checksum do pacote não confere (esperado %s…, veio %s…). Update abortado — a versão instalada segue intacta.',
                substr($cached['sha256'], 0, 12), substr($hash, 0, 12)
            ));
        }
        return $file;
    }

    // ========== 1b. FORCE UPDATE CHECK ==========

    /**
     * Limpa o transient interno + transients WP de updates e dispara
     * wp_update_plugins() pra forçar re-check imediato (sem esperar
     * o ciclo de 6h). Redireciona pra tela de Atualizações.
     */
    public function force_update_check() {
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) wp_die('forbidden', 403);
        check_admin_referer('adspirit_force_update_check');

        // Limpa o cache interno do plugin
        delete_transient(self::UPDATE_TRANSIENT);
        // Limpa o cache do WP (forçar re-check do pre_set_site_transient_update_plugins)
        delete_site_transient('update_plugins');
        // Dispara o re-check imediatamente
        if (function_exists('wp_update_plugins')) {
            wp_update_plugins();
        }
        // Redireciona pra tela de atualizações do WP
        wp_safe_redirect(admin_url('update-core.php'));
        exit;
    }

    // ========== 2. TEST EVENT ==========

    public function send_test_event() {
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) wp_die('forbidden', 403);
        check_admin_referer('adspirit_send_test_event');

        $core = AdSpirit_Settings::get_core();
        if (empty($core['brand_slug']) || empty($core['secret'])) {
            wp_safe_redirect(add_query_arg(array(
                'page' => AdSpirit_Menu::PAGE_SLUG,
                'tab' => 'overview',
                'test_result' => 'config_incompleta',
            ), admin_url('admin.php')));
            exit;
        }

        $payload = array(
            'your-name' => '[TESTE] AdSpirit Connector',
            'your-email' => 'teste-adspirit-' . time() . '@example.com',
            'Telefone' => '11999999999',
            'empresa' => 'Plugin Test',
            'cf7_time' => current_time('c'),
            'cf7_url' => home_url('/'),
            '_adspirit_test' => '1', // flag pro CRM arquivar automaticamente
        );

        $endpoint = rtrim($core['endpoint_url'], '/') . '/api/webhooks/contact-form-7';
        $response = wp_remote_post($endpoint, array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type' => 'application/json',
                'x-brand-slug' => $core['brand_slug'],
                'x-cf7-secret' => $core['secret'],
                'x-cf7-submission-id' => 'test-' . time() . '-' . wp_generate_password(6, false),
                'User-Agent' => 'AdSpirit-Connector/' . ADSPIRIT_CONNECTOR_VERSION,
            ),
            'body' => wp_json_encode($payload),
        ));

        $code = is_wp_error($response) ? 0 : wp_remote_retrieve_response_code($response);
        $result = is_wp_error($response) ? 'erro' : ($code === 200 ? 'sucesso' : ('http_' . $code));

        wp_safe_redirect(add_query_arg(array(
            'page' => AdSpirit_Menu::PAGE_SLUG,
            'tab' => 'overview',
            'test_result' => $result,
        ), admin_url('admin.php')));
        exit;
    }

    // ========== 4. HEALTH CRON ==========

    public function schedule_cron() {
        if (!wp_next_scheduled(self::HEALTH_CRON_HOOK)) {
            // Roda diário às 9h horário do WP
            wp_schedule_event(strtotime('tomorrow 9am'), 'daily', self::HEALTH_CRON_HOOK);
        }
    }

    public function run_health_check() {
        // Só alerta se cron diário SEM lead nas últimas 24h, em dia útil.
        $now_day = (int) wp_date('N'); // 1=segunda, 7=domingo — fuso do SITE (B11)
        if ($now_day >= 6) return; // pula fim de semana

        $log = get_option(AdSpirit_Cf7_Handler::LOG_KEY, array());
        if (!is_array($log)) $log = array();
        $window = time() - 86400;
        $sent = 0;
        foreach ($log as $entry) {
            if (empty($entry['at'])) continue;
            $ts = strtotime($entry['at']);
            if ($ts && $ts >= $window && ($entry['status'] ?? '') === 'sent') $sent++;
        }
        if ($sent > 0) return; // tudo certo

        // Throttle: 1 email por 24h
        $last = (int) get_option('adspirit_health_email_last', 0);
        if (time() - $last < 86400) return;
        update_option('adspirit_health_email_last', time(), false);

        $to = get_option('admin_email');
        $site = get_bloginfo('name');
        $subject = '[AdSpirit] Nenhum lead enviado em 24h em ' . $site;
        $body = "Olá,\n\n"
              . "O plugin AdSpirit Connector em '$site' não registrou nenhuma submissão de formulário enviada com sucesso nas últimas 24 horas.\n\n"
              . "Pode ser normal (dia calmo) ou pode indicar problema. Confira:\n"
              . "- Painel: " . admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG) . "\n"
              . "- Aba Logs pra ver dispatches e erros\n"
              . "- Teste manual em Visão geral → Testar conexão\n\n"
              . "Se quiser desligar esse alerta, vá no painel AdSpirit → Visão geral.\n\n"
              . "— AdSpirit Connector v" . ADSPIRIT_CONNECTOR_VERSION;

        wp_mail($to, $subject, $body);
    }

    // ========== 3. REVERSE TEXT TRAP (registrado externamente) ==========

    /**
     * Detecta texto suspeito: alta entropia + sem stopwords PT-BR.
     * Usado pelo anti-spam como camada 6.
     */
    /**
     * Texto que parece gerado por robô: sem nenhuma palavra comum do
     * português e com variedade de caracteres alta demais pra prosa.
     *
     * O piso de 12 caracteres era baixo demais e produziu FALSO POSITIVO em
     * lead de verdade: "Bruno Lopes 38991764098" tem 23 caracteres, nenhuma
     * stopword (nome e telefone não têm) e variedade alta — a regra dizia
     * bot. Aconteceu com um lead perfil A, e o envio parcial dele foi
     * barrado. Um nome com um telefone do lado é a situação MAIS comum de
     * um formulário, não a mais suspeita.
     *
     * Duas travas: só julga texto com corpo de frase (60+ caracteres), e
     * exige variedade bem mais alta. Quem chama também precisa mandar só
     * campo de texto livre — ver a chamada no anti-spam.
     */
    public static function is_suspicious_text($text) {
        $text = trim((string) $text);
        if ($text === '') return false;
        // Abaixo disso não há prosa pra julgar: é nome, telefone, empresa.
        if (mb_strlen($text) < 60) return false;
        // Stopwords PT-BR comuns — qualquer uma presente = humano OK
        $stopwords = array(
            ' de ', ' do ', ' da ', ' dos ', ' das ', ' e ', ' o ', ' a ', ' os ', ' as ',
            ' para ', ' com ', ' que ', ' um ', ' uma ', ' por ', ' me ', ' nos ',
            ' meu ', ' minha ', ' seu ', ' sua ', ' este ', ' esse ', ' aquele ',
            'gostaria', 'preciso', 'queria', 'tenho', 'somos', 'estamos',
        );
        $lower = ' ' . strtolower($text) . ' ';
        foreach ($stopwords as $w) {
            if (strpos($lower, $w) !== false) return false;
        }
        // Entropia simples: chars únicos / length
        $chars = mb_str_split(strtolower($text));
        $unique = count(array_unique($chars));
        $entropy_ratio = $unique / max(1, count($chars));
        // Prosa em português repete muita letra; 0.55 ainda pegava texto
        // humano curto e legítimo. Acima de 0.72 já é quase só string
        // aleatória.
        return $entropy_ratio > 0.72;
    }
}
