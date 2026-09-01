<?php
/**
 * AdSpirit Connector — Visão geral (overview tab).
 *
 * Dashboard com:
 *   1. Checklist visual de onboarding (5-7 steps)
 *   2. Próxima ação (computada dinamicamente)
 *   3. Métricas resumidas (24h/7d/30d submits + taxa sucesso)
 *   4. Forms CF7 detectados (com link pra mapping)
 *   5. Plugins relevantes (CF7 instalado? versão? WP Armour?)
 *   6. Botão de teste de conexão
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Status {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action(
            'adspirit_connector_render_tab_overview',
            AdSpirit_Safe_Hook::action(array($this, 'render'), 'status_overview')
        );
        add_action(
            'wp_ajax_adspirit_test_connection',
            AdSpirit_Safe_Hook::action(array($this, 'ajax_test_connection'), 'status_test_connection')
        );
    }

    public function render() {
        $core = AdSpirit_Settings::get_core();
        $checklist = $this->compute_checklist($core);
        $next_action = $this->compute_next_action($checklist);
        $metrics = AdSpirit_Health_Checker::summarize();
        $forms = $this->discover_cf7_forms();
        $env = $this->detect_environment();

        // Feedback do test event (redirect de send_test_event)
        $test_result = isset($_GET['test_result']) ? sanitize_key((string) $_GET['test_result']) : '';
        $connected_just = !empty($_GET['connected']);
        ?>
        <?php if ($connected_just): ?>
            <div class="as-notice info">
                <div class="as-notice-kicker">Conectado</div>
                <p>Plugin vinculado ao AdSpirit com sucesso. Brand <strong><?php echo esc_html($core['brand_name'] ?: $core['brand_slug']); ?></strong>. Já pode submeter formulários.</p>
            </div>
        <?php endif; ?>
        <?php if ($test_result): ?>
            <?php if ($test_result === 'sucesso'): ?>
                <div class="as-notice info">
                    <div class="as-notice-kicker">Teste enviado</div>
                    <p>Lead de teste foi aceito pelo CRM (HTTP 200) e arquivado automaticamente. Confira em <a href="<?php echo esc_url($core['endpoint_url']); ?>/leads?archived=1" target="_blank">/leads</a> no AdSpirit.</p>
                </div>
            <?php elseif ($test_result === 'config_incompleta'): ?>
                <div class="as-notice warn">
                    <div class="as-notice-kicker">Config faltando</div>
                    <p>Conecte o plugin primeiro, em <em>Conexão com o AdSpirit</em>.</p>
                </div>
            <?php else: ?>
                <div class="as-notice danger">
                    <div class="as-notice-kicker">Falha no teste</div>
                    <p>O AdSpirit respondeu: <code><?php echo esc_html($test_result); ?></code>. Veja os logs.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($next_action): ?>
            <div class="as-notice info">
                <div class="as-notice-kicker">Próxima ação</div>
                <p><?php echo wp_kses_post($next_action); ?></p>
            </div>
        <?php endif; ?>

        <?php
        // Doutrina: hierarquia muda com o estado. Tudo configurado → o
        // checklist recolhe numa linha (o dia a dia é a métrica, não o
        // onboarding). Algo faltando → o checklist É a tarefa e domina.
        $all_done = true;
        foreach ($checklist as $it) {
            if (($it['status'] ?? '') !== 'done') { $all_done = false; break; }
        }
        ?>
        <?php if ($all_done): ?>
            <details class="as-help" style="margin: 0 0 24px;">
                <summary><span class="as-badge ok" style="margin-right:8px;">✓</span>Site conectado e enviando — ver checklist</summary>
                <ul class="as-checklist" style="margin-top:12px;">
                    <?php foreach ($checklist as $item): ?>
                        <li>
                            <span class="icon done"><?php echo self::status_icon('done'); ?></span>
                            <div class="body"><div class="title"><?php echo esc_html($item['title']); ?></div></div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php else: ?>
        <h2 class="as-section"><span class="as-kicker-inline">Onboarding</span>Conectar o site ao AdSpirit</h2>
        <p class="as-section-help">Checklist do que falta pra ferramenta estar 100% conectada e enviando leads.</p>

        <ul class="as-checklist">
            <?php foreach ($checklist as $item): ?>
                <li>
                    <span class="icon <?php echo esc_attr($item['status']); ?>">
                        <?php echo self::status_icon($item['status']); ?>
                    </span>
                    <div class="body">
                        <div class="title"><?php echo esc_html($item['title']); ?></div>
                        <div class="desc"><?php echo wp_kses_post($item['desc']); ?></div>
                        <?php if (!empty($item['cta_url']) && $item['status'] !== 'done'): ?>
                            <div class="cta">
                                <a href="<?php echo esc_url($item['cta_url']); ?>" class="button button-primary">
                                    <?php echo esc_html($item['cta_label'] ?? 'Configurar'); ?>
                                </a>
                            </div>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
        <?php endif; ?>

        <h2 class="as-section"><span class="as-kicker-inline">Últimos 30 dias</span>Leads deste site</h2>
        <?php
        // Número que descreve um problema tem que levar até ele. Antes o
        // painel dizia "2 falhas de envio" e parava aí: a pessoa via o
        // problema e não tinha o que clicar.
        $subs = function ($status = '') {
            $args = array('page' => AdSpirit_Menu::PAGE_SLUG, 'tab' => 'submissions');
            if ($status !== '') $args['sl_status'] = $status;
            return admin_url('admin.php?' . http_build_query($args));
        };
        ?>
        <div class="as-metric-grid">
            <a class="as-metric as-metric-link" href="<?php echo esc_url($subs('sent')); ?>">
                <div class="label">Leads enviados</div>
                <div class="value"><?php echo esc_html($metrics['cf7_sent_30d']); ?></div>
                <div class="sub"><?php echo esc_html($metrics['cf7_sent_24h']); ?> nas últimas 24h</div>
            </a>
            <a class="as-metric as-metric-link" href="<?php echo esc_url($subs('problemas')); ?>">
                <div class="label">Falhas de envio</div>
                <div class="value <?php echo $metrics['cf7_failed_30d'] > 0 ? 'danger' : ''; ?>"><?php echo esc_html($metrics['cf7_failed_30d']); ?></div>
                <div class="sub"><?php echo esc_html($metrics['cf7_failed_24h']); ?> nas últimas 24h</div>
            </a>
            <div class="as-metric">
                <div class="label">Taxa de sucesso</div>
                <div class="value"><?php echo esc_html($metrics['success_rate']); ?>%</div>
                <div class="sub"><?php echo $metrics['cf7_sent_30d'] + $metrics['cf7_failed_30d']; ?> tentativas</div>
            </div>
            <a class="as-metric as-metric-link" href="<?php echo esc_url($subs('spam')); ?>">
                <div class="label">Bloqueados como spam</div>
                <div class="value"><?php echo esc_html($metrics['antispam_blocked_30d']); ?></div>
                <div class="sub"><?php echo esc_html($metrics['antispam_blocked_24h']); ?> nas últimas 24h</div>
            </a>
            <a class="as-metric as-metric-link" href="<?php echo esc_url($subs('')); ?>">
                <div class="label">Último lead</div>
                <div class="value text"><?php echo esc_html($metrics['last_cf7_at_human'] ?: 'nunca'); ?></div>
                <div class="sub"><?php echo esc_html($metrics['last_cf7_at_iso'] ?: ''); ?></div>
            </a>
            <?php // Doutrina: tile de erro SÓ quando há erro — "nenhum" é ruído. ?>
            <?php if (!empty($metrics['last_error'])): ?>
            <a class="as-metric as-metric-link" href="<?php echo esc_url($subs('problemas')); ?>">
                <div class="label">Último erro</div>
                <div class="value text danger" style="font-size:13px;">
                    <?php echo esc_html($metrics['last_error']); ?>
                </a>
            </div>
            <?php endif; ?>
        </div>

        <?php
        // Reestruturação 08-18: os últimos leads capturados moram na porta
        // de entrada — abrir o painel e VER os leads, sem caçar aba.
        $recent_leads = (class_exists('AdSpirit_Lead_Store') && AdSpirit_Lead_Store::available())
            ? AdSpirit_Lead_Store::query(8, array())
            : array();
        ?>
        <?php if (!empty($recent_leads)) : ?>
        <h2 class="as-section"><span class="as-kicker-inline">Capturados agora</span>Últimos leads</h2>
        <table class="as-table">
            <thead>
                <tr><th>Quando</th><th>Contato</th><th>Origem</th><th>Status</th><th>Perfil</th></tr>
            </thead>
            <tbody>
                <?php foreach ($recent_leads as $rl) :
                    $rl_status = (string) ($rl['status'] ?? '');
                    // A linha leva ATÉ o lead. Antes a lista mostrava "Spam"
                    // e não dava pra abrir e ver quem era — a pessoa via o
                    // problema e tinha que ir procurar noutra tela.
                    $rl_busca = (string) ($rl['email'] ?: ($rl['phone'] ?: ($rl['name'] ?: '')));
                    $rl_url = admin_url('admin.php?' . http_build_query(array_filter(array(
                        'page' => AdSpirit_Menu::PAGE_SLUG,
                        'tab' => 'submissions',
                        'sl_search' => $rl_busca,
                        'sl_status' => in_array($rl_status, array('sent', 'failed', 'spam', 'pending'), true) ? $rl_status : '',
                    ))));
                    $rl_cls = $rl_status === 'sent' ? 'ok' : ($rl_status === 'spam' ? 'muted' : ($rl_status === 'failed' ? 'danger' : 'warn'));
                    $rl_label = $rl_status === 'sent' ? 'Entregue' : ($rl_status === 'spam' ? 'Spam' : ($rl_status === 'failed' ? 'Falhou' : 'Pendente'));
                ?>
                <tr class="as-row-link" onclick="window.location='<?php echo esc_js($rl_url); ?>'">
                    <td style="white-space:nowrap;"><?php echo !empty($rl['created_at']) ? esc_html(get_date_from_gmt((string) $rl['created_at'], 'd/m H:i')) : '—'; ?></td>
                    <td>
                        <a href="<?php echo esc_url($rl_url); ?>" title="Abrir este lead na lista de envios"><?php echo esc_html((string) ($rl['name'] ?: ($rl['email'] ?: $rl['phone'] ?: '—'))); ?></a>
                        <?php if (!empty($rl['name']) && !empty($rl['email'])) : ?><br><small style="opacity:.7;"><?php echo esc_html((string) $rl['email']); ?></small><?php endif; ?>
                    </td>
                    <td><small><?php echo esc_html((string) ($rl['source'] ?? '')); ?></small></td>
                    <td><span class="as-badge <?php echo esc_attr($rl_cls); ?>"><?php echo esc_html($rl_label); ?></span></td>
                    <td><?php echo !empty($rl['profile']) ? '<span class="as-badge accent">' . esc_html((string) $rl['profile']) . '</span>' : '<span style="opacity:.4;">—</span>'; ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p style="margin:6px 0 0;"><a href="<?php echo esc_url(admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=submissions')); ?>" class="button-link">Ver todos os leads enviados →</a></p>
        <?php endif; ?>

        <?php // "O que o site tem hoje" saiu daqui (Pedro 08-20): mora na
        // aba Visão geral de Formulários, junto com o que é sobre
        // formulário. Início é sobre LEADS. ?>

        <?php
        // Doutrina: ambiente/proteções são consulta RARA — recolhidos por
        // padrão. O que precisa de atenção sobe como aviso enxuto.
        $safe_mode = class_exists('AdSpirit_Safe_Bootstrap') && AdSpirit_Safe_Bootstrap::is_safe_mode();
        $crashes = class_exists('AdSpirit_Crash_Tracker') ? AdSpirit_Crash_Tracker::get_log() : array();
        $recent_crashes = 0;
        $cwindow = time() - 86400;
        foreach ($crashes as $c) {
            if (($c['at'] ?? 0) >= $cwindow) $recent_crashes++;
        }
        ?>
        <?php
        // Atraso de versão: o site não tem como saber que está parado no
        // tempo, e o silêncio se parece com "está em dia". Fica ANTES do
        // aviso geral porque quem está abaixo da 2.28 não recebe correção
        // nenhuma — inclusive as de captação de lead.
        $atraso = class_exists('AdSpirit_Quickwins') && method_exists('AdSpirit_Quickwins', 'atraso_de_versao')
            ? AdSpirit_Quickwins::atraso_de_versao() : null;
        if (is_array($atraso) && ($atraso['sem_auto_update'] || $atraso['minor_atras'] >= 3)): ?>
            <div class="as-notice <?php echo $atraso['sem_auto_update'] ? 'danger' : 'warn'; ?>" style="margin:10px 0 16px;">
                <p style="margin:0;">
                    <strong>Este site está na versão <?php echo esc_html($atraso['instalada']); ?>,
                    e a atual é a <?php echo esc_html($atraso['publicada']); ?>.</strong>
                    <?php if ($atraso['sem_auto_update']): ?>
                        Versões anteriores à 2.28 não têm atualização automática — este site
                        <strong>não vai se atualizar sozinho</strong>. Atualize uma vez pelo painel de plugins
                        e daí em diante ele passa a acompanhar.
                    <?php else: ?>
                        A atualização automática parece não estar acontecendo.
                        <a href="<?php echo esc_url(admin_url('plugins.php')); ?>">Atualizar agora</a>.
                    <?php endif; ?>
                </p>
            </div>
        <?php endif; ?>
        <?php if ($env['wp_armour'] || $safe_mode || $recent_crashes > 0): ?>
            <div class="as-notice warn"><p>
                <?php if ($env['wp_armour']): ?>WP Armour é redundante — o anti-spam embutido cobre tudo; pode desinstalar. <?php endif; ?>
                <?php if ($safe_mode): ?>Plugin em modo de segurança (features desligadas, site intocado). <?php endif; ?>
                <?php if ($recent_crashes > 0): ?><?php echo (int) $recent_crashes; ?> erro(s) capturado(s) nas últimas 24h — <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=logs')); ?>">ver diagnóstico</a>.<?php endif; ?>
            </p></div>
        <?php endif; ?>

        <details class="as-help">
        <summary>Ambiente e proteções</summary>
        <table class="as-table" style="max-width:720px; margin-top:10px;">
            <tr>
                <th style="width:240px;">Contact Form 7</th>
                <td>
                    <?php if ($env['cf7_installed']): ?>
                        <span class="as-badge ok">Ativo</span> v<?php echo esc_html($env['cf7_version']); ?>
                    <?php else: ?>
                        <span class="as-badge">Não instalado</span>
                        <span class="as-field-help">Opcional — o formulário do AdSpirit captura sem ele.</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>WP Armour (anti-spam externo)</th>
                <td>
                    <?php if ($env['wp_armour']): ?>
                        <span class="as-badge warn">Redundante</span>
                        <span class="as-field-help" style="display:inline; margin-left:8px;">— pode desinstalar: o anti-spam embutido cobre 100% do escopo + rate-limit.</span>
                    <?php else: ?>
                        <span class="as-badge ok">Não necessário</span>
                        <span class="as-field-help" style="display:inline; margin-left:8px;">— anti-spam embutido cobre tudo que o WP Armour faz.</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>WordPress</th>
                <td><code>v<?php echo esc_html(get_bloginfo('version')); ?></code></td>
            </tr>
            <tr>
                <th>PHP</th>
                <td><code>v<?php echo esc_html(PHP_VERSION); ?></code></td>
            </tr>
            <tr>
                <th>Endpoint configurado</th>
                <td><code><?php echo esc_html($core['endpoint_url'] . '/api/webhooks/contact-form-7'); ?></code></td>
            </tr>
        </table>

        <table class="as-table" style="max-width:720px; margin-top:14px;">
            <tr>
                <th style="width:240px;">Modo de operação</th>
                <td>
                    <?php if ($safe_mode): ?>
                        <span class="as-badge danger">Safe Mode</span>
                        <span class="as-field-help" style="display:inline; margin-left:8px;">features desligadas, site intocado</span>
                    <?php else: ?>
                        <span class="as-badge ok">Normal</span>
                        <span class="as-field-help" style="display:inline; margin-left:8px;">todas as features ativas</span>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Crashes capturados (24h)</th>
                <td>
                    <?php if ($recent_crashes === 0): ?>
                        <span class="as-badge ok">0</span>
                        <span class="as-field-help" style="display:inline; margin-left:8px;">nenhuma exceção silenciada</span>
                    <?php else: ?>
                        <span class="as-badge <?php echo $recent_crashes >= 3 ? 'danger' : 'warn'; ?>"><?php echo esc_html($recent_crashes); ?></span>
                        <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=logs')); ?>" style="margin-left:8px;">ver logs</a>
                        <?php if ($recent_crashes >= 3): ?>
                            <span class="as-field-help" style="display:inline; margin-left:8px;">acima do threshold, Safe Mode pode ter sido ativado</span>
                        <?php endif; ?>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th>Auto-recuperação</th>
                <td>
                    <span class="as-badge ok">Ativa</span>
                    <span class="as-field-help" style="display:inline; margin-left:8px;">se 3+ erros em 5 min, plugin entra em Safe Mode automaticamente</span>
                </td>
            </tr>
            <tr>
                <th>Compatibilidade mínima</th>
                <td>WP <code>6.0+</code> · PHP <code>7.4+</code> — validado no boot e na ativação</td>
            </tr>
        </table>
        </details>

        <?php // Doutrina: testar é ação, não seção — rodapé compacto. ?>
        <div class="as-actions">
            <button type="button" class="button button-primary" id="adspirit-test-btn"><?php echo self::icon('zap'); ?> Testar conexão agora</button>
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                <input type="hidden" name="action" value="adspirit_send_test_event">
                <?php wp_nonce_field('adspirit_send_test_event'); ?>
                <button type="submit" class="button">Disparar lead de teste</button>
            </form>
            <span style="font-size:12px; color:var(--as-ink-faint);">valida a conexão sem criar lead de verdade</span>
        </div>
        <pre class="as-test-result" id="adspirit-test-result" style="display:none; margin-top:12px;"></pre>

        <script>
        (function() {
            var btn = document.getElementById('adspirit-test-btn');
            var box = document.getElementById('adspirit-test-result');
            if (!btn) return;
            btn.addEventListener('click', function() {
                btn.disabled = true;
                btn.textContent = 'Testando…';
                box.style.display = 'block';
                box.textContent = 'Aguarde…';
                fetch(ajaxurl + '?action=adspirit_test_connection&_wpnonce=<?php echo esc_js(wp_create_nonce('adspirit_test_connection')); ?>')
                    .then(function(r) { return r.json(); })
                    .then(function(d) {
                        box.textContent = JSON.stringify(d, null, 2);
                        btn.disabled = false;
                        btn.textContent = 'Testar conexão agora';
                    })
                    .catch(function(err) {
                        box.textContent = 'Erro: ' + err.message;
                        btn.disabled = false;
                        btn.textContent = 'Testar conexão agora';
                    });
            });
        })();
        </script>
        <?php
    }

    private function compute_checklist(array $core) {
        $cf7_installed = class_exists('WPCF7_Submission');
        $endpoint_ok = !empty($core['endpoint_url']) && filter_var($core['endpoint_url'], FILTER_VALIDATE_URL);
        $slug_ok = !empty($core['brand_slug']);
        $secret_ok = !empty($core['secret']) && preg_match('/^[a-f0-9]{32,128}$/i', $core['secret']);

        $metrics = AdSpirit_Health_Checker::summarize();
        $sent_30d_ok = $metrics['cf7_sent_30d'] > 0;

        $forms = $this->discover_cf7_forms();
        $any_mapped = false;
        foreach ($forms as $f) {
            if ($f['mapped_count'] > 0) {
                $any_mapped = true;
                break;
            }
        }
        // Sem formulário EXTERNO no site não há o que mapear: o formulário do
        // AdSpirit entrega cada campo já com o nome canônico, por construção.
        // Antes, um site que migrou pro multi-step e desinstalou o CF7 ficava
        // com "Forms com campos mapeados" pendente pra sempre — pendência que
        // não existe, e que faz o painel inteiro parecer inacabado.
        if (empty($forms)) $any_mapped = true;

        // Default mapping (sem custom) também conta — se o form CF7 já usa
        // os nomes canônicos, está implicitamente mapeado.
        if (!$any_mapped && !empty($forms)) {
            foreach ($forms as $f) {
                foreach ($f['fields'] as $field) {
                    if (in_array($field, array_keys(AdSpirit_Settings::canonical_fields()), true)) {
                        $any_mapped = true;
                        break 2;
                    }
                }
            }
        }

        return array(
            // O CF7 deixou de ser obrigatório: o formulário multi-step do
            // AdSpirit tem endpoint próprio (admin-ajax) e não depende dele.
            // Site que migrou e desativou o plugin estava vendo uma FALHA
            // vermelha mandando reinstalar — conselho errado, e que assusta
            // justamente quem fez a coisa certa (Digitals, 31/08).
            //
            // Vira informativo: só diz o que existe. Falha de verdade é não
            // ter NENHUM caminho de captura, e isso o próprio item de
            // formulários já cobre.
            array(
                'status' => 'done',
                'title'  => $cf7_installed
                    ? 'Formulários do site'
                    : 'Formulário multi-step do AdSpirit',
                'desc'   => $cf7_installed
                    ? 'Contact Form 7 ativo e o formulário do AdSpirit disponível — os dois entregam lead.'
                    : 'O formulário multi-step do AdSpirit captura por conta própria. O Contact Form 7 não é necessário.',
                'cta_url' => admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=formularios'),
                'cta_label' => 'Ver formulários',
            ),
            array(
                'status' => $endpoint_ok ? 'done' : 'todo',
                'title'  => 'Endpoint do CRM configurado',
                'desc'   => $endpoint_ok
                    ? 'URL: <code>' . esc_html($core['endpoint_url']) . '</code>'
                    : 'Configure a base URL do CRM (default <code>https://crm.agenciadigitals.com.br</code>).',
                'cta_url' => admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=connection'),
                'cta_label' => 'Configurar',
            ),
            array(
                'status' => $slug_ok ? 'done' : 'todo',
                'title'  => 'Brand slug definido',
                'desc'   => $slug_ok
                    ? 'Slug: <code>' . esc_html($core['brand_slug']) . '</code>. Você recebe esse valor em <code>/settings/integrations/tracking</code> no painel do CRM.'
                    : 'Falta o identificador da marca. Pegue no AdSpirit, em <em>Configurações → Rastreamento (pixel)</em>.',
                'cta_url' => admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=connection'),
                'cta_label' => 'Adicionar slug',
            ),
            array(
                'status' => $secret_ok ? 'done' : 'todo',
                'title'  => 'Secret de autenticação configurado',
                'desc'   => $secret_ok
                    ? 'Secret presente (oculto por segurança). Rotacione se suspeitar de vazamento.'
                    : 'Gere a chave no AdSpirit, em <em>Configurações → Rastreamento (pixel)</em>, e cole aqui. Ela aparece uma única vez — guarde antes de fechar a tela.',
                'cta_url' => admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=connection'),
                'cta_label' => 'Colar secret',
            ),
            array(
                'status' => $any_mapped ? 'done' : 'todo',
                'title'  => 'Forms com campos mapeados',
                'desc'   => $any_mapped
                    ? (empty($forms)
                        ? 'O formulário do AdSpirit entrega os campos já com o nome certo — não há o que mapear.'
                        : 'Pelo menos um form tem mapeamento configurado. Verifique outros forms se houver.')
                    : 'Cada cliente tem um perfil diferente. Mapeie os campos do form pra que o CRM reconheça nome, email, telefone, etc.',
                'cta_url' => admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=forms'),
                'cta_label' => 'Mapear campos',
            ),
            array(
                'status' => $sent_30d_ok ? 'done' : 'todo',
                'title'  => 'Primeira submissão enviada com sucesso',
                'desc'   => $sent_30d_ok
                    ? 'Pelo menos uma submissão chegou ao CRM nos últimos 30 dias.'
                    : 'Submeta um form de teste no site. Em até 5s aparece em <code>/leads</code> no CRM.',
            ),
        );
    }

    private function compute_next_action(array $checklist) {
        foreach ($checklist as $item) {
            if ($item['status'] === 'done') continue;
            $cta = !empty($item['cta_url'])
                ? ' <a href="' . esc_url($item['cta_url']) . '">' . esc_html($item['cta_label'] ?? 'configurar') . '</a>'
                : '';
            return esc_html($item['title']) . '.' . $cta;
        }
        return ''; // tudo done
    }

    private function discover_cf7_forms() {
        if (!class_exists('WPCF7_ContactForm')) return array();
        $forms = WPCF7_ContactForm::find(array(
            'posts_per_page' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
        ));
        $mappings = AdSpirit_Settings::get_field_mappings();
        // Mesma fonte de verdade da aba Forms: campos canônicos/aliases
        // contam como reconhecidos mesmo sem mapping manual salvo.
        $mapper = class_exists('AdSpirit_Field_Mapping') ? AdSpirit_Field_Mapping::instance() : null;
        $canonical = AdSpirit_Settings::canonical_fields();
        $out = array();
        foreach ($forms as $form) {
            $id = $form->id();
            $tags = $form->scan_form_tags();
            $fields = array();
            foreach ($tags as $tag) {
                $name = isset($tag->name) ? $tag->name : '';
                if ($name) $fields[] = $name;
            }
            $map = isset($mappings[$id]) ? $mappings[$id] : array();
            $out[] = array(
                'id'           => $id,
                'title'        => $form->title(),
                'fields'       => $fields,
                'mapped_count' => count(array_filter($map)),
                'match'        => $mapper ? $mapper->form_match_status($form, $canonical) : null,
            );
        }
        return $out;
    }

    private function detect_environment() {
        $cf7_installed = false;
        $cf7_version = '?';
        if (defined('WPCF7_VERSION')) {
            $cf7_installed = true;
            $cf7_version = WPCF7_VERSION;
        }

        // WP Armour (Honeypot for Contact Form 7 plugin) detection
        $wp_armour = false;
        if (is_plugin_active('honeypot-for-contact-form-7/honeypot-for-cf7.php')
            || is_plugin_active('wp-armour/wp-armour.php')
            || class_exists('WPA_Settings')) {
            $wp_armour = true;
        }

        return array(
            'cf7_installed' => $cf7_installed,
            'cf7_version'   => $cf7_version,
            'wp_armour'     => $wp_armour,
        );
    }

    /**
     * SVG inline line-style (Lucide-ish). Mantém-se consistente com
     * o design system do CRM (sem emojis).
     */
    public static function icon($name, $size = 14) {
        $size = (int) $size;
        $stroke = 1.75;
        $common = 'fill="none" stroke="currentColor" stroke-width="' . $stroke . '" stroke-linecap="round" stroke-linejoin="round"';
        switch ($name) {
            case 'check':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" ' . $common . '><polyline points="20 6 9 17 4 12"></polyline></svg>';
            case 'circle':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" ' . $common . '><circle cx="12" cy="12" r="9"></circle></svg>';
            case 'x':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" ' . $common . '><line x1="18" y1="6" x2="6" y2="18"></line><line x1="6" y1="6" x2="18" y2="18"></line></svg>';
            case 'zap':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" ' . $common . '><polygon points="13 2 3 14 12 14 11 22 21 10 12 10 13 2"></polygon></svg>';
            case 'arrow-right':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" ' . $common . '><line x1="5" y1="12" x2="19" y2="12"></line><polyline points="12 5 19 12 12 19"></polyline></svg>';
            case 'alert':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" ' . $common . '><circle cx="12" cy="12" r="10"></circle><line x1="12" y1="8" x2="12" y2="12"></line><line x1="12" y1="16" x2="12.01" y2="16"></line></svg>';
            case 'shield':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" ' . $common . '><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"></path></svg>';
            case 'copy':
                return '<svg width="' . $size . '" height="' . $size . '" viewBox="0 0 24 24" ' . $common . '><rect x="9" y="9" width="13" height="13" rx="2" ry="2"></rect><path d="M5 15H4a2 2 0 0 1-2-2V4a2 2 0 0 1 2-2h9a2 2 0 0 1 2 2v1"></path></svg>';
        }
        return '';
    }

    /**
     * Ícone do status do item no checklist: done/todo/fail.
     */
    public static function status_icon($status) {
        switch ($status) {
            case 'done': return self::icon('check', 14);
            case 'fail': return self::icon('x', 14);
            default:     return self::icon('circle', 14);
        }
    }

    public function ajax_test_connection() {
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) {
            wp_send_json(array('ok' => false, 'error' => 'forbidden'), 403);
        }
        check_ajax_referer('adspirit_test_connection');

        $s = AdSpirit_Settings::get_core();
        if (empty($s['endpoint_url']) || empty($s['brand_slug']) || empty($s['secret'])) {
            wp_send_json(array(
                'ok'    => false,
                'error' => 'config_incompleta',
                'hint'  => 'Preencha endpoint URL, brand slug e secret na aba Conexão CRM antes de testar.',
            ));
        }

        $url = trailingslashit($s['endpoint_url']) . 'api/webhooks/contact-form-7';
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'x-brand-slug' => $s['brand_slug'],
                'x-cf7-secret' => $s['secret'],
                'User-Agent'   => 'AdSpirit-Connector/' . ADSPIRIT_CONNECTOR_VERSION,
            ),
        ));

        if (is_wp_error($response)) {
            wp_send_json(array(
                'ok'      => false,
                'error'   => 'network',
                'message' => $response->get_error_message(),
            ));
        }

        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $parsed = json_decode($body, true);

        wp_send_json(array(
            'ok'        => $code === 200,
            'http_code' => $code,
            'endpoint'  => $url,
            'response'  => $parsed ?: $body,
        ));
    }
}

