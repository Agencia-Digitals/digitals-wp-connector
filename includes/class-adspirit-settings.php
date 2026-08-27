<?php
/**
 * AdSpirit Connector — data layer (sem UI).
 *
 * Centraliza get/save de todas as options do plugin. UI é
 * responsabilidade de cada *_Tab classe. Aqui só persistência +
 * defaults + sanitização.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Settings {
    // 1 row pra config geral. Features grandes (field mapping, anti-spam stats,
    // cf7 log) têm options separadas porque crescem com uso.
    const OPTION_CORE         = 'adspirit_connector_settings';
    const OPTION_CF7_SCOPE    = 'adspirit_connector_cf7_scope';
    const OPTION_FIELD_MAP    = 'adspirit_connector_field_mappings';
    const OPTION_ANTISPAM     = 'adspirit_connector_antispam';
    const OPTION_ANTISPAM_LOG = 'adspirit_connector_antispam_log';
    const OPTION_CAPI_META    = 'adspirit_connector_capi_meta';
    const OPTION_GA4          = 'adspirit_connector_ga4';
    const OPTION_CROSS_DOMAIN = 'adspirit_connector_cross_domain';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action(
            'admin_init',
            AdSpirit_Safe_Hook::action(array($this, 'register_settings'), 'settings_register')
        );
    }

    public static function seed_defaults() {
        if (false === get_option(self::OPTION_CORE)) {
            add_option(self::OPTION_CORE, self::core_defaults());
        }
        if (false === get_option(self::OPTION_ANTISPAM)) {
            add_option(self::OPTION_ANTISPAM, self::antispam_defaults());
        }
        if (false === get_option(self::OPTION_CAPI_META)) {
            add_option(self::OPTION_CAPI_META, self::capi_meta_defaults());
        }
        if (false === get_option(self::OPTION_GA4)) {
            add_option(self::OPTION_GA4, self::ga4_defaults());
        }
        if (false === get_option(self::OPTION_CROSS_DOMAIN)) {
            add_option(self::OPTION_CROSS_DOMAIN, self::cross_domain_defaults());
        }
        if (false === get_option(self::OPTION_FIELD_MAP)) {
            add_option(self::OPTION_FIELD_MAP, array());
        }
        // v2.2 Behavioral tracker — defaults só se classe carregou
        if (class_exists('AdSpirit_Behavioral') && false === get_option('adspirit_connector_behavioral')) {
            add_option('adspirit_connector_behavioral', AdSpirit_Behavioral::defaults());
        }
        // v2.2 Clarity — defaults só se classe carregou
        if (class_exists('AdSpirit_Clarity') && false === get_option(AdSpirit_Clarity::OPTION_CLARITY)) {
            add_option(AdSpirit_Clarity::OPTION_CLARITY, AdSpirit_Clarity::defaults());
        }
    }

    // ─────────────────────────────────────────────────────────
    // CORE: brand slug + secret + endpoint URL + pixel token
    // ─────────────────────────────────────────────────────────
    public static function core_defaults() {
        return array(
            'endpoint_url'  => 'https://crm.agenciadigitals.com.br',
            'brand_slug'    => '',
            'brand_name'    => '',
            'secret'        => '',
            'pixel_token'   => '',
            'cf7_enabled'   => '1',
            // Ligado por padrão (Pedro, 2026-08-24): sem isto metade do
            // AdSpirit fica cega — lead sem origem não entra na conta de
            // campanha. O fluxo de conexão automática já ligava; o default
            // cobre quem conecta colando credenciais na mão.
            'pixel_enabled' => '1',
            // v2.29: servir o pixel do próprio domínio (anti ad-blocker).
            // Sub-opção do pixel; default OFF — opt-in por site.
            // DESLIGADO e travado até o proxy ser consertado. Medido em
            // 2026-08-22: com ele ligado, o pixel.js é servido pelo endereço
            // do site, mas sai SEM o parâmetro `t` — e o script começa com
            // `if (!token) return;`. Ou seja: carrega e não faz nada. Pior,
            // ele monta o destino como `origem-do-script + /api/track`, que
            // no domínio do site não existe. Quem ligou isso parou de medir
            // sem receber nenhum sinal. Ver AdSpirit_Pixel_Proxy.
            // Padrão ligado (Pedro, 2026-08-24): menos visitante perdido
            // por bloqueador. Seguro porque o injetor só usa o modo quando
            // o pixel.js do CRM aceita config — senão cai no tradicional.
            // Instalação já existente mantém o que o usuário escolheu.
            'pixel_firstparty' => '1',
            // Feature 35: preview de lead score no [adspirit_form].
            // Off por default (opt-in) — feature visível só pra brands que
            // querem ativar gamification de qualificação.
            'show_lead_score_preview' => '0',
            // v2.30: coletor genérico (rede de segurança pra form builder
            // desconhecido, padrão HubSpot). Beta, opt-in — nasce desligado.
            'generic_forms_enabled' => '1',
        );
    }

    /**
     * v2.30: segredo pode vir de constante no wp-config.php — fica fora do
     * DB (backup de banco não expõe) e sobrevive a reset de options. A
     * constante definida VENCE o valor salvo na UI. Os update_* mergeiam a
     * partir da OPTION crua (não do getter) justamente pra constante nunca
     * ser persistida no banco.
     */
    public static function secret_from_config($constant, $fallback) {
        if (defined($constant)) {
            $v = constant($constant);
            if (is_string($v) && $v !== '') return $v;
        }
        return $fallback;
    }

    public static function get_core() {
        $stored = get_option(self::OPTION_CORE, array());
        $merged = wp_parse_args($stored, self::core_defaults());
        $merged['secret'] = self::secret_from_config('ADSPIRIT_CRM_SECRET', $merged['secret']);
        $merged['pixel_token'] = self::secret_from_config('ADSPIRIT_PIXEL_TOKEN', $merged['pixel_token']);
        // Defesa: se user colou a URL completa do endpoint (com path
        // /api/webhooks/contact-form-7), strip pra evitar duplicar o path
        // no dispatcher. Salva no DB normalizado pra próxima leitura.
        $normalized = self::normalize_endpoint_url((string) ($merged['endpoint_url'] ?? ''));
        if ($normalized !== $merged['endpoint_url']) {
            $merged['endpoint_url'] = $normalized;
        }
        return $merged;
    }

    /**
     * Aceita "https://crm.x.com" OR "https://crm.x.com/" OR
     * "https://crm.x.com/api/webhooks/contact-form-7" e devolve sempre
     * a base sem trailing slash.
     */
    public static function normalize_endpoint_url($url) {
        $url = trim((string) $url);
        if ($url === '') return '';
        $url = rtrim($url, '/');
        // Strip caminhos comuns que o user pode ter colado por engano
        $paths_to_strip = array(
            '/api/webhooks/contact-form-7',
            '/api/webhooks/contact-form-7/',
            '/api/webhooks',
            '/api',
        );
        foreach ($paths_to_strip as $path) {
            if (substr($url, -strlen($path)) === $path) {
                $url = substr($url, 0, -strlen($path));
                $url = rtrim($url, '/');
                break;
            }
        }
        return $url;
    }

    public static function update_core(array $patch) {
        $current = (array) get_option(self::OPTION_CORE, array());
        update_option(self::OPTION_CORE, array_merge($current, $patch), false);
    }

    // ─────────────────────────────────────────────────────────
    // ESCOPO CF7 (P0-2)
    //   mode 'all' (default, retrocompatível): captura + anti-spam em todos
    //   os forms CF7, como sempre foi. mode 'allowlist': só os form_ids
    //   listados — os demais ficam 100% intocados pelo plugin.
    // ─────────────────────────────────────────────────────────
    public static function cf7_scope_defaults() {
        return array(
            'mode'     => 'all',
            'form_ids' => array(),
        );
    }
    public static function get_cf7_scope() {
        $v = wp_parse_args(get_option(self::OPTION_CF7_SCOPE, array()), self::cf7_scope_defaults());
        $v['mode'] = ($v['mode'] === 'allowlist') ? 'allowlist' : 'all';
        $ids = is_array($v['form_ids']) ? $v['form_ids'] : array();
        $v['form_ids'] = array_values(array_unique(array_filter(array_map('intval', $ids))));
        return $v;
    }
    public static function update_cf7_scope(array $patch) {
        $current = self::get_cf7_scope();
        update_option(self::OPTION_CF7_SCOPE, array_merge($current, $patch), false);
    }

    // ─────────────────────────────────────────────────────────
    // ANTI-SPAM
    // ─────────────────────────────────────────────────────────
    /**
     * Duas famílias, e a diferença entre elas é a única coisa que o usuário
     * precisa entender nessa tela:
     *
     * BASE — o que qualquer site quer, sem contrapartida. Nasce ligada e não
     * tem por que desligar: armadilha invisível, tempo mínimo e recusa de
     * ferramenta automatizada não têm como barrar uma pessoa de verdade.
     *
     * OPCIONAL — o que depende do caso e tem implicação. Nasce DESLIGADA e a
     * tela diz o que muda ao ligar. Limite por endereço conta por IP, e IP é
     * compartilhado (iCloud Private Relay, escritório inteiro) — pode barrar
     * gente de verdade. Listas de bloqueio são opt-in por natureza.
     */
    const ANTISPAM_BASE = array('honeypot', 'time_trap', 'ua_check');

    public static function antispam_defaults() {
        return array(
            'enabled'         => '1',
            // Base
            'honeypot'        => '1',
            'time_trap'       => '1',
            'time_trap_min_s' => 2,
            'ua_check'        => '1',
            // Opcional
            'rate_limit'      => '0',
            'rate_limit_max'  => 5,
            'blocklist_emails'=> "",   // regex separado por linha
            'blocklist_words' => "",   // palavras separadas por linha (em qualquer field)
            // Marca a versão do formato. Ver get_antispam().
            'schema'          => 2,
        );
    }

    public static function get_antispam() {
        // O schema tem que ser lido do que está GRAVADO, não do resultado do
        // merge — wp_parse_args preenche a chave ausente com o default (2) e
        // o conserto abaixo nunca dispararia. Justamente nas instalações
        // antigas, que são as que precisam dele.
        $bruto = get_option(self::OPTION_ANTISPAM, array());
        if (!is_array($bruto)) $bruto = array();
        $schema_gravado = (int) ($bruto['schema'] ?? 1);

        $cfg = wp_parse_args($bruto, self::antispam_defaults());

        // Conserto de uma vez só.
        //
        // A tela antiga gravava '0' em toda caixa desmarcada, e wp_parse_args
        // só preenche chave AUSENTE — então um único save com as caixas
        // vazias desligava a proteção inteira, para sempre e sem aviso.
        // Aconteceu: dois sites nossos estavam com tudo em '0'.
        //
        // A assinatura do acidente é TODA a base desligada ao mesmo tempo.
        // Desligar uma camada só é escolha plausível e fica de pé. E só
        // acontece uma vez: gravar carimba o schema novo.
        if ($schema_gravado < 2) {
            $base_toda_off = true;
            foreach (self::ANTISPAM_BASE as $k) {
                if (($cfg[$k] ?? '1') === '1') { $base_toda_off = false; break; }
            }
            if ($base_toda_off) {
                foreach (self::ANTISPAM_BASE as $k) $cfg[$k] = '1';
                $cfg['enabled'] = '1';
                $cfg['reparado_em'] = current_time('c');
            }
            $cfg['schema'] = 2;
            update_option(self::OPTION_ANTISPAM, $cfg, false);
        }

        return $cfg;
    }
    public static function update_antispam(array $patch) {
        $current = self::get_antispam();
        update_option(self::OPTION_ANTISPAM, array_merge($current, $patch), false);
    }

    // ─────────────────────────────────────────────────────────
    // FIELD MAPPING (per form)
    //   structure: array(form_id => array(cf7_field => canonical_field))
    // ─────────────────────────────────────────────────────────
    public static function get_field_mappings() {
        $v = get_option(self::OPTION_FIELD_MAP, array());
        return is_array($v) ? $v : array();
    }
    public static function set_field_mapping_for_form($form_id, array $mapping) {
        $all = self::get_field_mappings();
        $all[(int) $form_id] = $mapping;
        update_option(self::OPTION_FIELD_MAP, $all, false);
    }
    public static function get_field_mapping_for_form($form_id) {
        $all = self::get_field_mappings();
        $key = (int) $form_id;
        return isset($all[$key]) ? $all[$key] : array();
    }

    // Campos canônicos que o CRM (cf7-processor) reconhece. Plugin mapeia
    // campos do CF7 pra esses nomes antes do POST.
    // v2.19: a lista vem do sync com o CRM (Field_Mapping_Sync baixa
    // {canonical,label,aliases} de /api/wp/field-mapping — inclui os campos
    // PERSONALIZADOS da brand criados em /settings do AdSpirit). Lê a option
    // DIRETO, sem Field_Mapping_Sync::get_defaults(): o lazy-refresh de lá
    // faz wp_remote_get síncrono (até 10s) quando o cache de 1h expira, e
    // canonical_fields() roda em render de admin. Fallback obrigatório no
    // array hardcoded: sync nunca rodou / falhou / Safe Mode.
    public static function canonical_fields() {
        if (class_exists('AdSpirit_Field_Mapping_Sync')) {
            $synced = get_option(AdSpirit_Field_Mapping_Sync::OPTION_DEFAULTS, array());
            if (is_array($synced) && !empty($synced)) {
                $out = array();
                foreach ($synced as $entry) {
                    if (!is_array($entry) || empty($entry['canonical'])) continue;
                    $canonical = (string) $entry['canonical'];
                    $label = isset($entry['label']) && $entry['label'] !== ''
                        ? (string) $entry['label']
                        : $canonical;
                    $out[$canonical] = $label;
                }
                if (!empty($out)) return $out;
            }
        }
        return array(
            'your-name'           => 'Nome',
            'your-email'          => 'Email',
            'Telefone'            => 'Telefone',
            'empresa'             => 'Empresa',
            'cargo'               => 'Cargo',
            'Numero-funcionarios' => 'Nº de funcionários',
            'nicho'               => 'Nicho / Segmento',
            'site-empresa'        => 'Site da empresa',
            'Investimento'        => 'Investimento atual',
            'urgencia para começar' => 'Urgência',
        );
    }

    // ─────────────────────────────────────────────────────────
    // CAPI META (Conversion API server-side)
    // ─────────────────────────────────────────────────────────
    public static function capi_meta_defaults() {
        return array(
            'enabled'        => '0',
            'pixel_id'       => '',
            'access_token'   => '',
            'test_event_code'=> '',
            'send_page_view' => '1', // dispara PageView a cada page load
            'send_lead'      => '1', // dispara Lead no CF7 submit
        );
    }
    public static function get_capi_meta() {
        $c = wp_parse_args(get_option(self::OPTION_CAPI_META, array()), self::capi_meta_defaults());
        $c['access_token'] = self::secret_from_config('ADSPIRIT_CAPI_ACCESS_TOKEN', $c['access_token']);
        return $c;
    }
    public static function update_capi_meta(array $patch) {
        $current = (array) get_option(self::OPTION_CAPI_META, array());
        update_option(self::OPTION_CAPI_META, array_merge($current, $patch), false);
    }

    // ─────────────────────────────────────────────────────────
    // GA4
    // ─────────────────────────────────────────────────────────
    public static function ga4_defaults() {
        return array(
            'enabled'         => '0',
            'measurement_id'  => '',
            'api_secret'      => '',
            'send_page_view'  => '1',
            'send_lead'       => '1',
        );
    }
    public static function get_ga4() {
        $c = wp_parse_args(get_option(self::OPTION_GA4, array()), self::ga4_defaults());
        $c['api_secret'] = self::secret_from_config('ADSPIRIT_GA4_API_SECRET', $c['api_secret']);
        return $c;
    }
    public static function update_ga4(array $patch) {
        $current = (array) get_option(self::OPTION_GA4, array());
        update_option(self::OPTION_GA4, array_merge($current, $patch), false);
    }

    // ─────────────────────────────────────────────────────────
    // CROSS-DOMAIN
    // ─────────────────────────────────────────────────────────
    public static function cross_domain_defaults() {
        return array(
            'enabled'  => '0',
            'domains'  => "", // 1 hostname por linha
        );
    }
    public static function get_cross_domain() {
        return wp_parse_args(get_option(self::OPTION_CROSS_DOMAIN, array()), self::cross_domain_defaults());
    }
    public static function update_cross_domain(array $patch) {
        $current = self::get_cross_domain();
        update_option(self::OPTION_CROSS_DOMAIN, array_merge($current, $patch), false);
    }

    public function register_settings() {
        // Cada classe tab registra o próprio handler de save via admin-post.
        // Aqui só placeholder por enquanto — settings API tradicional usa
        // register_setting; preferimos handlers próprios pra cada tab.
    }
}
