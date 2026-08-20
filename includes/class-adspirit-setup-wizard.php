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
                elseif ($it['status'] === 'off') $total_items--; // opcional parado não conta no placar
            }
        }
        $progress = $total_items > 0 ? round(($total_ok / $total_items) * 100) : 0;

        ?>
        <?php // Doutrina: DS-only (zero cor fora de token); OK é silencioso,
              // só warn/error carregam cor; seção 100% ok recolhe. ?>
        <div class="as-card" style="padding:18px 22px;">
            <div style="display:flex; align-items:center; gap:24px;">
                <div style="flex:1;">
                    <div style="font-size:13.5px; font-weight:600; margin-bottom:10px; color:var(--as-ink);">
                        <?php echo (int) $total_ok; ?> de <?php echo (int) $total_items; ?> itens prontos
                        <?php if ($total_error) : ?>
                            · <span style="color:var(--as-danger);"><?php echo (int) $total_error; ?> obrigatório(s) faltando</span>
                        <?php endif; ?>
                        <?php if ($total_warn) : ?>
                            · <span style="color:var(--as-warning);"><?php echo (int) $total_warn; ?> atenção</span>
                        <?php endif; ?>
                    </div>
                    <div style="background:var(--as-bg-subtle); border-radius:8px; height:6px; overflow:hidden;">
                        <div style="background:var(--as-accent); height:100%; width:<?php echo (int) $progress; ?>%; transition:width 320ms ease;"></div>
                    </div>
                </div>
                <div style="font-size:28px; font-weight:600; font-variant-numeric:tabular-nums; color:<?php echo $total_error > 0 ? 'var(--as-danger)' : 'var(--as-ink)'; ?>;">
                    <?php echo (int) $progress; ?>%
                </div>
            </div>
        </div>

        <?php foreach ($sections as $sec) :
            $sec_ok = true;
            foreach ($sec['items'] as $it2) {
                if (!in_array(($it2['status'] ?? ''), array('ok', 'off'), true)) { $sec_ok = false; break; }
            }
        ?>
            <?php if ($sec_ok) : ?>
                <details class="as-help" style="margin:0 0 14px;">
                    <summary><span class="as-badge ok" style="margin-right:8px;">✓</span><?php echo esc_html($sec['title']); ?></summary>
                    <ul class="adspirit-setup-list" style="margin-top:8px;">
                        <?php foreach ($sec['items'] as $item) : ?>
                            <li><span class="st-ic ok">✓</span>
                                <div style="flex:1;"><div class="st-label"><?php echo esc_html($item['label']); ?></div></div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </details>
            <?php else : ?>
            <div class="as-card" style="padding:18px 22px;">
                <h2 class="as-section" style="margin-top:0;"><span class="as-kicker-inline"><?php echo esc_html($sec['kicker']); ?></span><?php echo esc_html($sec['title']); ?></h2>
                <?php if (!empty($sec['intro'])) : ?>
                    <p class="as-section-help"><?php echo wp_kses_post($sec['intro']); ?></p>
                <?php endif; ?>

                <ul class="adspirit-setup-list">
                    <?php foreach ($sec['items'] as $item) :
                        $map = array('ok' => array('ok', '✓'), 'warn' => array('warn', '!'), 'off' => array('off', '·'));
                        list($st, $icon) = isset($map[$item['status']]) ? $map[$item['status']] : array('err', '✕');
                    ?>
                        <li>
                            <span class="st-ic <?php echo esc_attr($st); ?>"><?php echo esc_html($icon); ?></span>
                            <div style="flex:1;">
                                <div class="st-label"><?php echo esc_html($item['label']); ?></div>
                                <?php if (!empty($item['detail'])) : ?>
                                    <div class="st-detail"><?php echo wp_kses_post($item['detail']); ?></div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($item['cta_url']) && $item['status'] !== 'ok') : ?>
                                <a href="<?php echo esc_url($item['cta_url']); ?>" class="<?php echo $item['status'] === 'off' ? 'button-link' : 'button'; ?>" target="<?php echo esc_attr(!empty($item['cta_external']) ? '_blank' : '_self'); ?>"><?php echo esc_html($item['cta_label'] ?? 'Configurar'); ?></a>
                            <?php endif; ?>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php endif; ?>
        <?php endforeach; ?>

        <style>
            .adspirit-setup-list { margin: 8px 0 0; padding: 0; list-style: none; }
            .adspirit-setup-list li { display:flex; align-items:flex-start; gap:12px; padding:11px 0; border-bottom:1px solid var(--as-line); }
            .adspirit-setup-list li:last-child { border-bottom: none; }
            .adspirit-setup-list .st-label { font-weight:600; font-size:13.5px; color:var(--as-ink); }
            .adspirit-setup-list .st-detail { font-size:12.5px; color:var(--as-ink-faint); margin-top:3px; }
            /* OK é silencioso (tom suave); só problema carrega cor forte. */
            .adspirit-setup-list .st-ic {
                display:inline-flex; align-items:center; justify-content:center;
                width:22px; height:22px; border-radius:50%; flex-shrink:0;
                font-size:12px; font-weight:700; margin-top:1px;
            }
            .adspirit-setup-list .st-ic.ok  { background:var(--as-bg-success); color:var(--as-success); }
            .adspirit-setup-list .st-ic.warn{ background:var(--as-bg-warning); color:var(--as-warning); }
            .adspirit-setup-list .st-ic.err { background:var(--as-bg-danger);  color:var(--as-danger); }
            .adspirit-setup-list .st-ic.off { background:var(--as-bg-subtle);  color:var(--as-ink-faint); }
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

    /**
     * v2.30 — handshake de cobertura central (fatia 1). Pergunta ao AdSpirit
     * o que a marca JÁ TEM configurado do lado de lá (CAPI central, pixel,
     * domínios vinculados) pra reconciliar o checklist: "Coberto pelo
     * AdSpirit" em vez de pedir configuração duplicada. Propósito do plugin
     * é a conexão mágica — detectar o que foi feito à mão e complementar.
     * Cache 15 min; indisponível/CRM antigo (404) cacheia 5 min e o
     * checklist se comporta como antes (fail-soft). Nunca bloqueia render.
     */
    public static function central_status() {
        $cached = get_transient('adspirit_central_status');
        if (is_array($cached)) {
            return empty($cached['unavailable']) ? $cached : null;
        }
        $core = AdSpirit_Settings::get_core();
        if (empty($core['endpoint_url']) || empty($core['brand_slug']) || empty($core['secret'])) {
            return null; // sem conexão não há o que perguntar (e nada a cachear)
        }
        $url = rtrim((string) $core['endpoint_url'], '/')
            . '/api/wp/central-status?brand_slug=' . rawurlencode((string) $core['brand_slug']);
        $resp = wp_remote_get($url, array(
            'timeout' => 6,
            'headers' => array(
                'x-cf7-secret' => (string) $core['secret'],
                'User-Agent'   => 'AdSpirit-Connector/' . (defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : ''),
            ),
        ));
        $data = null;
        if (!is_wp_error($resp) && (int) wp_remote_retrieve_response_code($resp) === 200) {
            $body = json_decode((string) wp_remote_retrieve_body($resp), true);
            if (is_array($body) && isset($body['central']) && is_array($body['central'])) {
                $data = $body['central'];
            }
        }
        if ($data === null) {
            set_transient('adspirit_central_status', array('unavailable' => true), 300);
            return null;
        }
        set_transient('adspirit_central_status', $data, 900);
        return $data;
    }

    private function check_tracking() {
        $capi = method_exists('AdSpirit_Settings', 'get_capi_meta') ? AdSpirit_Settings::get_capi_meta() : array();
        $ga4 = method_exists('AdSpirit_Settings', 'get_ga4') ? AdSpirit_Settings::get_ga4() : array();
        $capi_url = admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=capi-meta');
        $ga4_url = admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=ga4');

        $items = array();

        // Handshake: o que a marca já tem configurado do lado do AdSpirit.
        $central = self::central_status();
        $c_capi_meta = is_array($central) && !empty($central['capi_meta']['covered']);
        $c_capi_goog = is_array($central) && !empty($central['capi_google']['covered']);
        $c_pixel_events = is_array($central) && isset($central['pixel']['events_30d'])
            ? (int) $central['pixel']['events_30d'] : 0;
        $c_pixel_mask = $c_capi_meta && !empty($central['capi_meta']['pixel_id_masked'])
            ? (string) $central['capi_meta']['pixel_id_masked'] : '';

        // Doutrina: opcional NÃO configurado é 'off' (silencioso) — âmbar é
        // reservado pra algo que pede ação de verdade. Com o handshake, o
        // que já foi configurado CENTRALMENTE aparece verde ("coberto") —
        // o setup feito à mão antes do plugin existir é detectado, não
        // pedido de novo.
        $capi_ok = class_exists('AdSpirit_Capi_Meta') && AdSpirit_Capi_Meta::is_configured($capi);
        if ($capi_ok) {
            $capi_detail = 'Pixel <code>' . esc_html($capi['pixel_id']) . '</code> · enviando do site'
                . ($c_capi_meta ? ' · também coberto centralmente pelo AdSpirit (dedup automático)' : '');
            $capi_status = 'ok';
        } elseif ($c_capi_meta) {
            $capi_detail = 'Coberto pelo AdSpirit (central' . ($c_pixel_mask !== '' ? ', pixel <code>' . esc_html($c_pixel_mask) . '</code>' : '') . ') — as conversões da marca disparam do CRM. Ativar o disparo deste site é opcional (dedup automático).';
            $capi_status = 'ok';
        } else {
            $capi_detail = 'Opcional — o AdSpirit pode disparar conversões centralmente pela marca. Ativar aqui adiciona o disparo do próprio site (recupera ~15-30% perdido por bloqueadores), com dedup automático entre os dois.';
            $capi_status = 'off';
        }
        $items[] = $this->item(
            $capi_status,
            'Conversões Meta',
            $capi_detail,
            $capi_url,
            $capi_ok ? 'Editar' : 'Configurar'
        );

        if ($c_capi_goog) {
            $items[] = $this->item(
                'ok',
                'Conversões Google Ads',
                'Coberto pelo AdSpirit (central) — leads e vendas da marca são reportados ao Google Ads pelo CRM. Nada a fazer neste site.',
                '',
                ''
            );
        }

        $ga4_ok = !empty($ga4['enabled']) && $ga4['enabled'] === '1'
            && !empty($ga4['measurement_id']) && !empty($ga4['api_secret']);
        $items[] = $this->item(
            $ga4_ok ? 'ok' : 'off',
            'Conversões Google (deste site)',
            $ga4_ok
                ? 'Stream <code>' . esc_html($ga4['measurement_id']) . '</code> · enviando do site'
                : 'Opcional — necessário só se a página de obrigado deste site dispara conversão direto no GA4.',
            $ga4_url,
            $ga4_ok ? 'Editar' : 'Configurar'
        );

        $core_check = AdSpirit_Settings::get_core();
        $pixel_status = !empty($core_check['pixel_enabled']) && $core_check['pixel_enabled'] === '1';
        // v2.10: Turnstile como opcional (recomendado pra anti-bot avançado)
        $turnstile_active = class_exists('AdSpirit_Turnstile') && AdSpirit_Turnstile::is_active();
        $items[] = $this->item(
            $turnstile_active ? 'ok' : 'off',
            'Verificação Cloudflare (anti-bot invisível)',
            $turnstile_active
                ? 'Ativa. Pega bots sofisticados que as camadas básicas não pegam.'
                : 'Opcional e grátis — camada extra recomendada se o site sofrer com bots que passam pelo anti-spam.',
            admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=turnstile'),
            $turnstile_active ? 'Editar' : 'Configurar'
        );

        if ($pixel_status) {
            $px_st = 'ok';
            $px_detail = 'Injetado pelo plugin. Atenção pra não duplicar com um pixel colado manualmente no tema.';
        } elseif ($c_pixel_events > 0) {
            // A marca registra eventos no AdSpirit sem o plugin injetar nada
            // — instalação manual (pré-plugin) ou outro site da marca.
            $px_st = 'ok';
            $px_detail = 'Rastreamento ativo na marca (' . number_format_i18n($c_pixel_events) . ' eventos/30d no AdSpirit) — provavelmente instalado manualmente antes do plugin. Ligar aqui só se quiser que o plugin gerencie a injeção (sem duplicar).';
        } else {
            $px_st = 'off';
            $px_detail = 'Desligado no plugin. Se o rastreio já está colado manualmente no tema, tudo certo — não duplicar.';
        }
        $items[] = $this->item(
            $px_st,
            'Rastreador no site (pixel)',
            $px_detail,
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
