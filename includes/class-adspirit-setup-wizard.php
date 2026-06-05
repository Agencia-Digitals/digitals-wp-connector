<?php
/**
 * AdSpirit Setup Wizard — checklist visual de configuração do plugin.
 *
 * Sit on top of TABS do menu admin como aba "Setup" (grupo Geral, default).
 * Rende uma checklist em 4 seções com status (verde/amarelo/vermelho) +
 * ação imediata pra cada item.
 *
 * Pra cada install novo de site, é a primeira aba que o front-end dev
 * deve ver — substitui a checklist manual de plugins do fluxo antigo.
 *
 * Detecta:
 *   1. Conexão CRM (brand_slug + secret válidos)
 *   2. Tracking server-side (CAPI Meta + GA4 configurados)
 *   3. Plugins concorrentes (warn pra evitar double-dispatch)
 *   4. Forms status (via Field_Mapping)
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Setup_Wizard {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('adspirit_connector_render_tab_setup',
            AdSpirit_Safe_Hook::action(array($this, 'render_tab'), 'setup_wizard_tab'));
    }

    public function render_tab() {
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) return;

        $sections = array(
            $this->check_connection(),
            $this->check_tracking(),
            $this->check_competing_plugins(),
            $this->check_forms(),
        );

        $total_ok = 0;
        $total_warn = 0;
        $total_error = 0;
        $total_items = 0;
        foreach ($sections as $sec) {
            foreach ($sec['items'] as $it) {
                $total_items++;
                if ($it['status'] === 'ok') $total_ok++;
                elseif ($it['status'] === 'warn') $total_warn++;
                elseif ($it['status'] === 'error') $total_error++;
            }
        }
        $progress = $total_items > 0 ? round(($total_ok / $total_items) * 100) : 0;

        ?>
        <div class="as-card">
            <h2 class="as-section"><span class="as-kicker-inline">Setup</span>Checklist de configuração</h2>
            <p class="as-section-help">
                Status de cada parte do plugin. Verde = configurado e funcionando.
                Amarelo = atenção (ex.: plugin concorrente ativo). Vermelho = falta config obrigatória.
            </p>

            <div style="display:flex; align-items:center; gap:24px; margin: 20px 0; padding:16px; background:#f6f7f7; border-radius:6px;">
                <div style="flex:1;">
                    <div style="font-size:14px; font-weight:600; margin-bottom:8px;">
                        <?php echo (int) $total_ok; ?> de <?php echo (int) $total_items; ?> itens OK
                        <?php if ($total_error) : ?>
                            · <span style="color:#dc3545;"><?php echo (int) $total_error; ?> obrigatório(s) faltando</span>
                        <?php endif; ?>
                        <?php if ($total_warn) : ?>
                            · <span style="color:#d39e00;"><?php echo (int) $total_warn; ?> aviso(s)</span>
                        <?php endif; ?>
                    </div>
                    <div style="background:#e5e5e5; border-radius:8px; height:8px; overflow:hidden;">
                        <div style="background:linear-gradient(90deg,#28a745,#5cb85c); height:100%; width:<?php echo (int) $progress; ?>%; transition:width 320ms ease;"></div>
                    </div>
                </div>
                <div style="font-size:36px; font-weight:700; color:<?php echo $total_error > 0 ? '#dc3545' : ($total_warn > 0 ? '#d39e00' : '#28a745'); ?>;">
                    <?php echo (int) $progress; ?>%
                </div>
            </div>
        </div>

        <?php foreach ($sections as $sec) : ?>
            <div class="as-card" style="margin-top:16px;">
                <h2 class="as-section"><span class="as-kicker-inline"><?php echo esc_html($sec['kicker']); ?></span><?php echo esc_html($sec['title']); ?></h2>
                <?php if (!empty($sec['intro'])) : ?>
                    <p class="as-section-help"><?php echo wp_kses_post($sec['intro']); ?></p>
                <?php endif; ?>

                <ul class="adspirit-setup-list">
                    <?php foreach ($sec['items'] as $item) :
                        $color = $item['status'] === 'ok' ? '#28a745' : ($item['status'] === 'warn' ? '#d39e00' : '#dc3545');
                        $icon = $item['status'] === 'ok' ? '✓' : ($item['status'] === 'warn' ? '⚠' : '✗');
                    ?>
                        <li style="display:flex; align-items:flex-start; gap:14px; padding:12px 0; border-bottom:1px solid #f0f0f0;">
                            <span style="display:inline-flex; align-items:center; justify-content:center; width:28px; height:28px; border-radius:50%; background:<?php echo esc_attr($color); ?>; color:white; font-weight:700; flex-shrink:0; font-size:14px;"><?php echo esc_html($icon); ?></span>
                            <div style="flex:1;">
                                <div style="font-weight:600; font-size:14px;"><?php echo esc_html($item['label']); ?></div>
                                <?php if (!empty($item['detail'])) : ?>
                                    <div style="font-size:12.5px; color:#555; margin-top:3px;"><?php echo wp_kses_post($item['detail']); ?></div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($item['cta_url'])) : ?>
                                <a href="<?php echo esc_url($item['cta_url']); ?>" class="button" target="<?php echo esc_attr(!empty($item['cta_external']) ? '_blank' : '_self'); ?>"><?php echo esc_html($item['cta_label'] ?? 'Configurar'); ?></a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endforeach; ?>

        <style>
            .adspirit-setup-list { margin: 8px 0 0; padding: 0; list-style: none; }
            .adspirit-setup-list li:last-child { border-bottom: none !important; }
        </style>
        <?php
    }

    private function check_connection() {
        $core = AdSpirit_Settings::get_core();
        $cfg_url = admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=connection');

        $items = array();
        $items[] = $this->item(
            !empty($core['brand_slug']) && !empty($core['secret']) ? 'ok' : 'error',
            'Conexão com CRM (brand_slug + secret)',
            !empty($core['brand_slug'])
                ? 'Brand: <code>' . esc_html($core['brand_slug']) . '</code>'
                : 'Sem brand_slug. Cole o brand_slug e secret gerados em <code>/settings/integrations</code> do CRM.',
            !empty($core['brand_slug']) ? null : $cfg_url,
            'Configurar'
        );

        $items[] = $this->item(
            !empty($core['endpoint_url']) ? 'ok' : 'error',
            'Endpoint URL do CRM',
            !empty($core['endpoint_url'])
                ? '<code>' . esc_html($core['endpoint_url']) . '</code>'
                : 'Endpoint padrão é <code>https://crm.agenciadigitals.com.br</code>.',
            null
        );

        $items[] = $this->item(
            !empty($core['cf7_enabled']) && $core['cf7_enabled'] === '1' ? 'ok' : 'warn',
            'Captura de Contact Form 7',
            !empty($core['cf7_enabled']) && $core['cf7_enabled'] === '1'
                ? 'CF7 envia leads pro CRM ao submit.'
                : 'CF7 captura desligada. Ative pra que leads cheguem no CRM.',
            $cfg_url,
            'Configurar'
        );

        return array(
            'kicker' => 'Etapa 1',
            'title' => 'Conexão com o CRM',
            'intro' => 'Pré-requisito pra qualquer outra coisa funcionar.',
            'items' => $items,
        );
    }

    private function check_tracking() {
        $capi = method_exists('AdSpirit_Settings', 'get_capi_meta') ? AdSpirit_Settings::get_capi_meta() : array();
        $ga4 = method_exists('AdSpirit_Settings', 'get_ga4') ? AdSpirit_Settings::get_ga4() : array();
        $capi_url = admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=capi-meta');
        $ga4_url = admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=ga4');

        $items = array();

        $capi_ok = !empty($capi['enabled']) && $capi['enabled'] === '1'
            && !empty($capi['pixel_id']) && !empty($capi['access_token']);
        $items[] = $this->item(
            $capi_ok ? 'ok' : 'warn',
            'Meta Conversions API (server-side)',
            $capi_ok
                ? 'Pixel <code>' . esc_html($capi['pixel_id']) . '</code> · CAPI ativa'
                : 'Configure <code>pixel_id</code> e <code>access_token</code>. Resolve ~15-30% de subnotificação por ad-blockers / iOS Private. Plus dedup automático com Pixel browser-side via <code>event_id</code>.',
            $capi_url,
            $capi_ok ? 'Editar' : 'Configurar'
        );

        $ga4_ok = !empty($ga4['enabled']) && $ga4['enabled'] === '1'
            && !empty($ga4['measurement_id']) && !empty($ga4['api_secret']);
        $items[] = $this->item(
            $ga4_ok ? 'ok' : 'warn',
            'GA4 Measurement Protocol (server-side)',
            $ga4_ok
                ? 'Stream <code>' . esc_html($ga4['measurement_id']) . '</code> · server-side ativo'
                : 'Configure <code>measurement_id</code> e <code>api_secret</code>. Necessário pro <code>[adspirit_thank_you]</code> disparar conversão server-side em GA4.',
            $ga4_url,
            $ga4_ok ? 'Editar' : 'Configurar'
        );

        $pixel_browser = !empty($core['pixel_enabled']) && $core['pixel_enabled'] === '1';
        $core_check = AdSpirit_Settings::get_core();
        $pixel_status = !empty($core_check['pixel_enabled']) && $core_check['pixel_enabled'] === '1';
        $items[] = $this->item(
            $pixel_status ? 'ok' : 'warn',
            'Pixel Meta (browser-side)',
            $pixel_status
                ? 'Pixel injetado no <code>&lt;head&gt;</code> via plugin. Cuidado com Pixel duplicado em Code Block manual.'
                : 'Pixel browser-side desligado no plugin. Se já está injetado via Code Block manual, OK — não duplicar.',
            admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=connection'),
            'Configurar'
        );

        return array(
            'kicker' => 'Etapa 2',
            'title' => 'Tracking server-side',
            'intro' => 'Resolve subnotificação de conversões causada por ad-blockers, iOS Private, privacy modes. Configurar ao menos CAPI Meta.',
            'items' => $items,
        );
    }

    private function check_competing_plugins() {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }

        // Slugs conhecidos de plugins redundantes com AdSpirit Connector.
        // Múltiplas entradas por plugin pra cobrir variações de package.
        $competitors = array(
            'CF7 Extras (redirect + analytics)' => array(
                'slugs' => array('contact-form-7-extras/contact-form-7-extras.php'),
                'replaced_by' => 'Form qualifier (redirect dinâmico por perfil) + CAPI Meta/GA4 events server-side',
            ),
            'TablePress (planilha de leads)' => array(
                'slugs' => array('tablepress/tablepress.php'),
                'replaced_by' => 'Aba "Submissões recentes" + CRM como source of truth (\'/leads\')',
            ),
            'Bit Integrations (fan-out CF7)' => array(
                'slugs' => array(
                    'bit-integrations/bit-integrations.php',
                    'bit-integration/bit-integration.php',
                ),
                'replaced_by' => 'AdSpirit → Integrações → Webhook out',
            ),
            'WP Armour (anti-spam)' => array(
                'slugs' => array(
                    'wp-armour/wp-armour.php',
                    'honeypot-anti-spam/wp-armour.php',
                ),
                'replaced_by' => 'AdSpirit Anti-Spam (paridade na v2.9.0)',
                'soft_warn' => true, // Não recomenda desativar até v2.9.0 sair
            ),
            'Meta Pixel WP (Pixel browser only)' => array(
                'slugs' => array(
                    'official-facebook-pixel/facebook-for-wordpress.php',
                    'facebook-for-wordpress/facebook-for-wordpress.php',
                    'pixel-caffeine/pixel-caffeine.php',
                ),
                'replaced_by' => 'AdSpirit Pixel Injector + CAPI Meta (browser + server-side)',
            ),
        );

        $items = array();
        $active_count = 0;
        foreach ($competitors as $name => $info) {
            $active = false;
            foreach ($info['slugs'] as $slug) {
                if (is_plugin_active($slug)) { $active = true; break; }
            }
            if ($active) {
                $active_count++;
                $status = !empty($info['soft_warn']) ? 'warn' : 'warn';
                $detail = !empty($info['soft_warn'])
                    ? 'Mantenha por enquanto. Substituído por: ' . esc_html($info['replaced_by'])
                    : 'Risco de double-dispatch (conversão contada 2x, lead duplicado). Substituído por: ' . esc_html($info['replaced_by']);
                $items[] = $this->item(
                    $status,
                    $name . ' está ATIVO',
                    $detail,
                    admin_url('plugins.php'),
                    'Gerenciar plugins'
                );
            }
        }

        if (empty($items)) {
            $items[] = $this->item(
                'ok',
                'Nenhum plugin concorrente detectado',
                'Stack limpo. AdSpirit Connector é o único responsável por captura + tracking.',
                null
            );
        }

        return array(
            'kicker' => 'Etapa 3',
            'title' => 'Plugins concorrentes',
            'intro' => 'Plugins do fluxo antigo que ficam redundantes com AdSpirit. Risco de double-dispatch / duplicação de leads se mantiver. Ver <a href="https://github.com/Agencia-Digitals/AGD-CRM/blob/main/docs/setup-wp-with-adspirit.md" target="_blank" rel="noopener">runbook de migração</a>.',
            'items' => $items,
        );
    }

    private function check_forms() {
        $forms_url = admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=forms');
        $items = array();

        if (!class_exists('WPCF7_ContactForm')) {
            $items[] = $this->item('warn', 'Contact Form 7 não está instalado',
                'Sem CF7, leads chegam só via shortcodes <code>[adspirit_form_qualifier]</code>.',
                admin_url('plugin-install.php?s=contact+form+7&tab=search&type=term'),
                'Instalar CF7'
            );
            return array('kicker' => 'Etapa 4', 'title' => 'Forms', 'intro' => '', 'items' => $items);
        }

        $forms = WPCF7_ContactForm::find(array('posts_per_page' => 50));
        if (empty($forms)) {
            $items[] = $this->item('warn', 'Nenhum form CF7 criado',
                'Crie um em <code>Contact → Forms</code> usando field names canônicos (your-name, your-email, Telefone, etc).',
                admin_url('admin.php?page=wpcf7-new'),
                'Criar form'
            );
            return array('kicker' => 'Etapa 4', 'title' => 'Forms', 'intro' => '', 'items' => $items);
        }

        $fm = class_exists('AdSpirit_Field_Mapping') ? AdSpirit_Field_Mapping::instance() : null;
        if (!$fm || !method_exists($fm, 'form_match_status')) {
            $items[] = $this->item('ok', count($forms) . ' form(s) detectado(s)',
                'Field mapping disponível na aba Forms.', $forms_url, 'Ver forms');
            return array('kicker' => 'Etapa 4', 'title' => 'Forms', 'intro' => '', 'items' => $items);
        }

        $total_unmatched = 0;
        $forms_with_issues = array();
        foreach ($forms as $f) {
            $st = $fm->form_match_status($f);
            if ($st['unmatched_count'] > 0) {
                $forms_with_issues[] = $f->title() . ' (' . $st['matched_count'] . '/' . $st['total'] . ')';
                $total_unmatched += $st['unmatched_count'];
            }
        }

        if (empty($forms_with_issues)) {
            $items[] = $this->item('ok',
                'Todos os ' . count($forms) . ' form(s) com campos reconhecidos',
                'Field names canônicos batem direto — sem necessidade de mapping manual.',
                null
            );
        } else {
            $items[] = $this->item('warn',
                count($forms_with_issues) . ' form(s) com ' . $total_unmatched . ' campo(s) não-mapeado(s)',
                'Forms: ' . esc_html(implode(', ', $forms_with_issues)) . '. Configure aliases na aba Forms ou renomeie pros canônicos.',
                $forms_url,
                'Configurar mapping'
            );
        }

        return array(
            'kicker' => 'Etapa 4',
            'title' => 'Forms',
            'intro' => '',
            'items' => $items,
        );
    }

    private function item($status, $label, $detail = '', $cta_url = null, $cta_label = 'Configurar', $cta_external = false) {
        return array(
            'status' => $status,
            'label' => $label,
            'detail' => $detail,
            'cta_url' => $cta_url,
            'cta_label' => $cta_label,
            'cta_external' => $cta_external,
        );
    }
}