// Conexão CRM tab
add_action('adspirit_connector_render_tab_connection', AdSpirit_Safe_Hook::action(function() {
    $s = AdSpirit_Settings::get_core();
    $connected = class_exists('AdSpirit_Connect') && AdSpirit_Connect::is_connected();
    $error = isset($_GET['connect_error']) ? sanitize_text_field((string) $_GET['connect_error']) : '';
    $disconnected = !empty($_GET['disconnected']);

    if ($error): ?>
        <div class="as-notice danger">
            <div class="as-notice-kicker">Erro de conexão</div>
            <p><?php echo esc_html(urldecode($error)); ?></p>
        </div>
    <?php endif; ?>

    <?php if ($disconnected): ?>
        <div class="as-notice info">
            <div class="as-notice-kicker">Desconectado</div>
            <p>Credenciais removidas. Plugin não envia leads até reconectar.</p>
        </div>
    <?php endif; ?>

    <?php if ($connected): ?>
        <?php
        // O QUE ESTE SITE FAZ — no topo, não escondido no "avançado".
        // Antes eram quatro checkboxes numa linha chamada "Extras
        // opcionais", dentro de um <details> fechado. Duas delas são o
        // plugin em si, e nenhuma dizia se estava FUNCIONANDO — só se
        // estava marcada.
        if (class_exists('AdSpirit_Recursos')) :
            $recursos = AdSpirit_Recursos::todos();
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Neste site</span>O que está ligado</h2>
        <p class="as-section-help">Cada chave abaixo muda o que este site faz. O selo à direita não diz
            só que está ligada — diz se há prova recente de que está funcionando.</p>

        <?php
        // Mesmo caminho de salvamento do resto do painel (AdSpirit_Menu),
        // com um marcador dizendo que ESTE form tem autoridade sobre os
        // quatro toggles — ver o guard em adspirit_connector_save_connection.
        AdSpirit_Menu::form_open('connection');
        ?>
            <input type="hidden" name="_adspirit_recursos" value="1">
            <ul class="as-recursos">
                <?php
                // Recurso "avançado" só entra na lista se estiver pedindo
                // atenção. Sem problema, é infraestrutura — e infraestrutura
                // que funciona não precisa de linha na tela.
                foreach ($recursos as $r) :
                    // Recurso "avançado" some da lista quando está tudo certo
                    // — mas o input PRECISA continuar no form. Checkbox que
                    // não é renderizada não é postada, e o handler leria isso
                    // como "desmarcada", desligando a chave sem ninguém pedir.
                    $esconder = !empty($r['avancado']) && $r['estado'] !== AdSpirit_Recursos::ATENCAO;
                    if ($esconder) {
                        printf(
                            '<input type="checkbox" name="%s" value="1" %s class="as-oculto">',
                            esc_attr($r['key']),
                            $r['ligado'] ? 'checked' : ''
                        );
                        continue;
                    }
                ?>
                    <li class="as-recurso<?php echo $r['sub'] ? ' as-recurso--sub' : ''; ?>">
                        <?php if ($r['essencial']) : ?>
                            <?php // Indicador, não controle: desligar isto é
                                  // decisão rara e mora no rodapé do card. O
                                  // input segue no form (escondido) pra que
                                  // salvar nunca zere a chave sem querer. ?>
                            <span class="as-essencial-marca <?php echo $r['ligado'] ? 'on' : 'off'; ?>"
                                  title="<?php echo $r['ligado'] ? 'Ativo' : 'Desligado'; ?>" aria-hidden="true"></span>
                            <input type="checkbox" id="asr_<?php echo esc_attr($r['key']); ?>"
                                   name="<?php echo esc_attr($r['key']); ?>" value="1"
                                   <?php checked($r['ligado']); ?> class="as-oculto">
                        <?php else : ?>
                            <label class="as-switch<?php echo !empty($r['indisponivel']) ? ' as-switch--pausado' : ''; ?>" for="asr_<?php echo esc_attr($r['key']); ?>">
                                <input type="checkbox" id="asr_<?php echo esc_attr($r['key']); ?>"
                                       name="<?php echo esc_attr($r['key']); ?>" value="1"
                                       <?php checked($r['ligado']); ?>>
                                <span class="as-switch-track" aria-hidden="true"><span class="as-switch-thumb"></span></span>
                            </label>
                        <?php endif; ?>
                        <div class="as-recurso-body">
                            <div class="as-recurso-head">
                                <span class="as-recurso-titulo"><?php echo esc_html($r['titulo']); ?></span>
                                <?php if ($r['essencial']) : ?>
                                    <span class="as-recurso-tag">essencial</span>
                                <?php endif; ?>
                                <?php if (!empty($r['indisponivel'])) : ?>
                                    <span class="as-recurso-tag as-recurso-tag--exp">em pausa</span>
                                <?php endif; ?>
                                <span class="as-recurso-selo as-recurso-selo--<?php echo esc_attr($r['estado']); ?>">
                                    <span class="as-status-dot" aria-hidden="true"></span><?php
                                    echo esc_html(AdSpirit_Recursos::rotulo($r['estado']));
                                ?></span>
                            </div>
                            <p class="as-recurso-faz"><?php echo esc_html($r['o_que_faz']); ?></p>

                            <?php // Número em destaque quando existe — é o que
                                  // se procura primeiro num painel de saúde. ?>
                            <?php if (!empty($r['metrica'])) : ?>
                                <div class="as-recurso-metrica">
                                    <span class="as-recurso-num"><?php echo esc_html($r['metrica']['valor']); ?></span>
                                    <span class="as-recurso-num-rot"><?php echo esc_html($r['metrica']['rotulo']); ?></span>
                                </div>
                            <?php endif; ?>

                            <p class="as-recurso-estado"><?php echo esc_html($r['resumo']); ?></p>

                            <?php // Procedência: quem lê precisa saber se o
                                  // número é medição deste site, config ou
                                  // arquivo remoto — cada um envelhece
                                  // diferente. É o que o /settings/integrations
                                  // do AdSpirit faz. ?>
                            <dl class="as-recurso-meta">
                                <?php if (isset($r['conexao'])) : ?>
                                    <div>
                                        <dt>Conexão</dt>
                                        <dd class="<?php echo !empty($r['conexao_ok']) ? 'ok' : 'nok'; ?>"><?php
                                            echo esc_html($r['conexao']);
                                        ?></dd>
                                    </div>
                                <?php endif; ?>
                                <?php if (!empty($r['fonte'])) : ?>
                                    <div>
                                        <dt>Fonte do dado</dt>
                                        <dd><?php echo esc_html($r['fonte']); ?></dd>
                                    </div>
                                <?php endif; ?>
                            </dl>

                            <?php if ($r['essencial']) : ?>
                                <details class="as-desligar">
                                    <summary><?php echo $r['ligado'] ? 'Desligar este recurso' : 'Religar este recurso'; ?></summary>
                                    <p>
                                        <?php echo $r['ligado']
                                            ? 'Só faz sentido em caso raro — cliente que não quer medição no site, ou site em construção. Desmarque e salve.'
                                            : 'Este recurso está desligado. Marque e salve pra voltar a funcionar.'; ?>
                                    </p>
                                    <label class="as-toggle">
                                        <input type="checkbox" class="as-espelho"
                                               data-alvo="asr_<?php echo esc_attr($r['key']); ?>"
                                               <?php checked($r['ligado']); ?>>
                                        <span class="t"><?php echo esc_html($r['titulo']); ?></span>
                                    </label>
                                </details>
                            <?php endif; ?>
                            <?php if (!empty($r['acao'])) : ?>
                                <p class="as-recurso-acao">
                                    <a class="button button-small" href="<?php
                                        echo esc_url(admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=' . $r['acao']['tab']));
                                    ?>"><?php echo esc_html($r['acao']['rotulo']); ?></a>
                                </p>
                            <?php endif; ?>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
            <script>
            // O "desligar" do card é um espelho: o input que o form envia
            // fica escondido, pra que salvar jamais zere a chave por ela
            // simplesmente não ter sido renderizada.
            (function () {
                var lista = document.querySelector('.as-recursos');
                var f = lista && lista.closest('form');
                if (!f) return;
                f.addEventListener('change', function (e) {
                    var esp = e.target.closest('.as-espelho');
                    if (!esp) return;
                    var alvo = document.getElementById(esp.getAttribute('data-alvo'));
                    if (alvo) alvo.checked = esp.checked;
                });
            })();
            </script>
            <p class="as-recursos-salvar">
                <button type="submit" class="button">Salvar alterações</button>
                <span class="as-recursos-dica">A mudança vale assim que você salvar.</span>
            </p>
        </form>
        <?php endif; ?>
        <?php
        // A devolutiva ao visitante NÃO entra na lista acima: não é algo
        // que o plugin faz por padrão, e é a única chave aqui que muda o
        // que a PESSOA lê no site. Fica separada, com o risco dito.
        echo apply_filters('adspirit_connector_recursos_render_extra', '');
        ?>
        <?php
        // Sobrou no avançado o que é de fato técnico — endereço, chaves e
        // desconectar.
        ?>
        <?php
        // Edição atrás do clique: quem abre esta aba quer saber se está tudo
        // certo, não editar endpoint. Quem precisa editar sabe procurar.
        ?>
        <details class="as-avancado" style="margin-top:8px">
        <summary style="cursor:pointer;font-size:12.5px;font-weight:600;color:var(--as-ink-soft);padding:10px 0">
            Ajustes técnicos e desconectar
        </summary>
        <?php AdSpirit_Menu::card_open('Configuração avançada', 'Endereço, marca e chaves. Mexer aqui só em caso especial — normalmente esses valores vêm do AdSpirit.'); ?>
        <?php AdSpirit_Menu::form_open('connection'); ?>
        <table class="form-table"><?php
    else:
    ?>
        <h2 class="as-section"><span class="as-kicker-inline">Conectar</span>Vincular este WordPress ao AdSpirit</h2>
        <p class="as-section-help">Em 2 cliques você conecta o plugin ao CRM. Você loga uma vez no AdSpirit, autoriza, e tudo vem configurado automaticamente — sem copy/paste de tokens.</p>

        <?php AdSpirit_Menu::card_open('Conexão automática', 'Recomendado — você é redirecionado pro AdSpirit, autoriza, volta conectado'); ?>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="adspirit_connect_start">
            <?php wp_nonce_field('adspirit_connect_start'); ?>
            <table class="form-table">
                <tr>
                    <th><label for="adspirit_endpoint">URL do AdSpirit</label></th>
                    <td>
                        <input type="url" id="adspirit_endpoint" name="endpoint_url" value="<?php echo esc_attr($s['endpoint_url']); ?>" class="regular-text" required>
                        <p class="description">Default: <code>https://crm.agenciadigitals.com.br</code>. Mudar só pra ambiente de teste.</p>
                    </td>
                </tr>
            </table>
            <p class="submit">
                <button type="submit" class="button button-primary" style="font-size:13.5px; padding:9px 18px;">Conectar ao AdSpirit →</button>
            </p>
        </form>
        <p class="as-field-help">Você vai ser levado pro CRM em uma nova aba. Após autorizar, retorna pra cá e tudo já estará configurado.</p>
        <?php AdSpirit_Menu::card_close(); ?>

        <details style="margin-top:20px;">
            <summary>Configurar manualmente (avançado)</summary>
        <?php AdSpirit_Menu::card_open('Credenciais', 'Cola os 4 valores gerados no painel do CRM'); ?>
        <?php AdSpirit_Menu::form_open('connection'); ?>
        <table class="form-table"><?php
    endif;
    ?>
        <tr>
            <th><label for="adspirit_endpoint_url">Endpoint URL</label></th>
            <td>
                <input type="url" id="adspirit_endpoint_url" name="endpoint_url" value="<?php echo esc_attr($s['endpoint_url']); ?>" class="regular-text" required>
                <p class="description">Endereço do AdSpirit. Padrão: <code>https://crm.agenciadigitals.com.br</code>.</p>
            </td>
        </tr>
        <tr>
            <th><label for="adspirit_brand_slug">Brand slug</label></th>
            <td>
                <input type="text" id="adspirit_brand_slug" name="brand_slug" value="<?php echo esc_attr($s['brand_slug']); ?>" class="regular-text" required pattern="[a-z0-9_-]+">
                <p class="description">Identificador da marca no AdSpirit (ex.: <code>agd</code>).</p>
            </td>
        </tr>
        <tr>
            <th><label for="adspirit_secret">Secret CF7</label></th>
            <td>
                <?php if (defined('ADSPIRIT_CRM_SECRET')) : ?>
                    <input type="password" id="adspirit_secret" disabled value="" class="regular-text" placeholder="Definido no wp-config.php">
                    <p class="description">Gerenciado pela constante <code>ADSPIRIT_CRM_SECRET</code> — fora do banco, por segurança.</p>
                <?php else : ?>
                    <input type="password" id="adspirit_secret" name="secret" value="<?php echo esc_attr($s['secret']); ?>" class="regular-text" autocomplete="off">
                <?php endif; ?>
                <button type="button" class="button" onclick="var e=document.getElementById('adspirit_secret');e.type=e.type==='password'?'text':'password';">Mostrar</button>
                <p class="description">64 caracteres. Cole o valor gerado no AdSpirit — ele não aparece de novo, então guarde.</p>
            </td>
        </tr>
        <tr>
            <th><label for="adspirit_pixel_token">Pixel token</label></th>
            <td>
                <input type="text" id="adspirit_pixel_token" name="pixel_token" value="<?php echo esc_attr($s['pixel_token']); ?>" class="regular-text">
                <p class="description">Opcional. Token <code>dos_…</code> da marca pro pixel. Mesma página do AdSpirit.</p>
            </td>
        </tr>
        <?php
        // Feature 35 (lead score preview) e features futuras se plugam aqui
        // sem editar este arquivo — filter render_extra concatena <tr>s.
        echo apply_filters('adspirit_connector_connection_render_extra', '');
        ?>
    </table>
    <?php AdSpirit_Menu::form_close('Salvar conexão'); ?>
    <?php AdSpirit_Menu::card_close(); ?>
    <?php ?></details><?php ?>
    <?php
}, 'connection_tab'));

add_action('adspirit_connector_save_connection', AdSpirit_Safe_Hook::action(function($post) {
    $patch = array();
    if (isset($post['endpoint_url'])) {
        // Normaliza: strip path se user colou URL completa (com /api/webhooks/...).
        $patch['endpoint_url'] = esc_url_raw(
            AdSpirit_Settings::normalize_endpoint_url((string) $post['endpoint_url'])
        );
    }
    if (isset($post['brand_slug'])) {
        $patch['brand_slug'] = sanitize_text_field(trim((string) $post['brand_slug']));
    }
    if (isset($post['secret'])) {
        $secret = trim((string) $post['secret']);
        if ($secret !== '' && !preg_match('/^[a-f0-9]{32,128}$/i', $secret)) {
            add_settings_error(AdSpirit_Settings::OPTION_CORE, 'invalid_secret', 'Secret inválido — deve ser hexadecimal de 64 caracteres.');
        } else {
            $patch['secret'] = $secret;
        }
    }
    if (isset($post['pixel_token'])) {
        $patch['pixel_token'] = sanitize_text_field(trim((string) $post['pixel_token']));
    }
    // Checkbox desmarcada não é postada, então estes quatro só podem ser
    // lidos por um form que os CARREGUE — senão salvar os ajustes técnicos
    // desligaria a captura inteira em silêncio. O marcador diz quem tem
    // autoridade pra mexer neles.
    if (!empty($post['_adspirit_recursos'])) {
        $patch['cf7_enabled']   = !empty($post['cf7_enabled']) ? '1' : '0';
        $patch['pixel_enabled'] = !empty($post['pixel_enabled']) ? '1' : '0';
        $patch['pixel_firstparty'] = !empty($post['pixel_firstparty']) ? '1' : '0';
        $patch['generic_forms_enabled'] = !empty($post['generic_forms_enabled']) ? '1' : '0';
    }
    delete_transient('adspirit_central_status'); // conexão mudou → re-perguntar cobertura
    // Feature 35 + futuras: filter pra cada feature contribuir patches
    // sem editar este handler.
    $patch = apply_filters('adspirit_connector_connection_save_extra', $patch, $post);
    AdSpirit_Settings::update_core($patch);
    add_settings_error(AdSpirit_Settings::OPTION_CORE, 'saved', 'Conexão salva.', 'updated');
}, 'connection_save'));
