<?php
/**
 * AdSpirit Connector — Contact Form 7 handler.
 *
 * Pipeline na ordem:
 *   wpcf7_validate (anti-spam é registrado em outra classe; roda antes)
 *   ↓
 *   wpcf7_before_send_mail (este handler, priority 99) — Fase 1.1: capturamos
 *   ANTES do envio do e-mail, NÃO no wpcf7_mail_sent. Motivo: mail_sent não
 *   dispara se o e-mail falha (SMTP/Gmail fora) → o lead se perdia sem nem ser
 *   gravado. before_send_mail roda após validação + anti-spam e antes do mail,
 *   então o lead é gravado na rede de segurança + despachado mesmo se o e-mail
 *   falhar. Não captura spam/inválido (esses não chegam aqui).
 *     1. Aplica field mapping (form_id → canonical names)
 *     2. POST pro CRM (fire-and-forget)
 *     3. Dispara Meta CAPI Lead event (paralelo)
 *     4. Dispara GA4 generate_lead event (paralelo)
 *     5. Log circular pro Logs tab
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Cf7_Handler {
    const LOG_KEY = 'adspirit_connector_cf7_log';
    const LOG_MAX = 100;

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // Fase 1.1: before_send_mail (não mail_sent) — captura o lead mesmo se
        // o e-mail falhar (ex: SMTP/Gmail revogado). Dispara após validação +
        // anti-spam do CF7 e antes do envio do e-mail.
        add_action(
            'wpcf7_before_send_mail',
            AdSpirit_Safe_Hook::action(array($this, 'dispatch'), 'cf7_handler'),
            99,
            1
        );

        // P0-2: aba "CF7 · Escopo" (grupo Captura) — todos os forms (default)
        // ou allowlist. Filtros de registro são baratos e não podem fatal;
        // render/save vão wrapped.
        add_filter('adspirit_connector_tabs', function ($tabs) {
            $tabs['cf7-scope'] = 'CF7 · Escopo';
            return $tabs;
        });
        add_filter('adspirit_connector_tab_groups', function ($groups) {
            if (isset($groups['captura']['tabs']) && is_array($groups['captura']['tabs'])
                && !in_array('cf7-scope', $groups['captura']['tabs'], true)) {
                $groups['captura']['tabs'][] = 'cf7-scope';
            }
            return $groups;
        });
        add_action(
            'adspirit_connector_render_tab_cf7-scope',
            AdSpirit_Safe_Hook::action(array($this, 'render_scope_tab'), 'cf7_scope_render')
        );
        add_action(
            'adspirit_connector_save_cf7-scope',
            AdSpirit_Safe_Hook::action(array($this, 'handle_scope_save'), 'cf7_scope_save')
        );
    }

    /**
     * P0-2 — o form CF7 está no escopo de atuação do plugin?
     * mode 'all' (default): sempre true — comportamento histórico.
     * mode 'allowlist': só os form_ids marcados; allowlist vazia = nenhum.
     * Usado pelo dispatch daqui E pelo anti-spam (honeypot + validate):
     * form fora do escopo fica 100% intocado.
     */
    public static function form_in_scope($form_id) {
        $scope = AdSpirit_Settings::get_cf7_scope();
        if ($scope['mode'] !== 'allowlist') return true;
        return in_array((int) $form_id, $scope['form_ids'], true);
    }

    public function dispatch($contact_form) {
        $settings = AdSpirit_Settings::get_core();
        if (empty($settings['cf7_enabled']) || $settings['cf7_enabled'] !== '1') {
            return;
        }
        // P0-2: escopo — form fora da allowlist é 100% intocado. Sem log de
        // 'skipped' de propósito: submissão fora do escopo não é assunto do
        // plugin e não deve consumir o log circular (capado em 100).
        if ($contact_form && !self::form_in_scope($contact_form->id())) {
            return;
        }
        if (empty($settings['endpoint_url']) || empty($settings['brand_slug']) || empty($settings['secret'])) {
            self::log('skipped', 0, 'config_incompleta');
            return;
        }

        if (!class_exists('WPCF7_Submission')) {
            self::log('skipped', 0, 'cf7_not_loaded');
            return;
        }

        $submission = WPCF7_Submission::get_instance();
        if (!$submission) {
            self::log('skipped', 0, 'no_submission');
            return;
        }

        $raw_data = $submission->get_posted_data();
        if (!is_array($raw_data) || empty($raw_data)) {
            self::log('skipped', 0, 'empty_data');
            return;
        }

        // Stringifica arrays (checkbox multi-valor → CSV).
        foreach ($raw_data as $k => $v) {
            if (is_array($v)) {
                $raw_data[$k] = implode(', ', array_filter(array_map('strval', $v)));
            } else {
                $raw_data[$k] = is_string($v) ? $v : strval($v);
            }
        }

        // 1) Aplica field mapping per-form (rename pra canonical).
        $form_id = $contact_form->id();
        $data = AdSpirit_Field_Mapping::apply($form_id, $raw_data);

        // 1.5) Local dedup — bloqueia mesmo email 2x em 60s (anti double-submit)
        if (class_exists('AdSpirit_Integrations')
            && !empty($data['your-email'])
            && AdSpirit_Integrations::is_dedup((string) $data['your-email'])) {
            self::log('skipped', 0, 'local_dedup_60s');
            return;
        }

        // 2) Augmenta com cf7_time + cf7_url (timestamps + referrer)
        $data['cf7_time'] = current_time('c');
        $url = '';
        if (function_exists('wp_get_referer')) {
            $url = wp_get_referer() ?: '';
        }
        if (!$url && !empty($_SERVER['HTTP_REFERER'])) {
            $url = esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']));
        }
        $data['cf7_url'] = $url;

        // 2.5) Telemetria — junta com pixel (visitor_id) + browser + server
        if (class_exists('AdSpirit_Telemetry')) {
            $data['_adspirit_telemetry'] = AdSpirit_Telemetry::collect_from_post(
                'contact_form_7',
                (string) $form_id,
                $url
            );
            // Email classification helper
            if (!empty($data['your-email'])) {
                $data['_adspirit_telemetry']['email_type'] =
                    AdSpirit_Telemetry::classify_email((string) $data['your-email']);
            }
        }

        // 3) ID idempotente
        $submission_id = sprintf(
            '%s-%s-%s',
            $form_id,
            time(),
            substr(md5(wp_json_encode($data)), 0, 8)
        );

        // 4) POST pro CRM
        $endpoint = trailingslashit($settings['endpoint_url']) . 'api/webhooks/contact-form-7';
        $body = wp_json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $args = array(
            'timeout'     => 8,
            'redirection' => 2,
            'blocking'    => false,
            'headers'     => array(
                'Content-Type'        => 'application/json; charset=utf-8',
                'x-brand-slug'        => $settings['brand_slug'],
                'x-cf7-secret'        => $settings['secret'],
                'x-cf7-submission-id' => $submission_id,
                'User-Agent'          => 'AdSpirit-Connector/' . ADSPIRIT_CONNECTOR_VERSION,
            ),
            'body'        => $body,
        );

        // Fase 1: grava local ANTES do POST pro CRM. Integridade NÃO bloqueia o
        // envio — se record_pending falhar (tabela ausente), retorna false e o
        // fluxo segue (lead vai pro CRM + log legado de fallback abaixo).
        if (class_exists('AdSpirit_Lead_Store')) {
            AdSpirit_Lead_Store::record_pending($submission_id, $data, 'cf7', (string) $form_id);
        }

        $response = wp_remote_post($endpoint, $args);
        if (is_wp_error($response)) {
            self::log('error', 0, $response->get_error_message());
            error_log('[AdSpirit Connector] CF7 dispatch failed: ' . $response->get_error_message());
            if (class_exists('AdSpirit_Lead_Store')) {
                AdSpirit_Lead_Store::mark($submission_id, 'crm', 'failed', 0, $response->get_error_message());
            }
        } else {
            self::log('sent', 0, null, array(
                'form_id' => $form_id,
                'fields'  => array_keys($data),
            ));
            // Log local pra aba "Submissions" (não-bloqueante, response body
            // não disponível em blocking=false — gravamos sem profile).
            $data_with_form = array_merge($data, array('_form_id' => (string) $form_id));
            do_action('adspirit_lead_dispatched', $data_with_form, null, 'cf7');
            // blocking=false: "sent" = despachado sem erro local (não confirma
            // aceite do CRM). O record durável permite reenvio se algo falhar.
            if (class_exists('AdSpirit_Lead_Store')) {
                AdSpirit_Lead_Store::mark($submission_id, 'crm', 'sent', 0, null);
            }
        }

        // Fanout pra webhooks externos (Zapier/Make/n8n)
        if (class_exists('AdSpirit_Integrations')) {
            AdSpirit_Integrations::fanout($data);
            if (class_exists('AdSpirit_Lead_Store')) {
                AdSpirit_Lead_Store::mark($submission_id, 'fanout', 'dispatched');
            }
        }

        // 5) Paralelo: dispara Meta CAPI Lead + GA4 generate_lead
        AdSpirit_Capi_Meta::send_lead_for_submission($submission_id, $data, $url);
        AdSpirit_Ga4::send_lead_for_submission($submission_id, $data, $url);

        // 6) Passthroughs dedicados (Customer.io + Mailchimp)
        if (class_exists('AdSpirit_Customerio')) {
            AdSpirit_Customerio::dispatch_for_payload($data);
        }
        if (class_exists('AdSpirit_Mailchimp')) {
            AdSpirit_Mailchimp::dispatch_for_payload($data);
        }

        // 7) Hook genérico pra extensions custom
        do_action('adspirit_connector_cf7_dispatched', $data, $form_id);
    }

    // ─────────────────────────────────────────────────────────
    // P0-2: aba "CF7 · Escopo" (render + save)
    // ─────────────────────────────────────────────────────────

    public function render_scope_tab() {
        $scope = AdSpirit_Settings::get_cf7_scope();
        $forms = array();
        if (class_exists('WPCF7_ContactForm')) {
            $forms = WPCF7_ContactForm::find(array(
                'posts_per_page' => -1,
                'orderby'        => 'ID',
                'order'          => 'ASC',
            ));
        }
        AdSpirit_Menu::card_open(
            'Escopo de captura — Contact Form 7',
            'Em quais forms CF7 o plugin atua (captura pro CRM + anti-spam). Form fora do escopo fica 100% intocado.'
        );
        AdSpirit_Menu::form_open('cf7-scope');
        ?>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row">Modo</th>
                <td>
                    <label style="display:block; margin-bottom:6px;">
                        <input type="radio" name="scope_mode" value="all" <?php checked($scope['mode'], 'all'); ?>>
                        <strong>Todos os forms</strong> — comportamento padrão (retrocompatível): todo form CF7 do site é capturado e protegido.
                    </label>
                    <label style="display:block;">
                        <input type="radio" name="scope_mode" value="allowlist" <?php checked($scope['mode'], 'allowlist'); ?>>
                        <strong>Somente os selecionados</strong> — só os forms marcados abaixo passam pelo plugin; os demais seguem como se o plugin não existisse.
                    </label>
                </td>
            </tr>
            <tr>
                <th scope="row">Forms no escopo</th>
                <td>
                    <?php if (empty($forms)) : ?>
                        <p class="description">Nenhum form CF7 encontrado (Contact Form 7 inativo?).</p>
                    <?php else : foreach ($forms as $f) : $fid = (int) $f->id(); ?>
                        <label style="display:block; margin-bottom:4px;">
                            <input type="checkbox" name="scope_form_ids[]" value="<?php echo esc_attr($fid); ?>"
                                <?php checked(in_array($fid, $scope['form_ids'], true)); ?>>
                            <?php echo esc_html($f->title()); ?> <span style="opacity:.6;">(id <?php echo esc_html($fid); ?>)</span>
                        </label>
                    <?php endforeach; endif; ?>
                    <p class="description">
                        Só vale no modo "Somente os selecionados". Allowlist vazia nesse modo = nenhum form capturado.
                        Os forms nativos do plugin (<code>[adspirit_form]</code> / qualifier) não são afetados por este escopo.
                    </p>
                </td>
            </tr>
        </table>
        <?php
        AdSpirit_Menu::form_close('Salvar escopo');
        AdSpirit_Menu::card_close();
    }

    public function handle_scope_save($post) {
        $mode = (isset($post['scope_mode']) && $post['scope_mode'] === 'allowlist') ? 'allowlist' : 'all';
        $ids  = array();
        if (isset($post['scope_form_ids']) && is_array($post['scope_form_ids'])) {
            foreach ($post['scope_form_ids'] as $v) {
                $i = (int) $v;
                if ($i > 0) $ids[] = $i;
            }
        }
        AdSpirit_Settings::update_cf7_scope(array(
            'mode'     => $mode,
            'form_ids' => array_values(array_unique($ids)),
        ));
    }

    public static function log($status, $http_code, $error = null, array $extra = array()) {
        $log = get_option(self::LOG_KEY, array());
        if (!is_array($log)) $log = array();
        $entry = array(
            'at'        => current_time('c'),
            'status'    => $status,
            'http_code' => $http_code,
            'error'     => $error,
        );
        if (!empty($extra)) $entry = array_merge($entry, $extra);
        array_unshift($log, $entry);
        if (count($log) > self::LOG_MAX) {
            $log = array_slice($log, 0, self::LOG_MAX);
        }
        update_option(self::LOG_KEY, $log, false);
    }
}
