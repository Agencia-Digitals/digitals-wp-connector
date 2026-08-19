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

    public function render_tab() {
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

        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Receber leads</span>Formulários</h2>
        <p class="as-section-help">Todos os formulários do site num lugar só. Escolha um pra visualizar, editar ou configurar — cada form carrega as próprias configurações.</p>

        <div class="fh-grid">
            <?php foreach ($cards as $c) : ?>
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

            <div class="fh-card fh-new">
                <div class="fh-body">
                    <div class="fh-title">Criar formulário</div>
                    <p class="fh-help">Escolha o formato:</p>
                    <div class="fh-new-opts">
                        <a href="<?php echo esc_url($this->admin_tab_url('qualifier')); ?>"><strong>Multi-etapas</strong><small>Uma pergunta por tela — o formato que mais converte.</small></a>
                        <a href="<?php echo esc_url($this->admin_tab_url('builder', array('new' => '1'))); ?>"><strong>Simples</strong><small>Todos os campos numa tela só.</small></a>
                        <span class="fh-soon"><strong>Chat</strong><small>Cara de conversa — em breve.</small></span>
                    </div>
                </div>
            </div>
        </div>

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
