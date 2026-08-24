<?php
/**
 * AdSpirit Connector — Field mapping sync (puxa defaults do CRM).
 *
 * Feature 36: o CRM mantém os campos canônicos + aliases comuns como
 * source-of-truth. Plugin chama GET /api/wp/field-mapping?brand_slug=X
 * com header x-cf7-secret pra puxar a lista e cachear localmente.
 *
 * Uso pelo plugin:
 *   - Após conectar com sucesso (AdSpirit_Connect::handle_callback hook),
 *     dispara sync automático em background — pluga via action
 *     'adspirit_connector_connected'.
 *   - Cache de 1h via option `adspirit_connector_mapping_sync_at`.
 *   - Botão manual "Sincronizar mapeamento com CRM" na tab Forms (forçado).
 *   - AdSpirit_Field_Mapping::compute_suggestions() consulta os aliases
 *     daqui em vez do array hardcoded — via filtro `adspirit_field_mapping_aliases`.
 *
 * Storage:
 *   - adspirit_connector_mapping_defaults: array de { canonical, label, aliases[] }
 *   - adspirit_connector_mapping_sync_at:  unix timestamp da última sync
 *   - adspirit_connector_mapping_sync_error: última mensagem de erro (string ou '')
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Field_Mapping_Sync {
    const OPTION_DEFAULTS  = 'adspirit_connector_mapping_defaults';
    const OPTION_SYNC_AT   = 'adspirit_connector_mapping_sync_at';
    const OPTION_SYNC_ERR  = 'adspirit_connector_mapping_sync_error';
    const CACHE_TTL_SECONDS = 3600; // 1h

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Hook no handler de save da tab Forms — botão manual envia
        // adspirit_tab=forms + sync_mapping=1.
        add_action(
            'adspirit_connector_save_forms',
            AdSpirit_Safe_Hook::action(array($this, 'handle_manual_sync'), 'field_mapping_sync_save')
        );

        // Auto-sync após connect — listener do hook custom disparado por
        // AdSpirit_Connect::handle_callback (se presente). Se o connect não
        // disparar o action, sync acontece on-demand via botão manual ou
        // via lazy refresh dentro de get_defaults().
        add_action(
            'adspirit_connector_connected',
            AdSpirit_Safe_Hook::action(array($this, 'handle_post_connect'), 'field_mapping_sync_post_connect')
        );

        // Override dos aliases hardcoded em AdSpirit_Field_Mapping::compute_suggestions().
        add_filter(
            'adspirit_field_mapping_aliases',
            AdSpirit_Safe_Hook::filter(array($this, 'inject_aliases'), 'field_mapping_sync_aliases'),
            10,
            1
        );

        // CTA acima da tab Forms — botão "Sincronizar mapeamento com CRM"
        // + status da última sync.
        add_action(
            'adspirit_connector_render_tab_forms',
            AdSpirit_Safe_Hook::action(array($this, 'render_sync_panel'), 'field_mapping_sync_render'),
            5 // antes do render_tab principal (default 10)
        );
    }

    // ─────────────────────────────────────────────────────────
    // Sync core
    // ─────────────────────────────────────────────────────────

    /**
     * Chama o CRM e atualiza as options. Retorna true se sucesso, false se
     * falhou (com mensagem em option OPTION_SYNC_ERR).
     */
    public function sync_now() {
        $core = AdSpirit_Settings::get_core();
        $endpoint = isset($core['endpoint_url']) ? (string) $core['endpoint_url'] : '';
        $brand_slug = isset($core['brand_slug']) ? (string) $core['brand_slug'] : '';
        $secret = isset($core['secret']) ? (string) $core['secret'] : '';

        if (!$endpoint || !$brand_slug || !$secret) {
            $msg = 'Conexão CRM incompleta — endpoint, brand_slug ou secret faltando.';
            update_option(self::OPTION_SYNC_ERR, $msg, false);
            return false;
        }

        $url = rtrim($endpoint, '/') . '/api/wp/field-mapping?brand_slug=' . rawurlencode($brand_slug);

        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'x-cf7-secret' => $secret,
                'x-brand-slug' => $brand_slug,
                'User-Agent'   => 'AdSpirit-Connector/' . (defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : 'unknown'),
                'Accept'       => 'application/json',
            ),
        ));

        if (is_wp_error($response)) {
            $msg = 'Erro de rede: ' . $response->get_error_message();
            update_option(self::OPTION_SYNC_ERR, $msg, false);
            return false;
        }

        $code = (int) wp_remote_retrieve_response_code($response);
        $body = (string) wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        if ($code !== 200 || !is_array($data) || empty($data['defaults']) || !is_array($data['defaults'])) {
            $err = (is_array($data) && !empty($data['error'])) ? $data['error'] : ('HTTP ' . $code);
            $msg = 'CRM rejeitou sync: ' . $err;
            update_option(self::OPTION_SYNC_ERR, $msg, false);
            return false;
        }

        // Sanitiza cada entry antes de salvar — defesa em profundidade.
        $clean = array();
        foreach ($data['defaults'] as $entry) {
            if (!is_array($entry)) continue;
            $canonical = sanitize_text_field((string) ($entry['canonical'] ?? ''));
            $label     = sanitize_text_field((string) ($entry['label'] ?? ''));
            $aliases_raw = isset($entry['aliases']) && is_array($entry['aliases']) ? $entry['aliases'] : array();
            $aliases = array();
            foreach ($aliases_raw as $alias) {
                $a = sanitize_text_field((string) $alias);
                if ($a !== '') $aliases[] = $a;
            }
            if ($canonical === '') continue;
            $clean[] = array(
                'canonical' => $canonical,
                'label'     => $label,
                'aliases'   => array_values(array_unique($aliases)),
            );
        }

        update_option(self::OPTION_DEFAULTS, $clean, false);
        update_option(self::OPTION_SYNC_AT, time(), false);
        update_option(self::OPTION_SYNC_ERR, '', false);
        return true;
    }

    /**
     * Retorna os defaults cacheados; se passou de 1h, dispara sync e
     * retorna o que tiver. Nunca bloqueia a UI por sync — caller pode
     * sempre seguir com o que tem em cache (ou vazio).
     */
    public static function get_defaults() {
        $stored = get_option(self::OPTION_DEFAULTS, array());
        if (!is_array($stored)) $stored = array();

        $sync_at = (int) get_option(self::OPTION_SYNC_AT, 0);
        $is_stale = ($sync_at === 0) || ((time() - $sync_at) > self::CACHE_TTL_SECONDS);

        // Lazy refresh: só dispara se já está conectado e cache stale.
        // Não bloqueia o caller — se falhar, retorna o cache atual.
        if ($is_stale && class_exists('AdSpirit_Connect') && AdSpirit_Connect::is_connected()) {
            self::instance()->sync_now();
            $stored = get_option(self::OPTION_DEFAULTS, $stored);
            if (!is_array($stored)) $stored = array();
        }

        return $stored;
    }

    public static function get_sync_at() {
        return (int) get_option(self::OPTION_SYNC_AT, 0);
    }

    public static function get_sync_error() {
        $v = get_option(self::OPTION_SYNC_ERR, '');
        return is_string($v) ? $v : '';
    }

    // ─────────────────────────────────────────────────────────
    // Hooks
    // ─────────────────────────────────────────────────────────

    /**
     * Disparado por AdSpirit_Connect após sucesso do exchange (se o action
     * estiver registrado lá). Faz sync inicial pra brand já entrar com
     * defaults frescos.
     */
    public function handle_post_connect() {
        $this->sync_now();
    }

    /**
     * Hook no save da tab Forms. Plugin envia checkbox/button
     * `sync_mapping=1` quando o user clica "Sincronizar mapeamento com CRM".
     */
    public function handle_manual_sync($post) {
        if (empty($post['sync_mapping'])) return; // só age se o trigger veio
        $ok = $this->sync_now();
        if ($ok) {
            $count = count(self::get_defaults());
            add_settings_error(
                'adspirit_connector_mapping_defaults',
                'sync_ok',
                sprintf('Sync com CRM ok — %d campos canônicos baixados.', $count),
                'updated'
            );
        } else {
            add_settings_error(
                'adspirit_connector_mapping_defaults',
                'sync_fail',
                'Falha ao sincronizar: ' . esc_html(self::get_sync_error()),
                'error'
            );
        }
    }

    /**
     * Injeta os aliases vindos do CRM no array de sugestões usado por
     * AdSpirit_Field_Mapping::compute_suggestions. Se o CRM ainda não foi
     * sincronizado, mantém o array original (hardcoded).
     *
     * Formato esperado: array(canonical_key => array(alias1, alias2, ...))
     */
    public function inject_aliases($current) {
        if (!is_array($current)) $current = array();
        $defaults = self::get_defaults();
        if (empty($defaults)) return $current;
        $remote = array();
        foreach ($defaults as $entry) {
            if (empty($entry['canonical'])) continue;
            $remote[$entry['canonical']] = isset($entry['aliases']) && is_array($entry['aliases'])
                ? $entry['aliases']
                : array();
        }
        // Merge: prefere o remoto, mas mantém local pra qualquer canonical
        // que o remoto não cubra.
        foreach ($current as $k => $v) {
            if (!isset($remote[$k])) $remote[$k] = $v;
        }
        return $remote;
    }

    // ─────────────────────────────────────────────────────────
    // UI: painel no topo da tab Forms
    // ─────────────────────────────────────────────────────────

    public function render_sync_panel() {
        if (!class_exists('AdSpirit_Connect') || !AdSpirit_Connect::is_connected()) {
            return; // tab principal já mostra mensagem de "conecte primeiro"
        }

        $sync_at = self::get_sync_at();
        $err = self::get_sync_error();
        $defaults = self::get_defaults();
        $count = is_array($defaults) ? count($defaults) : 0;

        $status_html = '';
        if ($sync_at > 0) {
            $diff = human_time_diff($sync_at, time());
            $status_html = sprintf(
                '<span class="as-badge">Última sync: há %s · %d campos</span>',
                esc_html($diff),
                (int) $count
            );
        } else {
            $status_html = '<span class="as-badge">Nunca sincronizado</span>';
        }

        AdSpirit_Menu::card_open(
            'Mapeamento canônico — sincronizado com o CRM',
            'O CRM mantém a lista de campos canônicos (your-name, your-email, Telefone, …) e os aliases comuns. Sincronize pra puxar atualizações sem precisar de release novo do plugin.',
            $status_html
        );

        if ($err) {
            echo '<div class="as-notice danger"><p><strong>Última tentativa falhou:</strong> ' . esc_html($err) . '</p></div>';
        }

        AdSpirit_Menu::form_open('forms');
        ?>
        <input type="hidden" name="sync_mapping" value="1">
        <p class="submit" style="margin-top:0;">
            <button type="submit" class="button button-primary">Sincronizar mapeamento com o AdSpirit</button>
            <span class="as-field-help" style="margin-left:10px;">
                Cache automático de 1h. Use o botão pra forçar refresh imediato.
            </span>
        </p>
        </form>
        <?php
        AdSpirit_Menu::card_close();
    }
}
