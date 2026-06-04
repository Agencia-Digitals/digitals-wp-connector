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
        if (!class_exists('WPCF7_ContactForm')) {
            echo '<div class="as-notice warn"><p>Contact Form 7 não está instalado. Sem isso não há forms pra mapear.</p></div>';
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

        ?>
        <h2 class="as-section"><span class="as-kicker-inline">Field mapping</span>Forms detectados</h2>
        <p class="as-section-help">Cada cliente tem nomes de campo diferentes (<code>nome</code> vs <code>your-name</code>). Mapeie aqui pra que o CRM reconheça cada campo independente do nome usado no form.</p>

        <div style="display:flex; flex-wrap:wrap; gap:6px; margin: 0 0 18px;">
        <?php foreach ($forms as $f): ?>
            <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=forms&form_id=' . $f->id())); ?>"
               class="button <?php echo $f->id() === $selected_form->id() ? 'button-primary' : ''; ?>">
                <?php echo esc_html($f->title()); ?>
                <code style="margin-left:6px; opacity:0.7; padding:0 4px;">#<?php echo esc_html($f->id()); ?></code>
            </a>
        <?php endforeach; ?>
        </div>

        <?php $this->render_mapping_form($selected_form); ?>
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
        <?php AdSpirit_Menu::card_open('Mapeamento — ' . esc_html($form->title()), 'Form ID <code>#' . esc_html($form_id) . '</code> · ' . count($cf7_fields) . ' campos detectados', '<button type="button" class="button button-primary" form="adspirit-mapping-form" onclick="adspiritApplySuggestions()">Aplicar sugestões</button>'); ?>
        <form id="adspirit-mapping-form" method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
            <input type="hidden" name="action" value="adspirit_save">
            <input type="hidden" name="adspirit_tab" value="forms">
            <?php wp_nonce_field('adspirit_forms_save', '_adspirit_nonce'); ?>
            <input type="hidden" name="form_id" value="<?php echo esc_attr($form_id); ?>">

            <table class="as-table">
                <thead>
                    <tr>
                        <th style="width:280px;">Campo no CRM</th>
                        <th style="width:140px;">Sugestão</th>
                        <th>Campo neste form CF7</th>
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
            document.querySelectorAll('code[data-canonical]').forEach(function(el) {
                var canKey = el.getAttribute('data-canonical');
                var suggestion = el.getAttribute('data-suggestion');
                if (!suggestion) return;
                var select = document.querySelector('select[name="mapping[' + canKey + ']"]');
                if (!select) return;
                var opt = select.querySelector('option[value="' + suggestion + '"]');
                if (opt) select.value = suggestion;
            });
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
