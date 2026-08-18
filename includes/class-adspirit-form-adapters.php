<?php
/**
 * AdSpirit Connector — adapters pra outros plugins de form.
 *
 * Cada plugin tem hook próprio + formato de payload diferente. Adapter
 * normaliza pro formato canonical (your-name, your-email, Telefone, etc)
 * e dispatch usa o mesmo CF7 handler.
 *
 * Suportados:
 *   - Gravity Forms (gform_after_submission)
 *   - WPForms (wpforms_process_complete)
 *   - Elementor Forms (elementor_pro/forms/new_record)
 *   - Fluent Forms (fluentform_submission_inserted)
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Form_Adapters {
    private static $instance = null;
    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Gravity Forms
        add_action('gform_after_submission',
            AdSpirit_Safe_Hook::action(array($this, 'on_gravity'), 'gravity_submit'), 99, 2);

        // WPForms
        add_action('wpforms_process_complete',
            AdSpirit_Safe_Hook::action(array($this, 'on_wpforms'), 'wpforms_submit'), 99, 4);

        // Elementor Forms
        add_action('elementor_pro/forms/new_record',
            AdSpirit_Safe_Hook::action(array($this, 'on_elementor'), 'elementor_submit'), 99, 2);

        // Fluent Forms
        add_action('fluentform_submission_inserted',
            AdSpirit_Safe_Hook::action(array($this, 'on_fluent'), 'fluent_submit'), 99, 3);
    }

    public function on_gravity($entry, $form) {
        if (!$this->is_enabled()) return;
        $payload = array();
        if (is_array($form) && !empty($form['fields'])) {
            foreach ($form['fields'] as $field) {
                $id = $field->id ?? '';
                if (!$id || !isset($entry[$id])) continue;
                $label = $this->canonical_name($field->label ?? '');
                if ($label) $payload[$label] = (string) $entry[$id];
            }
        }
        $this->dispatch('gravity', isset($form['id']) ? (string) $form['id'] : '', $payload);
    }

    public function on_wpforms($fields, $entry, $form_data, $entry_id) {
        if (!$this->is_enabled()) return;
        $payload = array();
        if (is_array($fields)) {
            foreach ($fields as $field) {
                $name = isset($field['name']) ? $field['name'] : '';
                $value = isset($field['value']) ? (string) $field['value'] : '';
                $canon = $this->canonical_name($name);
                if ($canon) $payload[$canon] = $value;
            }
        }
        $this->dispatch('wpforms', isset($form_data['id']) ? (string) $form_data['id'] : '', $payload);
    }

    public function on_elementor($record, $handler) {
        if (!$this->is_enabled()) return;
        if (!is_object($record) || !method_exists($record, 'get_field')) return;
        $raw = $record->get_field(array());
        $payload = array();
        if (is_array($raw)) {
            foreach ($raw as $f) {
                $title = isset($f['title']) ? $f['title'] : '';
                $value = isset($f['value']) ? (string) $f['value'] : '';
                $canon = $this->canonical_name($title);
                if ($canon) $payload[$canon] = $value;
            }
        }
        $form_id = '';
        if (method_exists($record, 'get_form_settings')) {
            $form_id = (string) ($record->get_form_settings('id') ?: '');
        }
        $this->dispatch('elementor', $form_id, $payload);
    }

    public function on_fluent($entry_id, $form_data, $form) {
        if (!$this->is_enabled()) return;
        $payload = array();
        if (is_array($form_data)) {
            foreach ($form_data as $k => $v) {
                $canon = $this->canonical_name($k);
                if ($canon) $payload[$canon] = is_array($v) ? implode(', ', $v) : (string) $v;
            }
        }
        $this->dispatch('fluent', is_object($form) && isset($form->id) ? (string) $form->id : '', $payload);
    }

    /**
     * Heurística de mapping: label/name → nome canonical do CRM.
     */
    private function canonical_name($input) {
        $s = strtolower(trim((string) $input));
        if ($s === '') return null;
        // Limpa
        $s = preg_replace('/[\s\-_]+/', '-', $s);

        $map = array(
            'nome' => 'your-name', 'name' => 'your-name', 'fullname' => 'your-name',
            'email' => 'your-email', 'e-mail' => 'your-email', 'mail' => 'your-email',
            'telefone' => 'Telefone', 'phone' => 'Telefone', 'tel' => 'Telefone',
            'celular' => 'Telefone', 'whatsapp' => 'Telefone',
            'empresa' => 'empresa', 'company' => 'empresa', 'business' => 'empresa',
            'cargo' => 'cargo', 'position' => 'cargo', 'role' => 'cargo',
            'nicho' => 'nicho', 'segment' => 'nicho', 'industry' => 'nicho',
            'investimento' => 'Investimento', 'budget' => 'Investimento', 'orcamento' => 'Investimento',
            'site' => 'site-empresa', 'website' => 'site-empresa', 'url' => 'site-empresa',
        );
        foreach ($map as $needle => $canon) {
            if (strpos($s, $needle) !== false) return $canon;
        }
        return null;
    }

    private function is_enabled() {
        $c = AdSpirit_Settings::get_core();
        return !empty($c['cf7_enabled']) && $c['cf7_enabled'] === '1'
            && !empty($c['brand_slug']) && !empty($c['secret']);
    }

    private function dispatch($form_kind, $form_id, array $payload) {
        if (empty($payload['your-email']) && empty($payload['Telefone'])) {
            // Sem email nem phone → não tem como criar lead
            return;
        }

        $core = AdSpirit_Settings::get_core();
        $payload['cf7_time'] = current_time('c');
        $url = function_exists('wp_get_referer') ? (wp_get_referer() ?: '') : '';
        if (!$url && !empty($_SERVER['HTTP_REFERER'])) {
            $url = esc_url_raw(wp_unslash($_SERVER['HTTP_REFERER']));
        }
        $payload['cf7_url'] = $url;

        if (class_exists('AdSpirit_Telemetry')) {
            $payload['_adspirit_telemetry'] = AdSpirit_Telemetry::collect_from_post($form_kind, $form_id, $url);
            if (!empty($payload['your-email'])) {
                $payload['_adspirit_telemetry']['email_type'] = AdSpirit_Telemetry::classify_email((string) $payload['your-email']);
            }
        }

        // F0 Connector 3.0: identidade do form no payload (form de terceiro
        // não tem config de finalidade — default comercial).
        $payload['_adspirit_form_id'] = $form_kind . ':' . $form_id;
        $payload['_adspirit_form_kind'] = 'comercial';

        $submission_id = $form_kind . '-' . $form_id . '-' . time() . '-' . substr(md5(wp_json_encode($payload)), 0, 8);
        $endpoint = rtrim($core['endpoint_url'], '/') . '/api/webhooks/contact-form-7';

        // Fase 1: grava local ANTES do POST. Não bloqueia o envio.
        if (class_exists('AdSpirit_Lead_Store')) {
            AdSpirit_Lead_Store::record_pending($submission_id, $payload, $form_kind, (string) $form_id);
        }

        // Dispatcher canônico (Connector 3.0). Antes: blocking=false + status
        // 'sent' otimista sem saber o resultado. Agora: blocking 5s (mesmo
        // trade-off do CF7) + mark_crm_attempt → status verdadeiro, attempts,
        // last_error e retry automático pelo cron quando o POST falha.
        if (class_exists('AdSpirit_Lead_Store')) {
            $result = AdSpirit_Lead_Store::dispatch_to_crm($submission_id, $payload, 5);
            AdSpirit_Lead_Store::mark_crm_attempt($submission_id, $result);
            if (class_exists('AdSpirit_Cf7_Handler')) {
                AdSpirit_Cf7_Handler::log(
                    !empty($result['ok']) ? 'sent' : 'error',
                    (int) $result['code'],
                    !empty($result['ok']) ? null : (string) $result['error'],
                    array('form_id' => $form_kind . ':' . $form_id, 'fields' => array_keys($payload))
                );
            }
        } else {
            // Fallback raro (Lead_Store não carregou): fire-and-forget legado.
            wp_remote_post($endpoint, array(
                'timeout' => 8, 'blocking' => false,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'x-brand-slug' => $core['brand_slug'],
                    'x-cf7-secret' => $core['secret'],
                    'x-cf7-submission-id' => $submission_id,
                    'User-Agent' => 'AdSpirit-Connector/' . ADSPIRIT_CONNECTOR_VERSION,
                ),
                'body' => wp_json_encode($payload),
            ));
            if (class_exists('AdSpirit_Cf7_Handler')) {
                AdSpirit_Cf7_Handler::log('sent', 0, null, array('form_id' => $form_kind . ':' . $form_id, 'fields' => array_keys($payload)));
            }
        }

        // Fallback: alimenta o log legado (antes os adapters não entravam nele).
        $payload_with_form = array_merge($payload, array('_form_id' => $form_kind . ':' . $form_id));
        do_action('adspirit_lead_dispatched', $payload_with_form, null, $form_kind);
    }
}
