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
                    <p>Lead de teste foi aceito pelo CRM (HTTP 200) e arquivado automaticamente. Confira em <a href="<?php echo esc_url($core['endpoint_url']); ?>/leads?archived=1" target="_blank">/leads</a> no CRM.</p>
                </div>
            <?php elseif ($test_result === 'config_incompleta'): ?>
                <div class="as-notice warn">
                    <div class="as-notice-kicker">Config faltando</div>
                    <p>Conecte o plugin primeiro (aba Conexão CRM).</p>
                </div>
            <?php else: ?>
                <div class="as-notice danger">
                    <div class="as-notice-kicker">Falha no teste</div>
                    <p>CRM retornou: <code><?php echo esc_html($test_result); ?></code>. Veja os logs.</p>
                </div>
            <?php endif; ?>
        <?php endif; ?>
        <?php if ($next_action): ?>
            <div class="as-notice info">
                <div class="as-notice-kicker">Próxima ação</div>
                <p><?php echo wp_kses_post($next_action); ?></p>
            </div>
        <?php endif; ?>

        <h2 class="as-section"><span class="as-kicker-inline">Onboarding</span>Conectar o site ao CRM</h2>
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

        <h2 class="as-section"><span class="as-kicker-inline">Performance</span>Métricas dos últimos 30 dias</h2>
        <div class="as-metric-grid">
            <div class="as-metric">
                <div class="label">CF7 → CRM enviados</div>
                <div class="value"><?php echo esc_html($metrics['cf7_sent_30d']); ?></div>
                <div class="sub"><?php echo esc_html($metrics['cf7_sent_24h']); ?> nas últimas 24h</div>
            </div>
            <div class="as-metric">
                <div class="label">Falhas no envio</div>
                <div class="value <?php echo $metrics['cf7_failed_30d'] > 0 ? 'danger' : ''; ?>"><?php echo esc_html($metrics['cf7_failed_30d']); ?></div>
                <div class="sub"><?php echo esc_html($metrics['cf7_failed_24h']); ?> nas últimas 24h</div>
            </div>
            <div class="as-metric">
                <div class="label">Taxa de sucesso</div>
                <div class="value"><?php echo esc_html($metrics['success_rate']); ?>%</div>
                <div class="sub"><?php echo $metrics['cf7_sent_30d'] + $metrics['cf7_failed_30d']; ?> tentativas</div>
            </div>
            <div class="as-metric">
                <div class="label">Bloqueios anti-spam</div>
                <div class="value"><?php echo esc_html($metrics['antispam_blocked_30d']); ?></div>
                <div class="sub"><?php echo esc_html($metrics['antispam_blocked_24h']); ?> nas últimas 24h</div>
            </div>
            <div class="as-metric">
                <div class="label">Última submissão</div>
                <div class="value text"><?php echo esc_html($metrics['last_cf7_at_human'] ?: 'nunca'); ?></div>
                <div class="sub"><?php echo esc_html($metrics['last_cf7_at_iso'] ?: ''); ?></div>
            </div>
            <div class="as-metric">
                <div class="label">Último erro</div>
                <div class="value text <?php echo $metrics['last_error'] ? 'danger' : ''; ?>" style="font-size:13px;">
                    <?php echo esc_html($metrics['last_error'] ?: 'nenhum'); ?>
                </div>
            </div>
        </div>

        <h2 class="as-section"><span class="as-kicker-inline">Forms</span>Detectados no site</h2>
        <?php if (empty($forms)): ?>
            <div class="as-notice warn">
                <p>Nenhum form CF7 encontrado. Crie um em <code>Contact → Forms</code>.</p>
            </div>
        <?php else: ?>
            <table class="as-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Título</th>
                        <th>Campos</th>
                        <th>Mapeamento</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($forms as $form): ?>
                        <tr>
                            <td><code><?php echo esc_html($form['id']); ?></code></td>
                            <td><?php echo esc_html($form['title']); ?></td>
                            <td><?php echo esc_html(count($form['fields'])); ?> campos</td>
                            <td>
                                <?php if ($form['mapped_count'] > 0): ?>
                                    <span class="as-badge ok"><?php echo esc_html($form['mapped_count']); ?> mapeados</span>
                                <?php else: ?>
                                    <span class="as-badge warn">não mapeado</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=forms&form_id=' . $form['id'])); ?>" class="button">
                                    Mapear campos
                                </a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>

        <h2 class="as-section"><span class="as-kicker-inline">Ambiente</span>O que está rodando no servidor</h2>
        <table class="as-table" style="max-width:720px;">
            <tr>
                <th style="width:240px;">Contact Form 7</th>
                <td>
                    <?php if ($env['cf7_installed']): ?>
                        <span class="as-badge ok">Ativo</span> v<?php echo esc_html($env['cf7_version']); ?>
                    <?php else: ?>
                        <span class="as-badge danger">Não instalado</span>
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

        <h2 class="as-section"><span class="as-kicker-inline">Resiliência</span>Proteções ativas</h2>
        <p class="as-section-help">Camadas que garantem que este plugin nunca derruba o site, mesmo se algo der errado.</p>
        <?php
        $safe_mode = class_exists('AdSpirit_Safe_Bootstrap') && AdSpirit_Safe_Bootstrap::is_safe_mode();
        $crashes = class_exists('AdSpirit_Crash_Tracker') ? AdSpirit_Crash_Tracker::get_log() : array();
        $recent_crashes = 0;
        $window = time() - 86400; // 24h
        foreach ($crashes as $c) {
            if (($c['at'] ?? 0) >= $window) $recent_crashes++;
        }
        ?>
        <table class="as-table" style="max-width:720px;">
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

        <h2 class="as-section"><span class="as-kicker-inline">Teste</span>Conexão com o CRM</h2>
        <p class="as-section-help">Faz <code>GET</code> no endpoint validando brand slug + secret. Não cria lead.</p>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
            <button type="button" class="button button-primary" id="adspirit-test-btn"><?php echo self::icon('zap'); ?> Testar conexão agora</button>
            <form method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                <input type="hidden" name="action" value="adspirit_send_test_event">
                <?php wp_nonce_field('adspirit_send_test_event'); ?>
                <button type="submit" class="button">Disparar lead de teste</button>
            </form>
            <form method="get" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                <input type="hidden" name="action" value="adspirit_force_update_check">
                <?php wp_nonce_field('adspirit_force_update_check'); ?>
                <button type="submit" class="button" title="Limpa cache + checa GitHub agora (não espera ciclo de 6h)">Verificar atualizações agora</button>
            </form>
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
            array(
                'status' => $cf7_installed ? 'done' : 'fail',
                'title'  => 'Contact Form 7 instalado e ativo',
                'desc'   => $cf7_installed
                    ? 'Plugin CF7 detectado. Hooks de submissão prontos.'
                    : 'Instale e ative o plugin <a href="' . esc_url(admin_url('plugin-install.php?s=contact+form+7&tab=search')) . '">Contact Form 7</a>. Sem ele, o envio de leads não funciona.',
                'cta_url' => admin_url('plugin-install.php?s=contact+form+7&tab=search'),
                'cta_label' => 'Instalar CF7',
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
                    : 'Falta o identificador da marca. Pegue em <em>Tracking → Plugin WordPress</em> no CRM.',
                'cta_url' => admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=connection'),
                'cta_label' => 'Adicionar slug',
            ),
            array(
                'status' => $secret_ok ? 'done' : 'todo',
                'title'  => 'Secret de autenticação configurado',
                'desc'   => $secret_ok
                    ? 'Secret presente (oculto por segurança). Rotacione se suspeitar de vazamento.'
                    : 'Gere o secret em <em>Tracking → Plugin WordPress → Gerar secret</em> no CRM. Cole aqui em <em>Conexão CRM</em>. O secret só aparece uma vez — guarde com cuidado.',
                'cta_url' => admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=connection'),
                'cta_label' => 'Colar secret',
            ),
            array(
                'status' => $any_mapped ? 'done' : 'todo',
                'title'  => 'Forms com campos mapeados',
                'desc'   => $any_mapped
                    ? 'Pelo menos um form tem mapeamento configurado. Verifique outros forms se houver.'
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
        <h2 class="as-section"><span class="as-kicker-inline">Conexão</span>Status</h2>
        <p class="as-section-help">Plugin conectado ao AdSpirit. Pra trocar de brand ou rotacionar credenciais, desconecte e reconecte.</p>

        <?php AdSpirit_Menu::card_open('Conectado', 'Credenciais salvas no banco do WordPress', '<span class="as-badge ok">Online</span>'); ?>
        <table class="form-table">
            <tr>
                <th>Marca</th>
                <td>
                    <strong style="font-size:14px; color:var(--as-ink);"><?php echo esc_html($s['brand_name'] ?: $s['brand_slug']); ?></strong>
                    <span class="as-field-help" style="display:inline; margin-left:8px;">slug <code><?php echo esc_html($s['brand_slug']); ?></code></span>
                </td>
            </tr>
            <tr>
                <th>Endpoint</th>
                <td><code><?php echo esc_html($s['endpoint_url']); ?></code></td>
            </tr>
            <tr>
                <th>Pixel token</th>
                <td><code><?php echo esc_html($s['pixel_token']); ?></code></td>
            </tr>
            <tr>
                <th>Secret CF7</th>
                <td><code><?php echo esc_html(substr($s['secret'], 0, 8) . str_repeat('•', 32) . substr($s['secret'], -4)); ?></code></td>
            </tr>
            <tr>
                <th>Features</th>
                <td>
                    <span class="as-badge <?php echo $s['cf7_enabled'] === '1' ? 'ok' : 'muted'; ?>">CF7 → CRM <?php echo $s['cf7_enabled'] === '1' ? 'ativo' : 'desligado'; ?></span>
                    <span class="as-badge <?php echo $s['pixel_enabled'] === '1' ? 'ok' : 'muted'; ?>" style="margin-left:6px;">Pixel <?php echo $s['pixel_enabled'] === '1' ? 'ativo' : 'desligado'; ?></span>
                </td>
            </tr>
        </table>
        <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin-top:16px;">
            <input type="hidden" name="action" value="adspirit_disconnect">
            <?php wp_nonce_field('adspirit_disconnect'); ?>
            <button type="submit" class="button button-danger">Desconectar</button>
        </form>
        <?php AdSpirit_Menu::card_close(); ?>

        <?php AdSpirit_Menu::card_open('Configuração avançada', 'Editar valores manualmente — use só pra debug ou caso especial'); ?>
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
                <p class="description">Base do CRM. Default: <code>https://crm.agenciadigitals.com.br</code>.</p>
            </td>
        </tr>
        <tr>
            <th><label for="adspirit_brand_slug">Brand slug</label></th>
            <td>
                <input type="text" id="adspirit_brand_slug" name="brand_slug" value="<?php echo esc_attr($s['brand_slug']); ?>" class="regular-text" required pattern="[a-z0-9_-]+">
                <p class="description">Identificador da marca no CRM (ex.: <code>agd</code>).</p>
            </td>
        </tr>
        <tr>
            <th><label for="adspirit_secret">Secret CF7</label></th>
            <td>
                <input type="password" id="adspirit_secret" name="secret" value="<?php echo esc_attr($s['secret']); ?>" class="regular-text" autocomplete="off">
                <button type="button" class="button" onclick="var e=document.getElementById('adspirit_secret');e.type=e.type==='password'?'text':'password';">Mostrar</button>
                <p class="description">64 caracteres hex. Cola o valor gerado no CRM. Não fica visível depois — guarde.</p>
            </td>
        </tr>
        <tr>
            <th><label for="adspirit_pixel_token">Pixel token</label></th>
            <td>
                <input type="text" id="adspirit_pixel_token" name="pixel_token" value="<?php echo esc_attr($s['pixel_token']); ?>" class="regular-text">
                <p class="description">Opcional. Token <code>dos_…</code> da marca pro pixel JS. Mesma página do CRM.</p>
            </td>
        </tr>
        <tr>
            <th>Features</th>
            <td>
                <label>
                    <input type="checkbox" name="cf7_enabled" value="1" <?php checked($s['cf7_enabled'], '1'); ?>>
                    Enviar submissões do Contact Form 7 pro CRM
                </label><br>
                <label style="margin-top:8px; display:inline-flex;">
                    <input type="checkbox" name="pixel_enabled" value="1" <?php checked($s['pixel_enabled'], '1'); ?>>
                    Injetar pixel de tracking no <code>&lt;head&gt;</code>
                </label>
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
    <?php if (!$connected): ?></details><?php endif; ?>
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
    $patch['cf7_enabled']   = !empty($post['cf7_enabled']) ? '1' : '0';
    $patch['pixel_enabled'] = !empty($post['pixel_enabled']) ? '1' : '0';
    // Feature 35 + futuras: filter pra cada feature contribuir patches
    // sem editar este handler.
    $patch = apply_filters('adspirit_connector_connection_save_extra', $patch, $post);
    AdSpirit_Settings::update_core($patch);
    add_settings_error(AdSpirit_Settings::OPTION_CORE, 'saved', 'Conexão salva.', 'updated');
}, 'connection_save'));
