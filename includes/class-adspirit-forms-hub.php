<?php
/**
 * AdSpirit Connector — hub "Formulários" (reestruturação 08-18).
 *
 * A porta única do "Receber leads", form-first: você vê TODOS os
 * formulários do site como uma coleção visual (snapshot tipo vitrine),
 * escolhe um e configura DENTRO dele — editar, mapear campos, visualizar.
 * As telas antigas (qualifier, builder, mapear) viram DETALHE alcançado
 * daqui; saíram da navegação.
 *
 * Fontes da coleção:
 *   - Form de avaliação (qualifier): roteiro local ou padrão embutido;
 *   - Forms do builder (option local);
 *   - Forms da Central do AdSpirit (catálogo via handshake, badge);
 *   - Modelos prontos (galeria do CRM) pra usar de base.
 *
 * Pré-visualização SEM frontend: ?adspirit_qf_preview=<fonte> renderiza o
 * form de verdade numa página limpa (admin logado apenas). No preview o
 * qualifier avança pelos campos obrigatórios sem preencher e NADA é
 * enviado (CFG.preview no JS + bloqueio universal de submit).
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Forms_Hub {
    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_filter('adspirit_connector_tabs',
            AdSpirit_Safe_Hook::filter(array($this, 'register_tab'), 'forms_hub_tab_register'));
        add_action('adspirit_connector_render_tab_formularios',
            AdSpirit_Safe_Hook::action(array($this, 'render_tab'), 'forms_hub_tab'));
        add_action('template_redirect',
            AdSpirit_Safe_Hook::action(array($this, 'maybe_render_preview'), 'forms_hub_preview'));
    }

    public function register_tab($tabs) {
        // Formulários entra logo depois da visão geral na ordem de registro.
        $out = array();
        foreach ($tabs as $slug => $label) {
            $out[$slug] = $label;
            if ($slug === 'overview') $out['formularios'] = 'Formulários';
        }
        if (!isset($out['formularios'])) $out['formularios'] = 'Formulários';
        return $out;
    }

    // ─────────────────────────────────────────────────────────
    // Coleção
    // ─────────────────────────────────────────────────────────

    /** URL de pré-visualização de uma fonte (qualifier | builder:id | central:slug). */
    public static function preview_url($source) {
        return add_query_arg('adspirit_qf_preview', rawurlencode((string) $source), home_url('/'));
    }

    private function admin_tab_url($tab, $extra = array()) {
        $args = array_merge(array('page' => AdSpirit_Menu::PAGE_SLUG, 'tab' => $tab), $extra);
        return add_query_arg($args, admin_url('admin.php'));
    }

    /** Primeira pergunta "de verdade" de um roteiro (pro snapshot). */
    private static function first_question(array $steps) {
        foreach ($steps as $step) {
            if (!is_array($step) || !empty($step['isIntro']) || !empty($step['isSuccess'])) continue;
            return $step;
        }
        return null;
    }

    /** Sub-abas do hub (pedido 9c do Pedro): visão geral · novo · nossos ·
     *  de outros plugins. Cada uma responde uma pergunta diferente. */
    private function subtabs() {
        return array(
            'visao'   => 'Visão geral',
            'novo'    => 'Novo formulário',
            'nossos'  => 'Formulários AdSpirit',
            'outros'  => 'De outros plugins',
        );
    }

    private function current_sub() {
        $sub = isset($_GET['sub']) ? sanitize_key((string) $_GET['sub']) : 'visao';
        return isset($this->subtabs()[$sub]) ? $sub : 'visao';
    }

    private function render_subnav($current) {
        echo '<div class="fh-subnav">';
        foreach ($this->subtabs() as $slug => $label) {
            $url = $this->admin_tab_url('formularios', array('sub' => $slug));
            printf(
                '<a href="%s" class="fh-pill%s">%s</a>',
                esc_url($url),
                $slug === $current ? ' on' : '',
                esc_html($label)
            );
        }
        echo '</div>';
    }

    public function render_tab() {
        $sub = $this->current_sub();
        $cards = array();

        // 1) Form de avaliação (qualifier) — sempre existe (padrão embutido).
        $local_steps = class_exists('AdSpirit_Form_Qualifier') ? AdSpirit_Form_Qualifier::get_steps() : array();
        $has_local = !empty($local_steps);
        $cards[] = array(
            'title'    => 'Form de avaliação',
            'format'   => 'Multi-etapas',
            'fin'      => 'Comercial',
            'origem'   => $has_local ? 'Personalizado' : 'Padrão Digitals',
            'shortcode'=> '[adspirit_form_qualifier]',
            'snapshot' => array('kind' => 'multistep', 'step' => self::first_question($has_local ? $local_steps : array())),
            'edit'     => $this->admin_tab_url('qualifier'),
            'map'      => $this->admin_tab_url('forms', array('context' => 'qualifier')),
            'preview'  => self::preview_url('qualifier'),
        );

        // 2) Forms do builder (locais deste site). "Sincronizado" = também
        // vive na Central (salvou aqui → refletiu lá).
        $builder_forms = class_exists('AdSpirit_Form') ? AdSpirit_Form::get_forms() : array();
        $builder_slugs = array();
        foreach ($builder_forms as $fid => $cfg) {
            if (!is_array($cfg)) continue;
            $builder_slugs[sanitize_key((string) $fid)] = true;
            $fields = isset($cfg['steps'][0]['fields']) && is_array($cfg['steps'][0]['fields'])
                ? $cfg['steps'][0]['fields'] : array();
            $cards[] = array(
                'title'    => (string) ($cfg['title'] ?? $fid),
                'format'   => count($cfg['steps'] ?? array()) > 1 ? 'Multi-etapas' : 'Simples',
                'fin'      => (($cfg['finalidade'] ?? '') === 'nutricao') ? 'Nutrição' : 'Comercial',
                'origem'   => !empty($cfg['synced_at']) ? 'Sincronizado' : 'Este site',
                'shortcode'=> '[adspirit_form id="' . esc_attr((string) $fid) . '"]',
                'snapshot' => array('kind' => 'fields', 'fields' => $fields),
                'edit'     => $this->admin_tab_url('builder', array('edit' => (string) $fid)),
                'map'      => $this->admin_tab_url('forms', array('context' => (string) $fid)),
                'preview'  => self::preview_url('builder:' . (string) $fid),
            );
        }

        // 3) Forms da Central do AdSpirit (handshake) — editar lá reflete cá.
        $central = class_exists('AdSpirit_Central_Forms') ? AdSpirit_Central_Forms::catalog() : array();
        $core = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();
        $crm_forms_url = !empty($core['endpoint_url'])
            ? rtrim((string) $core['endpoint_url'], '/') . '/settings/formularios' : '';
        foreach ($central as $slug => $cf) {
            if (isset($builder_slugs[$slug])) continue; // já aparece como card local sincronizado
            $fmt = $cf['style'] === 'quiz' ? 'Quiz'
                : ($cf['style'] === 'chat' ? 'Chat'
                : ($cf['style'] === 'single' ? 'Simples' : 'Multi-etapas'));
            $cards[] = array(
                'title'    => (string) $cf['name'],
                'format'   => $fmt,
                'fin'      => $cf['finalidade'] === 'nutricao' ? 'Nutrição' : 'Comercial',
                'origem'   => 'AdSpirit',
                'shortcode'=> '[adspirit_form_qualifier form="' . esc_attr($slug) . '"]',
                'snapshot' => array('kind' => 'multistep', 'step' => self::first_question($cf['steps'])),
                'edit'     => $crm_forms_url,
                'edit_ext' => true,
                'map'      => '',
                'preview'  => !empty($cf['steps']) ? self::preview_url('central:' . $slug) : '',
            );
        }

        // 4) Formulários de OUTROS plugins (CF7 e afins) — o site já os
        // tinha; o AdSpirit captura os leads deles do mesmo jeito. Card
        // sem preview (o render é do outro plugin) e com atalho pro mapa
        // de campos, que é o que de fato se configura aqui.
        if (class_exists('WPCF7_ContactForm')) {
            $cf7 = WPCF7_ContactForm::find(array('posts_per_page' => 30, 'orderby' => 'title', 'order' => 'ASC'));
            foreach ((array) $cf7 as $f) {
                if (!is_object($f) || !method_exists($f, 'id')) continue;
                $fields = array();
                foreach ((array) $f->scan_form_tags() as $tag) {
                    if (!empty($tag->name)) $fields[] = array('label' => $tag->name);
                }
                $cards[] = array(
                    'title'    => (string) $f->title(),
                    'format'   => 'Simples',
                    'fin'      => 'Comercial',
                    'origem'   => 'Outro plugin',
                    'shortcode'=> '[contact-form-7 id="' . (int) $f->id() . '"]',
                    'snapshot' => array('kind' => 'fields', 'fields' => array_slice($fields, 0, 3)),
                    'edit'     => admin_url('admin.php?page=wpcf7&post=' . (int) $f->id() . '&action=edit'),
                    'map'      => $this->admin_tab_url('forms', array('form_id' => (int) $f->id())),
                    'preview'  => '',
                );
            }
        }

        // Separa por origem: nossos (avaliação + builder + Central) vs de
        // outros plugins (CF7 e afins, que o site já tinha).
        $nossos = array();
        $outros = array();
        foreach ($cards as $c) {
            if (($c['origem'] ?? '') === 'Outro plugin') $outros[] = $c;
            else $nossos[] = $c;
        }
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Formulários</span><?php echo esc_html($this->subtabs()[$sub]); ?></h2>
        <?php $this->render_subnav($sub); ?>

        <?php if ($sub === 'visao') { $this->render_overview($cards); return; } ?>
        <?php if ($sub === 'novo') { $this->render_new($cards); return; } ?>
        <?php
        $show = $sub === 'outros' ? $outros : $nossos;
        if (empty($show)) {
            echo '<p class="as-section-help">' . ($sub === 'outros'
                ? 'Nenhum formulário de outro plugin detectado neste site.'
                : 'Nenhum formulário do AdSpirit ainda — crie um na aba "Novo formulário".') . '</p>';
            return;
        }
        ?>
        <p class="as-section-help"><?php echo $sub === 'outros'
            ? 'Formulários que já existiam no site (Contact Form 7 e afins). O AdSpirit captura os leads deles; o mapeamento de campos fica em Mapear campos.'
            : 'Os formulários do AdSpirit neste site. Escolha um pra visualizar, editar ou configurar.'; ?></p>

        <div class="fh-grid">
            <?php foreach ($show as $c) : ?>
                <div class="fh-card">
                    <a class="fh-snap-link" href="<?php echo esc_url($c['preview'] ?: $c['edit']); ?>" <?php echo $c['preview'] ? 'target="_blank" rel="noopener"' : ''; ?> aria-label="Pré-visualizar <?php echo esc_attr($c['title']); ?>">
                        <div class="fh-snap">
                            <?php $this->render_snapshot($c['snapshot'], $c['title']); ?>
                        </div>
                    </a>
                    <div class="fh-body">
                        <div class="fh-title"><?php echo esc_html($c['title']); ?></div>
                        <div class="fh-chips">
                            <span class="fh-chip"><?php echo esc_html($c['format']); ?></span>
                            <span class="fh-chip"><?php echo esc_html($c['fin']); ?></span>
                            <span class="fh-chip <?php echo in_array($c['origem'], array('AdSpirit', 'Sincronizado'), true) ? 'accent' : ''; ?>"><?php echo esc_html($c['origem']); ?></span>
                        </div>
                        <div class="fh-shortcode"><code><?php echo esc_html($c['shortcode']); ?></code></div>
                        <div class="fh-actions">
                            <?php if (!empty($c['preview'])) : ?>
                                <a class="button button-small" href="<?php echo esc_url($c['preview']); ?>" target="_blank" rel="noopener">Visualizar</a>
                            <?php endif; ?>
                            <?php if (!empty($c['edit'])) : ?>
                                <a class="button button-small" href="<?php echo esc_url($c['edit']); ?>" <?php echo !empty($c['edit_ext']) ? 'target="_blank" rel="noopener"' : ''; ?>><?php echo !empty($c['edit_ext']) ? 'Editar no AdSpirit' : 'Editar'; ?></a>
                            <?php endif; ?>
                            <?php if (!empty($c['map'])) : ?>
                                <a class="button-link" href="<?php echo esc_url($c['map']); ?>">Mapear campos</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>

        </div>

        <?php return; ?>
        <?php
        // Modelos prontos (galeria do CRM) — usar de base, não inventar a
        // roda. Busca ATIVA (fix 08-19): antes lia só o cache populado pela
        // aba do qualifier, então quem nunca abriu aquela aba via o hub sem
        // modelo nenhum.
        $tpls = class_exists('AdSpirit_Form_Qualifier')
            ? AdSpirit_Form_Qualifier::fetch_templates()
            : get_option('adspirit_qualifier_templates', null);
        if (is_array($tpls) && !empty($tpls)) : ?>
            <h2 class="as-section" style="margin-top:28px;"><span class="as-kicker-inline">Modelos prontos</span>Comece de uma base</h2>
            <p class="as-section-help">Roteiros validados por tipo de negócio. Aplicar leva o modelo pro Form de avaliação — daí é só personalizar.</p>
            <div class="fh-grid">
                <?php foreach (array_slice($tpls, 0, 6) as $t) : if (!is_array($t)) continue; ?>
                    <div class="fh-card">
                        <div class="fh-snap">
                            <?php $this->render_snapshot(array('kind' => 'multistep', 'step' => self::first_question(isset($t['steps']) && is_array($t['steps']) ? $t['steps'] : array())), (string) ($t['label'] ?? '')); ?>
                        </div>
                        <div class="fh-body">
                            <div class="fh-title"><?php echo esc_html((string) ($t['label'] ?? $t['id'] ?? 'Modelo')); ?></div>
                            <p class="fh-help"><?php echo esc_html(mb_substr((string) ($t['description'] ?? ''), 0, 120)); ?></p>
                            <div class="fh-actions">
                                <a class="button button-small" href="<?php echo esc_url($this->admin_tab_url('qualifier', array('tpl' => (string) ($t['id'] ?? '')))); ?>">Ver e aplicar</a>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif;
    }

    /** Aba "Visão geral": o que o site tem hoje (saiu do Início, pedido 9c). */
    private function render_overview($cards) {
        $nossos = 0; $outros = 0;
        foreach ($cards as $c) {
            if (($c['origem'] ?? '') === 'Outro plugin') $outros++; else $nossos++;
        }
        $unsent = (class_exists('AdSpirit_Lead_Store') && AdSpirit_Lead_Store::available())
            ? AdSpirit_Lead_Store::count_unsent() : 0;
        $tys = class_exists('AdSpirit_ThankYou_Setup') ? AdSpirit_ThankYou_Setup::confirmed_all() : array();
        ?>
        <p class="as-section-help">O que este site tem hoje pra capturar leads.</p>
        <div class="as-metric-grid">
            <div class="as-metric">
                <div class="label">Formulários do AdSpirit</div>
                <div class="value"><?php echo (int) $nossos; ?></div>
                <div class="sub">criados aqui ou no AdSpirit</div>
            </div>
            <div class="as-metric">
                <div class="label">De outros plugins</div>
                <div class="value"><?php echo (int) $outros; ?></div>
                <div class="sub">capturados mesmo assim</div>
            </div>
            <div class="as-metric">
                <div class="label">Aguardando entrega</div>
                <div class="value <?php echo $unsent > 0 ? 'danger' : ''; ?>"><?php echo (int) $unsent; ?></div>
                <div class="sub">leads na fila de reenvio</div>
            </div>
            <div class="as-metric">
                <div class="label">Páginas de conversão</div>
                <div class="value <?php echo empty($tys) ? '' : ''; ?>"><?php echo empty($tys) ? '—' : count($tys); ?></div>
                <div class="sub"><?php
                    if (empty($tys)) { echo 'nenhuma confirmada ainda'; }
                    else {
                        $paths = array();
                        foreach (array_slice($tys, 0, 2) as $t) $paths[] = $t['path'];
                        echo esc_html(implode(' · ', $paths));
                        if (count($tys) > 2) echo ' +' . (count($tys) - 2);
                    }
                ?></div>
            </div>
        </div>
        <p style="margin-top:14px;">
            <a class="button" href="<?php echo esc_url($this->admin_tab_url('formularios', array('sub' => 'nossos'))); ?>">Ver formulários do AdSpirit</a>
            <a class="button-link" style="margin-left:10px;" href="<?php echo esc_url($this->admin_tab_url('forms')); ?>">Mapear campos →</a>
        </p>
        <?php
    }

    /** Aba "Novo formulário": criar do zero ou partir de um modelo (9c). */
    private function render_new($cards) {
        ?>
        <p class="as-section-help">Crie um formulário do AdSpirit. Escolha o formato — dá pra mudar depois.</p>
        <div class="fh-grid">
            <div class="fh-card fh-new">
                <div class="fh-body">
                    <div class="fh-title">Do zero</div>
                    <p class="fh-help">Escolha o formato:</p>
                    <div class="fh-new-opts">
                        <a href="<?php echo esc_url($this->admin_tab_url('qualifier')); ?>"><strong>Multi-etapas</strong><small>Uma pergunta por tela — o formato que mais converte.</small></a>
                        <a href="<?php echo esc_url($this->admin_tab_url('builder', array('new' => '1'))); ?>"><strong>Simples</strong><small>Todos os campos numa tela só.</small></a>
                        <span class="fh-soon"><strong>Chat</strong><small>Cara de conversa — em breve.</small></span>
                    </div>
                </div>
            </div>
            <?php
            $tpls = class_exists('AdSpirit_Form_Qualifier') ? AdSpirit_Form_Qualifier::fetch_templates() : null;
            if (is_array($tpls)) : foreach (array_slice($tpls, 0, 6) as $t) : if (!is_array($t)) continue; ?>
                <div class="fh-card">
                    <div class="fh-snap">
                        <?php $this->render_snapshot(array('kind' => 'multistep', 'step' => self::first_question(isset($t['steps']) && is_array($t['steps']) ? $t['steps'] : array())), (string) ($t['label'] ?? '')); ?>
                    </div>
                    <div class="fh-body">
                        <div class="fh-title"><?php echo esc_html((string) ($t['label'] ?? 'Modelo')); ?></div>
                        <p class="fh-help"><?php echo esc_html(mb_substr((string) ($t['description'] ?? ''), 0, 110)); ?></p>
                        <div class="fh-actions">
                            <a class="button button-small" href="<?php echo esc_url($this->admin_tab_url('qualifier', array('tpl' => (string) ($t['id'] ?? '')))); ?>">Usar este modelo</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; endif; ?>
        </div>
        <?php
    }

    /** Miniatura visual do form (vitrine) — puro CSS, sem iframe. */
    private function render_snapshot($snap, $title) {
        if (($snap['kind'] ?? '') === 'fields') {
            $fields = array_slice(is_array($snap['fields'] ?? null) ? $snap['fields'] : array(), 0, 3);
            ?>
            <div class="fh-mini fh-mini-light">
                <div class="fh-mini-title"><?php echo esc_html($title); ?></div>
                <?php foreach ($fields as $f) : ?>
                    <div class="fh-mini-field"><?php echo esc_html((string) (is_array($f) ? ($f['label'] ?? $f['name'] ?? '') : '')); ?></div>
                <?php endforeach; ?>
                <?php if (empty($fields)) : ?><div class="fh-mini-field">Campos do formulário</div><?php endif; ?>
                <div class="fh-mini-btn">Enviar</div>
            </div>
            <?php
            return;
        }
        // Multi-etapas: frame escuro estilo qualifier com a 1ª pergunta.
        $step = is_array($snap['step'] ?? null) ? $snap['step'] : null;
        $eyebrow = $step ? (string) ($step['eyebrow'] ?? '') : 'avaliação';
        $qtitle = $step ? (string) ($step['title'] ?? '') : 'Preencha os seus dados';
        $choices = $step && isset($step['choices']) && is_array($step['choices'])
            ? array_slice($step['choices'], 0, 3) : array();
        ?>
        <div class="fh-mini fh-mini-dark">
            <?php if ($eyebrow !== '') : ?><div class="fh-mini-eyebrow"><?php echo esc_html($eyebrow); ?></div><?php endif; ?>
            <div class="fh-mini-title"><?php echo esc_html($qtitle); ?></div>
            <?php if (!empty($choices)) : ?>
                <?php foreach ($choices as $ch) : ?>
                    <div class="fh-mini-choice"><?php echo esc_html((string) (is_array($ch) ? ($ch['label'] ?? '') : $ch)); ?></div>
                <?php endforeach; ?>
            <?php else : ?>
                <div class="fh-mini-field"></div>
                <div class="fh-mini-btn">Continuar</div>
            <?php endif; ?>
        </div>
        <?php
    }

    // ─────────────────────────────────────────────────────────
    // Pré-visualização sem frontend
    // ─────────────────────────────────────────────────────────

    public function maybe_render_preview() {
        if (!isset($_GET['adspirit_qf_preview'])) return;
        if (!current_user_can('manage_options')) return; // segue o fluxo normal

        $source = (string) wp_unslash($_GET['adspirit_qf_preview']);
        $shortcode = '[adspirit_form_qualifier mode="inline"]';
        if (strpos($source, 'builder:') === 0) {
            $fid = sanitize_key(substr($source, 8));
            $shortcode = '[adspirit_form id="' . $fid . '"]';
        } elseif (strpos($source, 'central:') === 0) {
            $slug = sanitize_key(substr($source, 8));
            $shortcode = '[adspirit_form_qualifier mode="inline" form="' . $slug . '"]';
        }

        // Sinal de preview: o enqueue do qualifier injeta CFG.preview e o JS
        // deixa avançar pelos obrigatórios sem preencher e NUNCA envia.
        if (!defined('ADSPIRIT_QF_PREVIEW')) define('ADSPIRIT_QF_PREVIEW', true);
        if (!defined('DONOTCACHEPAGE')) define('DONOTCACHEPAGE', true);

        status_header(200);
        nocache_headers();
        ?><!doctype html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo('charset'); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,nofollow">
    <title>Pré-visualização — AdSpirit</title>
    <?php wp_head(); ?>
    <style>
        body { margin: 0; }
        .adspirit-preview-banner {
            position: fixed; top: 0; left: 0; right: 0; z-index: 2147483647;
            background: #0F1419; color: #fff; font: 12px/1.4 -apple-system, sans-serif;
            padding: 8px 16px; text-align: center; letter-spacing: .04em;
        }
        .adspirit-preview-banner a { color: #7BE0E0; }
        body { padding-top: 34px; }
    </style>
</head>
<body class="adspirit-preview">
    <div class="adspirit-preview-banner">PRÉ-VISUALIZAÇÃO — nada é enviado; campos obrigatórios podem ser pulados. <a href="javascript:window.close();">Fechar</a></div>
    <?php echo do_shortcode($shortcode); ?>
    <script>
    // Trava universal: nenhum <form> desta página submete (builder incluso).
    document.addEventListener('submit', function (e) {
        e.preventDefault();
        e.stopImmediatePropagation();
        alert('Pré-visualização: nada é enviado.');
    }, true);
    </script>
    <?php wp_footer(); ?>
</body>
</html><?php
        exit;
    }
}
