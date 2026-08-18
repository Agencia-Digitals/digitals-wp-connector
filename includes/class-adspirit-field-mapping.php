<?php
/**
 * AdSpirit Connector — Field mapping per form.
 *
 * Cada cliente CF7 tem um form com nomes de campos diferentes
 * (your-name vs nome, your-email vs email, Telefone vs phone, etc).
 * O CRM espera nomes canônicos (definidos em AdSpirit_Settings::canonical_fields()).
 *
 * UI: aba "Forms / Field mapping" lista todos os forms CF7 do site. Pra
 * cada form, dropdown por campo canonical: "qual campo do seu form é o
 * Nome?" (lista todos os campos do form).
 *
 * Storage: array(form_id => array(canonical_field => cf7_field_name))
 *
 * Aplicação: AdSpirit_Field_Mapping::apply($form_id, $raw_data) faz
 * rename dos campos antes do POST pro CRM. Campos não-mapeados passam
 * inalterados (CRM ignora / vão pra metadata).
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Field_Mapping {
    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action(
            'adspirit_connector_render_tab_forms',
            AdSpirit_Safe_Hook::action(array($this, 'render_tab'), 'field_mapping_tab')
        );
        add_action(
            'adspirit_connector_save_forms',
            AdSpirit_Safe_Hook::action(array($this, 'handle_save'), 'field_mapping_save')
        );
    }

    /**
     * Aplica mapeamento: $raw_data com chaves do form CF7 →
     * canonical names que o CRM reconhece. Não-mapeados ficam como estão.
     */
    public static function apply($form_id, array $raw_data) {
        $map = AdSpirit_Settings::get_field_mapping_for_form($form_id);
        if (empty($map)) return $raw_data; // sem mapping = passa direto

        $out = $raw_data;
        foreach ($map as $canonical => $cf7_field) {
            if (empty($cf7_field)) continue;
            if ($cf7_field === $canonical) continue; // mesmo nome
            if (isset($raw_data[$cf7_field]) && !isset($out[$canonical])) {
                $out[$canonical] = $raw_data[$cf7_field];
                // Mantém o original também (CRM vê os dois).
            }
        }
        return $out;
    }

    public function render_tab() {
        // Reestruturação 08-18: tela CONTEXTUAL — chega-se aqui de um form
        // específico (hub Formulários) e vê-se o mapeamento DELE. Forms
        // nativos do AdSpirit (avaliação/builder) já usam os nomes que o
        // AdSpirit entende — pra eles a tela MOSTRA o de-para, não pede
        // configuração. O mapeamento editável serve pra formulários de
        // terceiros (CF7 hoje; outros builders amanhã).
        $context = isset($_GET['context']) ? sanitize_key((string) $_GET['context']) : '';
        if ($context !== '') {
            $this->render_native_context($context);
            return;
        }

        if (!class_exists('WPCF7_ContactForm')) {
            echo '<h2 class="as-section"><span class="as-kicker-inline">Mapear campos</span>De onde vem → como chega no AdSpirit</h2>';
            echo '<div class="as-notice"><p>Os formulários do AdSpirit (avaliação e criados aqui) já entregam cada campo com o nome que o AdSpirit entende — nada a configurar. Esta tela serve pra conectar formulários de <strong>outros plugins</strong> (Contact Form 7 hoje). Sem nenhum instalado, não há o que mapear.</p></div>';
            return;
        }

        $forms = WPCF7_ContactForm::find(array(
            'posts_per_page' => 50,
            'orderby' => 'date',
            'order' => 'DESC',
        ));

        if (empty($forms)) {
            echo '<div class="as-notice warn"><p>Nenhum form CF7 criado. Crie um em <code>Contact → Forms</code>.</p></div>';
            return;
        }

        $selected_form_id = isset($_GET['form_id']) ? intval($_GET['form_id']) : $forms[0]->id();
        $selected_form = null;
        foreach ($forms as $f) {
            if ($f->id() === $selected_form_id) { $selected_form = $f; break; }
        }
        if (!$selected_form) $selected_form = $forms[0];

        // v2.6: diagnóstico — para cada form, conta campos reconhecidos
        // (= bate canonical name ou tem mapping existente) vs não-reconhecidos.
        $all_status = array();
        $any_unmapped = false;
        $canonical_fields_all = AdSpirit_Settings::canonical_fields();
        foreach ($forms as $f) {
            $st = $this->form_match_status($f, $canonical_fields_all);
            $all_status[$f->id()] = $st;
            if ($st['unmatched_count'] > 0) $any_unmapped = true;
        }

        ?>
        <?php if (!$any_unmapped) : ?>
            <div class="as-notice" style="background:#d4edda; border-left-color:#28a745; padding:12px 16px; margin-bottom:16px;">
                <strong>✓ Todos os campos dos seus <?php echo count($forms); ?> form(s) estão reconhecidos automaticamente.</strong>
                Você não precisa configurar nada nessa aba — o CRM já recebe cada submission com os field names canônicos.
            </div>
        <?php else : ?>
            <div class="as-notice warn" style="padding:12px 16px; margin-bottom:16px;">
                <strong>⚠ Encontramos campos não-mapeados em alguns forms.</strong>
                Campos com nome diferente do canônico (<code>nome</code> ao invés de <code>your-name</code>, etc) chegam no CRM mas
                <strong>não são interpretados</strong> — scoring zera, dimensões ficam vazias. Configure abaixo o que falta.
            </div>
        <?php endif; ?>

        <h2 class="as-section"><span class="as-kicker-inline">Mapear campos</span>De onde vem → como chega no AdSpirit</h2>
        <p class="as-section-help">Formulários de outros plugins usam nomes próprios de campo (<code>nome</code>, <code>email-123</code>…). Aqui você diz como cada um chega no AdSpirit — escolha o formulário e ligue campo a campo. Os formulários do próprio AdSpirit não precisam disso.</p>

        <div style="display:flex; flex-wrap:wrap; gap:6px; margin: 0 0 18px;">
        <?php foreach ($forms as $f):
            $st = $all_status[$f->id()] ?? array('matched_count' => 0, 'total' => 0, 'unmatched_count' => 0);
            $badge_color = $st['unmatched_count'] === 0 ? '#28a745' : '#d39e00';
        ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=forms&form_id=' . $f->id())); ?>"
               class="button <?php echo $f->id() === $selected_form->id() ? 'button-primary' : ''; ?>"
               title="<?php echo (int) $st['matched_count']; ?> de <?php echo (int) $st['total']; ?> campos reconhecidos">
                <?php echo esc_html($f->title()); ?>
                <code style="margin-left:6px; opacity:0.7; padding:0 4px;">#<?php echo esc_html($f->id()); ?></code>
                <span style="margin-left:6px; display:inline-block; padding:1px 6px; border-radius:3px; background:<?php echo esc_attr($badge_color); ?>; color:white; font-size:10px; font-weight:600;">
                    <?php echo (int) $st['matched_count']; ?>/<?php echo (int) $st['total']; ?>
                </span>
            </a>
        <?php endforeach; ?>
        </div>

        <?php $this->render_mapping_form($selected_form); ?>
        <?php
    }

    /**
     * Contexto de form NATIVO: mostra o de-para (campo → como chega no
     * AdSpirit) em modo leitura — a edição acontece no editor do próprio
     * form (a chave `canonical` de cada campo).
     */
    private function render_native_context($context) {
        $title = '';
        $rows = array();
        if ($context === 'qualifier' && class_exists('AdSpirit_Form_Qualifier')) {
            $title = 'Form de avaliação';
            $steps = AdSpirit_Form_Qualifier::get_steps();
            $is_default = empty($steps);
            if ($is_default) {
                // Roteiro padrão embutido: de-para conhecido do playbook.
                $rows = array(
                    array('first_name + last_name', 'your-name', 'Nome do lead'),
                    array('phone', 'Telefone', 'WhatsApp'),
                    array('email', 'your-email', 'E-mail'),
                    array('company', 'empresa', 'Empresa'),
                    array('role', 'cargo', 'Cargo'),
                    array('size / market / revenue / investment / timing', 'porte, mercado, faturamento…', 'Respostas da qualificação'),
                );
            } else {
                foreach ($steps as $step) {
                    if (!is_array($step) || !empty($step['isIntro']) || !empty($step['isSuccess'])) continue;
                    if (!empty($step['fieldKey'])) {
                        $rows[] = array((string) $step['fieldKey'], (string) ($step['canonical'] ?? $step['fieldKey']), (string) ($step['title'] ?? ''));
                    }
                    foreach ((isset($step['fields']) && is_array($step['fields'])) ? $step['fields'] : array() as $f) {
                        if (!is_array($f) || empty($f['key'])) continue;
                        $rows[] = array((string) $f['key'], (string) ($f['canonical'] ?? $f['key']), (string) ($step['title'] ?? ''));
                    }
                }
            }
            $edit_url = admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=qualifier');
        } elseif (class_exists('AdSpirit_Form')) {
            $forms = AdSpirit_Form::get_forms();
            if (!isset($forms[$context]) || !is_array($forms[$context])) {
                echo '<div class="as-notice warn"><p>Formulário não encontrado.</p></div>';
                return;
            }
            $cfg = $forms[$context];
            $title = (string) ($cfg['title'] ?? $context);
            foreach ((isset($cfg['steps'][0]['fields']) && is_array($cfg['steps'][0]['fields'])) ? $cfg['steps'][0]['fields'] : array() as $f) {
                if (!is_array($f) || empty($f['name'])) continue;
                $rows[] = array((string) ($f['label'] ?? $f['name']), (string) $f['name'], '');
            }
            $edit_url = admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=builder&edit=' . rawurlencode($context));
        } else {
            echo '<div class="as-notice warn"><p>Formulário não encontrado.</p></div>';
            return;
        }
        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Mapear campos</span><?php echo esc_html($title); ?></h2>
        <p class="as-section-help">Este formulário é do AdSpirit — cada campo já chega com o nome certo, sem configuração. A tabela mostra o de-para; pra mudar um nome, edite o campo no próprio formulário.</p>
        <table class="as-table">
            <thead><tr><th>Campo no formulário</th><th>Como chega no AdSpirit</th><th>Contexto</th></tr></thead>
            <tbody>
                <?php foreach ($rows as $r) : ?>
                    <tr>
                        <td><code><?php echo esc_html($r[0]); ?></code></td>
                        <td><code><?php echo esc_html($r[1]); ?></code></td>
                        <td><small style="opacity:.7;"><?php echo esc_html($r[2]); ?></small></td>
                    </tr>
                <?php endforeach; ?>
                <?php if (empty($rows)) : ?>
                    <tr><td colspan="3" style="opacity:.6;">Sem campos ainda.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
        <p style="margin-top:10px;"><a class="button" href="<?php echo esc_url($edit_url); ?>">Editar o formulário</a></p>
        <?php
    }

    private function render_mapping_form($form) {
        $form_id = $form->id();
        $tags = $form->scan_form_tags();
        $cf7_fields = array();
        foreach ($tags as $tag) {
            $name = isset($tag->name) ? $tag->name : '';
            if (!$name) continue;
            $type = isset($tag->type) ? $tag->type : '';
            $cf7_fields[] = array('name' => $name, 'type' => $type);
        }

        $existing = AdSpirit_Settings::get_field_mapping_for_form($form_id);
        $canonical = AdSpirit_Settings::canonical_fields();

        // Auto-suggest defaults (heurística): campos com nomes próximos
        $suggestions = $this->compute_suggestions($cf7_fields, $canonical);

        AdSpirit_Menu::form_open('forms');
        ?>
        <?php $has_suggestions = !empty($suggestions); ?>
        <?php AdSpirit_Menu::card_open(
            'Mapeamento — ' . esc_html($form->title()),
            'Form ID <code>#' . esc_html($form_id) . '</code> · ' . count($cf7_fields) . ' campos detectados',
            $has_suggestions
                ? '<button type="button" class="button button-primary" form="adspirit-mapping-form" onclick="adspiritApplyAndSave()" title="Aplica sugestões e salva imediatamente">Aplicar e salvar sugestões</button>
                   <button type="button" class="button" form="adspirit-mapping-form" onclick="adspiritApplySuggestions()" title="Preenche os dropdowns; você ainda precisa clicar Salvar">Só aplicar</button>'
                : ''
        ); ?>
        <form id="adspirit-mapping-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="adspirit_save">
            <input type="hidden" name="adspirit_tab" value="forms">
            <?php wp_nonce_field('adspirit_forms_save', '_adspirit_nonce'); ?>
            <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">

            <table class="as-table">
                <thead>
                    <tr>
                        <th style="width:280px;">Como chega no AdSpirit</th>
                        <th style="width:140px;">Sugestão</th>
                        <th>Campo no seu formulário</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($canonical as $can_key => $can_label): ?>
                        <?php
                            $current = isset($existing[$can_key]) ? $existing[$can_key] : '';
                            $suggestion = isset($suggestions[$can_key]) ? $suggestions[$can_key] : '';
                        ?>
                        <tr>
                            <td>
                                <strong style="color:var(--as-ink); display:block;"><?php echo esc_html($can_label); ?></strong>
                                <code style="font-size:10.5px; margin-top:2px; display:inline-block;"><?php echo esc_html($can_key); ?></code>
                            </td>
                            <td>
                                <?php if ($suggestion): ?>
                                    <code data-canonical="<?php echo esc_attr($can_key); ?>" data-suggestion="<?php echo esc_attr($suggestion); ?>" style="color:var(--as-accent); border-color:var(--as-accent);">
                                        <?php echo esc_html($suggestion); ?>
                                    </code>
                                <?php else: ?>
                                    <span class="as-field-help">sem sugestão</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <select name="mapping[<?php echo esc_attr($can_key); ?>]" style="width:100%; max-width:340px;">
                                    <option value="">— não mapeado —</option>
                                    <?php foreach ($cf7_fields as $f): ?>
                                        <option value="<?php echo esc_attr($f['name']); ?>" <?php selected($current, $f['name']); ?>>
                                            <?php echo esc_html($f['name']); ?><?php if ($f['type']): ?> · <?php echo esc_html($f['type']); endif; ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <p class="submit">
                <button type="submit" class="button button-primary">Salvar mapeamento</button>
            </p>
        </form>

        <script>
        function adspiritApplySuggestions() {
            var applied = 0;
            document.querySelectorAll('code[data-canonical]').forEach(function(el) {
                var canKey = el.getAttribute('data-canonical');
                var suggestion = el.getAttribute('data-suggestion');
                if (!suggestion) return;
                var select = document.querySelector('select[name="mapping[' + canKey + ']"]');
                if (!select) return;
                var opt = select.querySelector('option[value="' + suggestion + '"]');
                if (opt) { select.value = suggestion; applied++; }
            });
            return applied;
        }
        function adspiritApplyAndSave() {
            var n = adspiritApplySuggestions();
            if (n === 0) { alert('Nenhuma sugestão a aplicar.'); return; }
            var form = document.getElementById('adspirit-mapping-form');
            if (form) form.submit();
        }
        </script>

        <details>
            <summary>Campos detectados neste form (<?php echo count($cf7_fields); ?>)</summary>
            <ul style="margin: 10px 0 0; padding-left: 20px;">
                <?php foreach ($cf7_fields as $f): ?>
                    <li style="margin-bottom: 4px; font-size: 12.5px;"><code><?php echo esc_html($f['name']); ?></code> · type <code><?php echo esc_html($f['type']); ?></code></li>
                <?php endforeach; ?>
            </ul>
        </details>
        <?php AdSpirit_Menu::card_close(); ?>
        <?php
    }

    /**
     * Heurística: pra cada canonical, acha o melhor candidato no form
     * baseado em similaridade de nome.
     */
    private function compute_suggestions(array $cf7_fields, array $canonical) {
        $out = array();
        $field_names = array_map(function($f){ return $f['name']; }, $cf7_fields);

        // Aliases default — feature 36 (Field_Mapping_Sync) injeta extras
        // vindos do CRM via filter `adspirit_field_mapping_aliases`.
        $aliases = apply_filters('adspirit_field_mapping_aliases', array(
            'your-name'           => array('your-name', 'name', 'nome', 'fullname', 'full-name', 'nome-completo'),
            'your-email'          => array('your-email', 'email', 'e-mail', 'mail', 'seu-email'),
            'Telefone'            => array('Telefone', 'telefone', 'phone', 'tel', 'celular', 'whatsapp', 'numero', 'fone'),
            'empresa'             => array('empresa', 'company', 'business', 'razao-social'),
            'cargo'               => array('cargo', 'position', 'role', 'job-title'),
            'Numero-funcionarios' => array('Numero-funcionarios', 'numero-funcionarios', 'employees', 'funcionarios', 'team-size'),
            'nicho'               => array('nicho', 'segment', 'industry', 'segmento', 'mercado'),
            'site-empresa'        => array('site-empresa', 'website', 'site', 'url'),
            'Investimento'        => array('Investimento', 'investimento', 'budget', 'orcamento'),
            'urgencia para começar' => array('urgencia para começar', 'urgencia', 'urgency', 'prazo'),
        ));

        foreach ($canonical as $can_key => $_label) {
            $candidates = $aliases[$can_key] ?? array($can_key);
            foreach ($candidates as $alias) {
                $alias_lower = strtolower($alias);
                foreach ($field_names as $name) {
                    if (strtolower($name) === $alias_lower) {
                        $out[$can_key] = $name;
                        break 2;
                    }
                }
            }
        }
        return $out;
    }

    /**
     * v2.6: status de mapeamento de um form individual.
     * Retorna ['total'=>N, 'matched_count'=>N, 'unmatched_count'=>N,
     *   'unmatched_fields'=>[name,...], 'has_explicit_map'=>bool]
     *
     * "matched" = campo do form com nome canônico exato OU alias conhecido
     * OU com mapping explícito salvo. "unmatched" = não cai em nenhum dos 3.
     * Campos auxiliares (submit, _wpcf7_*, recaptcha) ficam fora da contagem.
     */
    public function form_match_status($form, $canonical_fields = null) {
        if (!$form) return array('total' => 0, 'matched_count' => 0, 'unmatched_count' => 0, 'unmatched_fields' => array(), 'has_explicit_map' => false);
        $form_id = $form->id();
        $tags = $form->scan_form_tags();
        $canonical_fields = $canonical_fields ?: AdSpirit_Settings::canonical_fields();
        $existing = AdSpirit_Settings::get_field_mapping_for_form($form_id);
        $has_explicit_map = !empty($existing);

        // Aliases (reaproveita o filter)
        $aliases = apply_filters('adspirit_field_mapping_aliases', array(
            'your-name'           => array('your-name', 'name', 'nome', 'fullname', 'full-name', 'nome-completo', 'first-name'),
            'your-email'          => array('your-email', 'email', 'e-mail', 'mail', 'seu-email'),
            'Telefone'            => array('Telefone', 'telefone', 'phone', 'tel', 'celular', 'whatsapp', 'numero', 'fone'),
            'empresa'             => array('empresa', 'company', 'business', 'razao-social'),
            'cargo'               => array('cargo', 'position', 'role', 'job-title'),
            'Numero-funcionarios' => array('Numero-funcionarios', 'numero-funcionarios', 'employees', 'funcionarios'),
            'nicho'               => array('nicho', 'segment', 'industry', 'segmento'),
            'site-empresa'        => array('site-empresa', 'website', 'site', 'url'),
            'Investimento'        => array('Investimento', 'investimento', 'budget', 'orcamento'),
            'urgencia para começar' => array('urgencia para começar', 'urgencia', 'urgency', 'prazo'),
        ));

        // Lista flat de todos os aliases (lowercase) reconhecidos
        $alias_pool = array();
        foreach ($aliases as $list) foreach ($list as $a) $alias_pool[strtolower($a)] = true;

        // Mapeamentos explícitos salvos viram "reconhecidos"
        $mapped_cf7_fields = array();
        foreach ($existing as $can_key => $cf7_field) {
            if (!empty($cf7_field)) $mapped_cf7_fields[strtolower($cf7_field)] = true;
        }

        // Ignora campos auxiliares de CF7 / re-captcha / submit
        $ignore_prefixes = array('_wpcf7', 'g-recaptcha', 'recaptcha');
        $ignore_types = array('submit', 'recaptcha', 'acceptance', 'response_output');

        $total = 0;
        $matched = 0;
        $unmatched_fields = array();
        foreach ($tags as $tag) {
            $name = isset($tag->name) ? (string) $tag->name : '';
            $type = isset($tag->type) ? (string) $tag->type : '';
            if (!$name) continue;
            $skip = false;
            foreach ($ignore_prefixes as $p) if (stripos($name, $p) === 0) { $skip = true; break; }
            if ($skip || in_array($type, $ignore_types, true)) continue;

            $total++;
            $nl = strtolower($name);
            if (isset($alias_pool[$nl]) || isset($mapped_cf7_fields[$nl])) {
                $matched++;
            } else {
                $unmatched_fields[] = $name;
            }
        }

        return array(
            'total' => $total,
            'matched_count' => $matched,
            'unmatched_count' => $total - $matched,
            'unmatched_fields' => $unmatched_fields,
            'has_explicit_map' => $has_explicit_map,
        );
    }

    public function handle_save($post) {
        $form_id = intval($post['form_id'] ?? 0);
        if ($form_id <= 0) {
            add_settings_error(AdSpirit_Settings::OPTION_FIELD_MAP, 'invalid_form', 'Form inválido.');
            return;
        }
        $mapping = isset($post['mapping']) && is_array($post['mapping']) ? $post['mapping'] : array();
        $sanitized = array();
        foreach ($mapping as $can_key => $cf7_field) {
            $can_key = sanitize_text_field((string) $can_key);
            $cf7_field = sanitize_text_field((string) $cf7_field);
            if ($cf7_field !== '') $sanitized[$can_key] = $cf7_field;
        }
        AdSpirit_Settings::set_field_mapping_for_form($form_id, $sanitized);
        add_settings_error(AdSpirit_Settings::OPTION_FIELD_MAP, 'saved', sprintf('Mapeamento do form #%d salvo (%d campos).', $form_id, count($sanitized)), 'updated');
    }
}
