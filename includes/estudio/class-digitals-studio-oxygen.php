<?php
/**
 * Digitals Studio — Conversor Oxygen classic → Oxygen 6.
 *
 * Era um plugin separado (Oxygen Classic Converter). Virou módulo daqui porque
 * o connector já está instalado em todo site, e manter dois plugins pro mesmo
 * time significava dois updates, dois menus e duas chances de alguém instalar
 * o errado no lugar errado.
 *
 * Só acorda quando AdSpirit_Ambiente::e_estudio() — ou seja, quando o site
 * está num endereço nosso. No domínio do cliente este arquivo até vai junto,
 * mas nenhuma ability é registrada e nenhum hook é pendurado.
 *
 * As chaves de armazenamento continuam com o prefixo antigo (agd_occ_*,
 * _agd_occ_converted_at) DE PROPÓSITO: elas guardam estado vivo de migração —
 * o mapa de componentes já criados e o marcador de rollback de cada post
 * convertido. Renomear ganharia consistência cosmética e custaria o histórico
 * de quem já foi migrado.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) { exit; }



class Digitals_Studio_Oxygen {

    const VERSION = '0.6.1';
    const BACKUP_MARKER = '_agd_occ_converted_at';
    const SETTINGS_OPTION = 'agd_occ_settings';
    const SELECTORS_OPTION = 'oxygen_oxy_selectors_json_string';
    const COMPONENT_MAP_OPTION = 'agd_occ_component_map';
    const COMPONENT_POST_TYPE = 'oxygen_block';

    /**
     * CONTRATO DE COMPATIBILIDADE COM O EDITOR (io-ts) — descoberto em 18/08/2026.
     * O frontend do Oxygen 6 é tolerante; o editor valida TUDO na abertura e um formato
     * errado repetido derruba o builder inteiro ("Oxygen Doctor ... malformed"):
     *  1. Toda árvore precisa de "status":"exported" na raiz (literal exigido pelo codec).
     *  2. Seletor NUNCA pode ter properties/bucket de breakpoint como objeto vazio — o
     *     round-trip PHP do load_document re-encoda {} como [] e o codec rejeita. Todo
     *     registro leva conteúdo inerte: {breakpoint_base:{layout:{display:null}}}.
     *  3. Unidades CSS só do enum oficial; valor sem unidade (line-height) = "custom",
     *     nunca "", " " ou "inherit". Parser de unidade preserva %/vh/vw/em/rem — o bug
     *     "width:100%" virar "100px" esmagou o grid de cases da home.
     */

    private static $element_map = [
        'ct_section'       => 'OxygenElements\\Container',
        'ct_div_block'     => 'OxygenElements\\Container',
        'ct_new_columns'   => 'OxygenElements\\Container',
        'ct_headline'      => 'OxygenElements\\Text',
        'ct_text_block'    => 'OxygenElements\\Text',
        'ct_span'          => 'OxygenElements\\Text',
        'ct_image'         => 'OxygenElements\\Image',
        'ct_link'          => 'OxygenElements\\ContainerLink',
        'ct_link_button'   => 'OxygenElements\\TextLink',
        'ct_link_text'     => 'OxygenElements\\TextLink',
        'ct_code_block'    => 'OxygenElements\\Shortcode',
        'ct_html'          => 'OxygenElements\\Shortcode',
        'ct_shortcode'     => 'OxygenElements\\Shortcode',
        'oxy_rich_text'    => 'OxygenElements\\RichText',
        'ct_fancy_icon'    => 'OxygenElements\\Shortcode',
        'oxy_dynamic_list' => 'OxygenElements\\Shortcode',
        'ct_reusable'      => 'OxygenElements\\Shortcode',
        'ct_video'         => 'OxygenElements\\Shortcode',
        'ct_inner_content' => 'OxygenElements\\TemplateContentArea',
        'oxy_map'          => 'EssentialElements\\GoogleMap',
    ];

    /** Classes estruturais do classic que o CSS legado espera encontrar. */
    private static $struct_map = [
        'ct_section'     => 'ct-section',
        'ct_div_block'   => 'ct-div-block',
        'ct_link'        => 'ct-link',
        'ct_link_text'   => 'ct-link-text',
        'ct_link_button' => 'ct-link-text',
        'ct_image'       => 'ct-image',
        'ct_fancy_icon'  => 'ct-fancy-icon',
    ];


    /**
     * Compostos do classic que ainda não têm tradução automática. Em vez de
     * achatar em silêncio, o relatório aponta o elemento nativo equivalente
     * para o time refazer a peça no builder (uma vez só, no lugar certo).
     */
    private static $composite_hints = [
        'ct_slider' => 'EssentialElements\\Advancedslider',
        'ct_slide' => 'EssentialElements\\AdvancedSlide',
        'oxy-oxyninja_slider' => 'EssentialElements\\Advancedslider',
        'oxy_gallery' => 'EssentialElements\\Gallery',
        'ct_modal' => 'EssentialElements\\Popup',
        'oxy-shape-divider' => 'EssentialElements\\FancyDivider',
        'oxy-breadcrumb' => 'EssentialElements\\Breadcrumbs',
        'oxy_pro_menu' => 'EssentialElements\\MenuBuilder',
        'oxy_tabs' => 'EssentialElements\\AdvancedTabs',
        'oxy_toggle' => 'EssentialElements\\AdvancedAccordion',
    ];

    private static $css_units = ['cm','mm','in','pc','pt','px','ch','em','rem','%','vw','vh','vmin','vmax','svw','svh','s','ms','deg','calc','auto','custom','fr','none'];

    private $selmap = [];
    private $componentes_em_conversao = [];
    private $icon_cache = [];
    private $sprite_html = [];

    /** de-para AOS -> tipos de entrada nativos do Oxygen 6. */
    private static $aos_map = [
        'fade' => 'fade',
        'fade-up' => 'slideUp', 'fade-down' => 'slideDown',
        'fade-left' => 'slideLeft', 'fade-right' => 'slideRight',
        'fade-up-right' => 'slideUp', 'fade-up-left' => 'slideUp',
        'fade-down-right' => 'slideDown', 'fade-down-left' => 'slideDown',
        'zoom-in' => 'zoomIn', 'zoom-in-up' => 'zoomIn', 'zoom-in-down' => 'zoomIn',
        'zoom-out' => 'zoomOut', 'zoom-out-up' => 'zoomOut', 'zoom-out-down' => 'zoomOut',
        'flip-up' => 'flipUp', 'flip-down' => 'flipDown',
        'flip-left' => 'flipLeft', 'flip-right' => 'flipRight',
        'slide-up' => 'slideUp', 'slide-down' => 'slideDown',
        'slide-left' => 'slideLeft', 'slide-right' => 'slideRight',
    ];

    /** Monta o entrance_animation nativo a partir das opções AOS do classic. */
    public static function entrance_from_aos($orig)
    {
        $tipo = (string)($orig['aos-type'] ?? 'fade');
        $dur = (int)($orig['aos-duration'] ?? 0) ?: 1800;   // 1800ms era o default do AOS.init do site
        $delay = (int)($orig['aos-delay'] ?? 0);
        $ent = [
            'animation_type' => self::$aos_map[$tipo] ?? 'fade',
            'duration' => ['number' => $dur, 'unit' => 'ms', 'style' => $dur . 'ms'],
            // 'ease' do CSS (default do AOS) ~ power2.out no GSAP
            'advanced' => ['once' => true, 'ease' => 'power2.out'],
        ];
        if ($delay) { $ent['delay'] = ['number' => $delay, 'unit' => 'ms', 'style' => $delay . 'ms']; }
        return $ent;
    }


    public function __construct() {
        add_action('abilities_api_init', [$this, 'register_abilities']);
        add_action('wp_abilities_api_init', [$this, 'register_abilities']);
        add_action('wp_abilities_api_categories_init', [$this, 'register_category']);
        // Blindagem do contrato NA FONTE: qualquer escrita no banco de seletores
        // (inclusive saves do próprio builder, que re-esvaziam properties) passa
        // pela sanitização — objetos vazios ganham conteúdo inerte, unidades
        // inválidas viram "custom". Sem isto o builder quebra sozinho com o tempo.
        add_filter('pre_update_option_' . self::SELECTORS_OPTION, [$this, 'sanitize_selectors_write'], 10, 2);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_legacy_css'], 5);
    }

    public function sanitize_selectors_write($value, $old_value) {
        if (!is_string($value) || $value === '') { return $value; }
        $list = json_decode($value, true);
        if (!is_array($list)) { return $value; }
        $mudou = false;
        $fix_units = function (&$arr) use (&$fix_units, &$mudou) {
            foreach ($arr as &$v) {
                if (is_array($v)) {
                    if (isset($v['unit']) && is_string($v['unit']) && !in_array($v['unit'], self::$css_units, true)) { $v['unit'] = 'custom'; $mudou = true; }
                    $fix_units($v);
                }
            }
        };
        foreach ($list as &$s) {
            if (!is_array($s)) { continue; }
            $p = $s['properties'] ?? null;
            if ($p === [] || $p === null) { $s['properties'] = self::inert_properties(); $mudou = true; continue; }
            if (is_array($p)) {
                foreach ($s['properties'] as &$bucket) {
                    if ($bucket === []) { $bucket = self::inert_properties()['breakpoint_base']; $mudou = true; }
                }
                unset($bucket);
                $fix_units($s['properties']);
            }
        }
        unset($s);
        return $mudou ? wp_json_encode($list) : $value;
    }

    /** A Abilities API exige categoria registrada; sem ela o registro falha em silêncio. */
    public function register_category() {
        if (function_exists('wp_register_ability_category')) {
            wp_register_ability_category('digitals-studio', [
                'label' => 'Oxygen Migrator',
                'description' => 'Conversão Oxygen classic -> Oxygen 6 e manutenção do contrato do editor.',
            ]);
        }
    }

    /* ============================ Settings ============================ */

    /** Config por site mora no DB (nunca em env — onboarding sem acesso a infra). */
    public function get_settings() {
        $s = get_option(self::SETTINGS_OPTION, []);
        return wp_parse_args(is_array($s) ? $s : [], [
            'origin_url' => '',        // site classic de origem (ex.: https://agenciadigitals.com.br). Vazio = o próprio site.
            'assets_dir' => 'agd-legacy',
            'purge_litespeed' => true,
        ]);
    }

    private function origin_base() {
        $s = $this->get_settings();
        return rtrim($s['origin_url'] ?: home_url(), '/');
    }

    private function assets_path() {
        $s = $this->get_settings();
        return WP_CONTENT_DIR . '/uploads/' . trim($s['assets_dir'], '/');
    }

    private function assets_url() {
        $s = $this->get_settings();
        return content_url('uploads/' . trim($s['assets_dir'], '/'));
    }

    /* ============================ Abilities ============================ */

    public function register_abilities() {
        if (!function_exists('wp_register_ability')) { return; }
        // Ser administrador do site não basta: no site do cliente o próprio
        // cliente é administrador. A tranca é ser da Digitals E poder
        // administrar. Sem a classe de ambiente, cai no critério antigo.
        $perm = function () {
            if (class_exists('AdSpirit_Ambiente')) {
                return AdSpirit_Ambiente::pode_operar_pelo_agente();
            }
            return current_user_can('manage_options');
        };

        wp_register_ability('digitals-studio/inventory', [
            'category' => 'digitals-studio',
            'meta' => [
                // `public` é o que a rota do core (wp-abilities/v1) filtra;
                // `mcp.public` é o que o adaptador de MCP olha. Precisa dos
                // dois, senão a operação existe no PHP e some da API.
                'public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true],
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
            'label' => 'Inventário de conversão Oxygen classic',
            'description' => 'Lista posts com dados do Oxygen classic (_ct_builder_*), com histograma de tipos de elemento e status de conversão. Somente leitura.',
            'input_schema' => ['type' => 'object', 'default' => new stdClass(), 'properties' => [
                'post_type' => ['type' => 'string', 'description' => 'Opcional: limitar a um post type (page, post, cases, ct_template...).'],
            ]],
            'execute_callback' => [$this, 'ability_inventory'],
            'permission_callback' => $perm,
        ]);

        wp_register_ability('digitals-studio/inspect-post', [
            'category' => 'digitals-studio',
            'meta' => [
                // `public` é o que a rota do core (wp-abilities/v1) filtra;
                // `mcp.public` é o que o adaptador de MCP olha. Precisa dos
                // dois, senão a operação existe no PHP e some da API.
                'public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true],
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
            'label' => 'Inspecionar post legado',
            'description' => 'Mostra a árvore legada resumida de um post: elementos, dinâmicos, reutilizáveis, classes que ainda não resolvem para seletores do Oxygen 6. Somente leitura.',
            'input_schema' => ['type' => 'object', 'required' => ['post_id'], 'properties' => [
                'post_id' => ['type' => 'integer'],
            ]],
            'execute_callback' => [$this, 'ability_inspect'],
            'permission_callback' => $perm,
        ]);

        wp_register_ability('digitals-studio/convert-post', [
            'category' => 'digitals-studio',
            'meta' => [
                // `public` é o que a rota do core (wp-abilities/v1) filtra;
                // `mcp.public` é o que o adaptador de MCP olha. Precisa dos
                // dois, senão a operação existe no PHP e some da API.
                'public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true],
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            ],
            'label' => 'Converter post para Oxygen 6',
            'description' => 'Converte a árvore _ct_builder_json de src_post_id para _oxygen_data em dst_post_id (padrão: o mesmo post). dry_run=true (padrão) só devolve o relatório. Nunca altera os dados legados. Ao gravar, roda automaticamente o pós-fix (contrato do editor + caches).',
            'input_schema' => ['type' => 'object', 'required' => ['post_id'], 'properties' => [
                'post_id' => ['type' => 'integer', 'description' => 'Post de origem (com _ct_builder_json).'],
                'dst_post_id' => ['type' => 'integer', 'description' => 'Post de destino (padrão: o próprio post_id). Permite converter um template legado para dentro de outro post.'],
                'dry_run' => ['type' => 'boolean', 'default' => true],
                'overwrite' => ['type' => 'boolean', 'default' => false, 'description' => 'Permitir sobrescrever uma árvore Oxygen 6 já existente no destino.'],
            ]],
            'execute_callback' => [$this, 'ability_convert'],
            'permission_callback' => $perm,
        ]);

        wp_register_ability('digitals-studio/rollback-post', [
            'category' => 'digitals-studio',
            'meta' => [
                // `public` é o que a rota do core (wp-abilities/v1) filtra;
                // `mcp.public` é o que o adaptador de MCP olha. Precisa dos
                // dois, senão a operação existe no PHP e some da API.
                'public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true],
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            ],
            'label' => 'Reverter conversão',
            'description' => 'Remove a árvore Oxygen 6 gravada por este conversor (só quando o marcador de backup existe). Os dados legados ct_* nunca foram tocados.',
            'input_schema' => ['type' => 'object', 'required' => ['post_id'], 'properties' => [
                'post_id' => ['type' => 'integer'],
            ]],
            'execute_callback' => [$this, 'ability_rollback'],
            'permission_callback' => $perm,
        ]);

        wp_register_ability('digitals-studio/post-fix', [
            'category' => 'digitals-studio',
            'meta' => [
                // `public` é o que a rota do core (wp-abilities/v1) filtra;
                // `mcp.public` é o que o adaptador de MCP olha. Precisa dos
                // dois, senão a operação existe no PHP e some da API.
                'public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true],
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            ],
            'label' => 'Pós-correção global (contrato do editor)',
            'description' => 'Audita e corrige o banco de seletores (objetos vazios -> conteúdo inerte; unidades inválidas -> custom), carimba status:exported em árvores convertidas sem ele, limpa os caches de CSS do Oxygen e o object cache. Rodar depois de qualquer escrita manual.',
            'input_schema' => ['type' => 'object', 'default' => new stdClass(), 'properties' => []],
            'execute_callback' => [$this, 'ability_post_fix'],
            'permission_callback' => $perm,
        ]);

        wp_register_ability('digitals-studio/bootstrap', [
            'category' => 'digitals-studio',
            'meta' => [
                // `public` é o que a rota do core (wp-abilities/v1) filtra;
                // `mcp.public` é o que o adaptador de MCP olha. Precisa dos
                // dois, senão a operação existe no PHP e some da API.
                'public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true],
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            ],
            'label' => 'Bootstrap de utilitários',
            'description' => 'Registra os seletores utilitários que o conversor usa (agd-hidden, agd-inner-full, slot de vídeo, neutralizador de AOS no canvas do builder, largura de wrappers de fragmento). Idempotente.',
            'input_schema' => ['type' => 'object', 'default' => new stdClass(), 'properties' => []],
            'execute_callback' => [$this, 'ability_bootstrap'],
            'permission_callback' => $perm,
        ]);

        wp_register_ability('digitals-studio/set-settings', [
            'category' => 'digitals-studio',
            'meta' => [
                // `public` é o que a rota do core (wp-abilities/v1) filtra;
                // `mcp.public` é o que o adaptador de MCP olha. Precisa dos
                // dois, senão a operação existe no PHP e some da API.
                'public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true],
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            ],
            'label' => 'Configurar conversor',
            'description' => 'Define origin_url (site classic de origem pra baixar CSS/fragmentos), assets_dir e purge_litespeed. Config mora no DB, nunca em env.',
            'input_schema' => ['type' => 'object', 'default' => new stdClass(), 'properties' => [
                'origin_url' => ['type' => 'string'],
                'assets_dir' => ['type' => 'string'],
                'purge_litespeed' => ['type' => 'boolean'],
            ]],
            'execute_callback' => [$this, 'ability_set_settings'],
            'permission_callback' => $perm,
        ]);

        wp_register_ability('digitals-studio/audit-site', [
            'category' => 'digitals-studio',
            'meta' => [
                // `public` é o que a rota do core (wp-abilities/v1) filtra;
                // `mcp.public` é o que o adaptador de MCP olha. Precisa dos
                // dois, senão a operação existe no PHP e some da API.
                'public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true],
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
            'label' => 'Vistoria de site Oxygen classic',
            'description' => 'Retrato completo de um site ainda no Oxygen classic: o que existe, o que o conversor traduz sozinho, o que precisa de mão e o tamanho do trabalho. Somente leitura — NÃO exige Oxygen 6 instalado, serve para avaliar um site antes de migrar.',
            'input_schema' => ['type' => 'object', 'default' => new stdClass(), 'properties' => [
                'detalhar_paginas' => ['type' => 'boolean', 'default' => false, 'description' => 'Inclui a lista página a página com contagem de elementos.'],
            ]],
            'execute_callback' => [$this, 'ability_audit_site'],
            'permission_callback' => $perm,
        ]);

        wp_register_ability('digitals-studio/import-global-css', [
            'category' => 'digitals-studio',
            'meta' => [
                // `public` é o que a rota do core (wp-abilities/v1) filtra;
                // `mcp.public` é o que o adaptador de MCP olha. Precisa dos
                // dois, senão a operação existe no PHP e some da API.
                'public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true],
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            ],
            'label' => 'Importar CSS global da origem',
            'description' => 'Baixa os stylesheets que o site de origem carrega (escala tipográfica, colunas, defaults, largura de página), localiza as URLs e passa a enfileirá-los aqui. Sem isso a conversão sai desproporcional.',
            'input_schema' => ['type' => 'object', 'default' => new stdClass(), 'properties' => [
                'urls_extras' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Caminhos adicionais da origem para varrer (ex.: uma LP específica).'],
            ]],
            'execute_callback' => [$this, 'ability_import_global_css'],
            'permission_callback' => $perm,
        ]);

        wp_register_ability('digitals-studio/upgrade-repeaters', [
            'category' => 'digitals-studio',
            'meta' => [
                // `public` é o que a rota do core (wp-abilities/v1) filtra;
                // `mcp.public` é o que o adaptador de MCP olha. Precisa dos
                // dois, senão a operação existe no PHP e some da API.
                'public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true],
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            ],
            'label' => 'Trocar listagens congeladas por loop nativo',
            'description' => 'Em um post já convertido, substitui os repetidores que ficaram como HTML congelado pelo elemento de loop nativo (com Componente de item e a consulta do classic). Preserva todo o resto da página.',
            'input_schema' => ['type' => 'object', 'required' => ['post_id', 'source_post_id'], 'properties' => [
                'post_id' => ['type' => 'integer', 'description' => 'Post convertido (destino).'],
                'source_post_id' => ['type' => 'integer', 'description' => 'Post legado de origem (onde estão os oxy_dynamic_list).'],
                'dry_run' => ['type' => 'boolean', 'default' => false],
            ]],
            'execute_callback' => [$this, 'ability_upgrade_repeaters'],
            'permission_callback' => $perm,
        ]);

        wp_register_ability('digitals-studio/render-diagnose', [
            'category' => 'digitals-studio',
            'meta' => [
                // `public` é o que a rota do core (wp-abilities/v1) filtra;
                // `mcp.public` é o que o adaptador de MCP olha. Precisa dos
                // dois, senão a operação existe no PHP e some da API.
                'public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true],
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
            'label' => 'Diagnóstico de render',
            'description' => 'Explica por que uma página pode não renderizar ou não abrir no builder: meta da árvore, status:exported, template aplicável (templates "everywhere" vazios sequestram!), seletores inválidos. Somente leitura.',
            'input_schema' => ['type' => 'object', 'required' => ['post_id'], 'properties' => [
                'post_id' => ['type' => 'integer'],
            ]],
            'execute_callback' => [$this, 'ability_diagnose'],
            'permission_callback' => $perm,
        ]);

        // ── Edição de conteúdo ────────────────────────────────────────────
        // As abilities acima leem e migram. Estas três editam o conteúdo do
        // construtor — o que faltava pra operar o site inteiro daqui. Valem em
        // Oxygen clássico (ct_builder_shortcodes) e no 6 (_oxygen_data): a peça
        // é a mesma, string guardada em post meta. Toda escrita tira uma foto
        // antes; restore-content desfaz. Mesma tranca das outras (estúdio +
        // Digitals) — no site do cliente nada disso existe.
        wp_register_ability('digitals-studio/read-content', [
            'category' => 'digitals-studio',
            'meta' => [
                'public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true],
                'annotations' => ['readonly' => true, 'destructive' => false, 'idempotent' => true],
            ],
            'label' => 'Ler o conteúdo cru do construtor',
            'description' => 'Devolve o valor cru de cada meta do construtor deste post (ct_builder_shortcodes, _ct_builder_shortcodes, _ct_builder_json, _oxygen_data) com o tamanho de cada um. É o que se lê ANTES de editar, pra montar um find/replace que casa com a forma guardada (URLs costumam vir com a barra escapada). Somente leitura.',
            'input_schema' => ['type' => 'object', 'required' => ['post_id'], 'properties' => [
                'post_id' => ['type' => 'integer'],
            ]],
            'execute_callback' => [$this, 'ability_read_content'],
            'permission_callback' => $perm,
        ]);

        wp_register_ability('digitals-studio/edit-content', [
            'category' => 'digitals-studio',
            'meta' => [
                'public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true],
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => false],
            ],
            'label' => 'Editar o conteúdo do construtor (find/replace)',
            'description' => 'Troca uma string por outra dentro da meta do construtor de um post, com foto de segurança antes de gravar. find/replace em vez de reescrever a árvore inteira: erra pra menos, nunca corrompe o post. Recusa se não achar o find, ou se o número de ocorrências não bater com expected_count (guarda contra troca em massa sem querer). Limpa os caches (Oxygen, object, LiteSpeed). Devolve o token da foto pra desfazer com restore-content.',
            'input_schema' => ['type' => 'object', 'required' => ['post_id', 'find', 'replace'], 'properties' => [
                'post_id' => ['type' => 'integer'],
                'find' => ['type' => 'string', 'description' => 'Trecho exato a procurar, na forma como está guardado (veja read-content).'],
                'replace' => ['type' => 'string', 'description' => 'O que entra no lugar.'],
                'meta_key' => ['type' => 'string', 'description' => 'Opcional. Qual meta editar. Vazio = detecta sozinho (ct_builder_shortcodes, senão _ct_builder_shortcodes, senão _oxygen_data).'],
                'expected_count' => ['type' => 'integer', 'description' => 'Opcional. Quantas ocorrências espero trocar. Se não bater, recusa e não grava.'],
            ]],
            'execute_callback' => [$this, 'ability_edit_content'],
            'permission_callback' => $perm,
        ]);

        wp_register_ability('digitals-studio/restore-content', [
            'category' => 'digitals-studio',
            'meta' => [
                'public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true],
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
            'label' => 'Desfazer uma edição de conteúdo',
            'description' => 'Restaura a meta do construtor a partir de uma foto tirada por edit-content. Sem token, lista as fotos disponíveis do post (as 10 últimas) em vez de restaurar. Limpa os caches.',
            'input_schema' => ['type' => 'object', 'required' => ['post_id'], 'properties' => [
                'post_id' => ['type' => 'integer'],
                'token' => ['type' => 'string', 'description' => 'Token devolvido por edit-content. Vazio = só lista as fotos.'],
            ]],
            'execute_callback' => [$this, 'ability_restore_content'],
            'permission_callback' => $perm,
        ]);

        // ── SEO por post ──────────────────────────────────────────────────
        // O Rank Math guarda o foco/título/descrição em post meta que ele NÃO
        // expõe no REST — então nem o wp/v2 nem as abilities dele escrevem por
        // post. Esta operação escreve só essas chaves conhecidas (não é setter
        // de meta genérico), no mesmo gate estúdio+Digitals.
        wp_register_ability('digitals-studio/set-seo', [
            'category' => 'digitals-studio',
            'meta' => [
                'public' => true, 'show_in_rest' => true, 'mcp' => ['public' => true],
                'annotations' => ['readonly' => false, 'destructive' => false, 'idempotent' => true],
            ],
            'label' => 'Definir SEO (Rank Math) de um post',
            'description' => 'Grava palavra-chave de foco, título e/ou meta descrição do Rank Math num post. Só os campos enviados são tocados; os demais ficam. Lê de volta o que gravou.',
            'input_schema' => ['type' => 'object', 'required' => ['post_id'], 'properties' => [
                'post_id' => ['type' => 'integer'],
                'focus_keyword' => ['type' => 'string', 'description' => 'Palavra-chave de foco. Várias, separe por vírgula (a primeira é a principal).'],
                'title' => ['type' => 'string', 'description' => 'Opcional: título SEO.'],
                'description' => ['type' => 'string', 'description' => 'Opcional: meta descrição.'],
            ]],
            'execute_callback' => [$this, 'ability_set_seo'],
            'permission_callback' => $perm,
        ]);
    }

    /* ============================== SEO por post ========================== */

    public function ability_set_seo($input) {
        $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;
        if (!$post_id || !get_post($post_id)) { return ['erro' => 'post não encontrado', 'post_id' => $post_id]; }
        $mapa = [
            'focus_keyword' => 'rank_math_focus_keyword',
            'title' => 'rank_math_title',
            'description' => 'rank_math_description',
        ];
        $gravado = [];
        foreach ($mapa as $campo => $meta_key) {
            if (!isset($input[$campo]) || !is_string($input[$campo])) { continue; }
            update_post_meta($post_id, $meta_key, sanitize_text_field($input[$campo]));
            $gravado[$campo] = get_post_meta($post_id, $meta_key, true);
        }
        if (empty($gravado)) { return ['erro' => 'nada pra gravar — envie focus_keyword, title ou description']; }
        clean_post_cache($post_id);
        return ['ok' => true, 'post_id' => $post_id, 'gravado' => $gravado];
    }

    /* ========================= Edição de conteúdo ========================= */

    /** Metas onde algum construtor guarda a árvore, da mais "ao vivo" à menos. */
    private static $content_metas = ['ct_builder_shortcodes', '_ct_builder_shortcodes', '_ct_builder_json', '_oxygen_data'];
    const EDIT_BACKUPS = '_agd_edit_backups';

    public function ability_read_content($input) {
        $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;
        if (!$post_id || !get_post($post_id)) { return ['erro' => 'post não encontrado', 'post_id' => $post_id]; }
        $out = ['post_id' => $post_id, 'title' => get_the_title($post_id), 'metas' => []];
        foreach (self::$content_metas as $k) {
            $v = get_post_meta($post_id, $k, true);
            if (!is_string($v) || $v === '') { continue; }
            $out['metas'][$k] = ['bytes' => strlen($v), 'value' => $v];
        }
        if (empty($out['metas'])) { $out['aviso'] = 'nenhuma meta de construtor neste post'; }
        return $out;
    }

    /** Qual meta editar: a explícita, senão a primeira não-vazia da lista. */
    private function pick_content_meta($post_id, $meta_key) {
        if (is_string($meta_key) && $meta_key !== '') {
            return in_array($meta_key, self::$content_metas, true) ? $meta_key : null;
        }
        foreach (self::$content_metas as $k) {
            $v = get_post_meta($post_id, $k, true);
            if (is_string($v) && $v !== '') { return $k; }
        }
        return null;
    }

    public function ability_edit_content($input) {
        $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;
        if (!$post_id || !get_post($post_id)) { return ['erro' => 'post não encontrado', 'post_id' => $post_id]; }
        $find = isset($input['find']) ? (string) $input['find'] : '';
        if ($find === '') { return ['erro' => 'find vazio']; }
        $replace = isset($input['replace']) ? (string) $input['replace'] : '';

        $meta_key = $this->pick_content_meta($post_id, $input['meta_key'] ?? '');
        if ($meta_key === null) { return ['erro' => 'meta de construtor não encontrada (meta_key inválida ou post sem árvore)']; }

        $atual = (string) get_post_meta($post_id, $meta_key, true);
        $ocorrencias = substr_count($atual, $find);
        if ($ocorrencias === 0) {
            return ['erro' => 'find não encontrado nessa meta', 'meta_key' => $meta_key, 'dica' => 'rode read-content: a forma guardada pode ter a barra escapada (\\/) ou aspas HTML.'];
        }
        if (isset($input['expected_count']) && (int) $input['expected_count'] !== $ocorrencias) {
            return ['erro' => 'expected_count não bate — nada gravado', 'esperado' => (int) $input['expected_count'], 'encontrado' => $ocorrencias, 'meta_key' => $meta_key];
        }

        // Foto antes de gravar. Guarda valor + meta_key + hora; mantém as 10
        // últimas por post. O token é a hora em microssegundos.
        $token = (string) round(microtime(true) * 1000);
        $fotos = get_post_meta($post_id, self::EDIT_BACKUPS, true);
        if (!is_array($fotos)) { $fotos = []; }
        $fotos[$token] = ['meta_key' => $meta_key, 'value' => $atual, 'quando' => current_time('mysql'), 'find' => $find];
        if (count($fotos) > 10) { $fotos = array_slice($fotos, -10, null, true); }
        update_post_meta($post_id, self::EDIT_BACKUPS, $fotos);

        $novo = str_replace($find, $replace, $atual);
        update_post_meta($post_id, $meta_key, wp_slash($novo));

        return [
            'ok' => true,
            'post_id' => $post_id,
            'meta_key' => $meta_key,
            'trocas' => $ocorrencias,
            'bytes_antes' => strlen($atual),
            'bytes_depois' => strlen($novo),
            'backup_token' => $token,
            'caches' => $this->limpar_caches_do_post($post_id),
            'nota' => 'confira no front-end; desfaz com restore-content e este token.',
        ];
    }

    public function ability_restore_content($input) {
        $post_id = isset($input['post_id']) ? (int) $input['post_id'] : 0;
        if (!$post_id || !get_post($post_id)) { return ['erro' => 'post não encontrado', 'post_id' => $post_id]; }
        $fotos = get_post_meta($post_id, self::EDIT_BACKUPS, true);
        if (!is_array($fotos) || !$fotos) { return ['erro' => 'sem fotos pra este post']; }

        $token = isset($input['token']) ? (string) $input['token'] : '';
        if ($token === '') {
            $lista = [];
            foreach ($fotos as $t => $f) { $lista[] = ['token' => $t, 'meta_key' => $f['meta_key'], 'quando' => $f['quando'], 'find' => $f['find'] ?? '']; }
            return ['fotos' => $lista, 'nota' => 'passe um token pra restaurar.'];
        }
        if (!isset($fotos[$token])) { return ['erro' => 'token não encontrado', 'token' => $token]; }

        $f = $fotos[$token];
        update_post_meta($post_id, $f['meta_key'], wp_slash($f['value']));
        return [
            'ok' => true,
            'post_id' => $post_id,
            'meta_key' => $f['meta_key'],
            'restaurado_de' => $f['quando'],
            'caches' => $this->limpar_caches_do_post($post_id),
        ];
    }

    /**
     * Limpa o que possa servir a versão velha depois de uma edição: cache de
     * post, CSS gerado do Oxygen (6 via Breakdance; clássico é arquivo por
     * página em uploads/oxygen/css) e o cache de página do LiteSpeed. Melhor
     * esforço — cada peça só roda se existir neste ambiente.
     */
    private function limpar_caches_do_post($post_id) {
        $feito = [];
        clean_post_cache($post_id);
        $feito[] = 'post_cache';
        if (function_exists('Breakdance\\Render\\clearAllCssCachesAndDeleteCachedFiles')) {
            \Breakdance\Render\clearAllCssCachesAndDeleteCachedFiles();
            $feito[] = 'css_oxygen6';
        }
        // NÃO apagar o CSS por página do Oxygen clássico
        // (uploads/oxygen/css/{id}.css): ao contrário do que se supunha, o
        // clássico NÃO regenera esse arquivo ao renderizar a página — só no
        // save do builder. Apagar deixava um 404 que o LiteSpeed herdava ao
        // recombinar o CSS. Edição de conteúdo (URL, texto, alt) não muda o
        // CSS, então o arquivo continua válido. Quem edita estilo regenera
        // pelo próprio Oxygen. Incidente 2026-08-29.
        if (has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all');
            $feito[] = 'litespeed';
        } elseif (has_action('litespeed_purge_post')) {
            do_action('litespeed_purge_post', $post_id);
            $feito[] = 'litespeed_post';
        }
        return $feito;
    }

    /* ============================ Leitura do legado ============================ */

    private function get_legacy_tree($post_id) {
        $json = get_post_meta($post_id, '_ct_builder_json', true);
        if (is_string($json) && $json !== '') {
            $data = json_decode($json, true);
            if (is_array($data)) {
                $root = isset($data['root']) ? $data['root'] : $data;
                if (!empty($root['children'])) { return ['source' => '_ct_builder_json', 'tree' => $root]; }
            }
        }
        $shortcodes = get_post_meta($post_id, '_ct_builder_shortcodes', true);
        if (is_string($shortcodes) && $shortcodes !== '') {
            $tree = $this->parse_ct_shortcodes($shortcodes);
            if ($tree) { return ['source' => '_ct_builder_shortcodes', 'tree' => ['children' => $tree]]; }
        }
        return null;
    }

    /** Parser recursivo de shortcodes ct_* (fallback quando não há _ct_builder_json). */
    private function parse_ct_shortcodes($content) {
        $nodes = [];
        $pattern = get_shortcode_regex();
        if (!preg_match_all('/' . $pattern . '/s', $content, $matches, PREG_SET_ORDER)) { return $nodes; }
        foreach ($matches as $m) {
            $tag = $m[2];
            if (strpos($tag, 'ct_') !== 0 && strpos($tag, 'oxy_') !== 0) { continue; }
            $atts = shortcode_parse_atts($m[3]);
            $opts = [];
            if (!empty($atts['ct_options'])) {
                $decoded = json_decode(html_entity_decode($atts['ct_options'], ENT_QUOTES), true);
                if (is_array($decoded)) { $opts = $decoded; }
            }
            $node = ['name' => $tag, 'options' => $opts ?: $atts, 'children' => []];
            $inner = isset($m[5]) ? $m[5] : '';
            if ($inner !== '') {
                $children = $this->parse_ct_shortcodes($inner);
                if ($children) { $node['children'] = $children; }
                else if (empty($node['options']['ct_content'])) { $node['options']['ct_content'] = trim($inner); }
            }
            $nodes[] = $node;
        }
        return $nodes;
    }

    /* ============================ Seletores ============================ */

    private function load_selmap() {
        $this->selmap = [];
        $list = json_decode((string)get_option(self::SELECTORS_OPTION), true);
        if (!is_array($list)) { return; }
        $walk = function ($items) use (&$walk) {
            foreach ((array)$items as $s) {
                if (isset($s['name'], $s['id'])) { $this->selmap[$s['name']] = $s['id']; }
                if (!empty($s['children'])) { $walk($s['children']); }
            }
        };
        $walk($list);
    }

    /** Conteúdo inerte que sobrevive ao round-trip PHP do load_document (regra 2 do contrato). */
    private static function inert_properties() {
        return ['breakpoint_base' => ['layout' => ['display' => null]]];
    }

    private function register_selectors($names) {
        if (!$names) { return 0; }
        $raw = (string)get_option(self::SELECTORS_OPTION);
        $list = json_decode($raw, true);
        if (!is_array($list)) { return 0; }
        $added = 0;
        foreach ($names as $n) {
            if (isset($this->selmap[$n])) { continue; }
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/', $n)) { continue; }
            $id = wp_generate_uuid4();
            $list[] = [
                'id' => $id, 'name' => $n, 'type' => 'class', 'locked' => false,
                'children' => [], 'collection' => 'Legado auto',
                'properties' => self::inert_properties(),
            ];
            $this->selmap[$n] = $id;
            $added++;
        }
        if ($added) { update_option(self::SELECTORS_OPTION, wp_json_encode($list)); }
        return $added;
    }

    /* ============================ Dinâmicos ============================ */

    /** Resolve nome de campo ACF -> field key (imagem_1 -> field_6419...). */
    private function acf_key($field_name) {
        if (!function_exists('acf_get_field')) { return null; }
        $f = acf_get_field($field_name);
        return ($f && !empty($f['key'])) ? $f['key'] : null;
    }

    /** Traduz shortcodes [oxygen data=...] pro equivalente [breakdance_dynamic ...]. */
    private function dyn($text) {
        return preg_replace_callback('/\[oxygen ([^\]]+)\]/', function ($m) {
            $a = $m[1];
            $get = function ($k) use ($a) { return preg_match("/$k='([^']*)'/", $a, $mm) ? $mm[1] : ''; };
            switch ($get('data')) {
                case 'title': return "[breakdance_dynamic field='post_title']";
                case 'permalink': return "[breakdance_dynamic field='post_permalink']";
                case 'content': return "[breakdance_dynamic field='post_content']";
                case 'excerpt': return "[breakdance_dynamic field='post_excerpt']";
                case 'featured_image': return "[breakdance_dynamic field='post_featured_image_url']";
                // author_name EXIGE name_field, senão volta vazio:
                case 'author': return "[breakdance_dynamic field='author_name' name_field='display_name']";
                case 'author_pic': return "[breakdance_dynamic field='author_avatar_url']";
                case 'terms':
                    $tax = $get('taxonomy') ?: 'category';
                    $sep = $get('separator') ?: ', ';
                    return "[breakdance_dynamic field='post_terms' taxonomy='$tax' separator='$sep']";
                // post_date sem type= vira data de MODIFICAÇÃO no Breakdance — forçar type=date:
                case 'date': return "[breakdance_dynamic field='post_date' type='date' format='j/m/Y']";
                case 'custom_acf_content':
                    $key = $this->acf_key($get('settings_path'));
                    return $key ? "[breakdance_dynamic field='acf_field_$key']" : '';
            }
            return '';
        }, (string)$text);
    }

    /** Companion _dynamic_meta obrigatório quando a propriedade é exatamente 1 shortcode dinâmico. */
    private static function dynamic_meta($shortcode) {
        if (!preg_match("/^\[breakdance_dynamic field='([^']+)'/", $shortcode, $m)) { return null; }
        return ['field' => $m[1], 'shortcode' => $shortcode, 'attributes' => new stdClass()];
    }

    /* ============================ Assets do site de origem ============================ */

    /**
     * Troca o domínio da origem pelo domínio local. Uma passada só e ancorada no
     * início do host: substituição ingênua transformava "dev.exemplo.com" em
     * "dev.dev.exemplo.com" quando a origem é "exemplo.com" (quebrava imagens).
     */
    private function localize($url) {
        $url = (string)$url;
        $origem = preg_replace('#^https?://#', '', $this->origin_base());
        $local = preg_replace('#^https?://#', '', home_url());
        if ($origem === '' || $origem === $local) { return $url; }
        return preg_replace('#(https?://)(?:www\.)?' . preg_quote($origem, '#') . '\b#i', '$1' . $local, $url);
    }

    private function fetch($url) {
        return (string)wp_remote_retrieve_body(wp_remote_get($url, ['timeout' => 30]));
    }

    /** Extrai do HTML renderizado da origem o markup de um elemento (repeaters, listas). */
    private function extract_fragment($html, $element_id) {
        $pos = strpos($html, 'id="' . $element_id . '"');
        if ($pos === false) { return ''; }
        $start = strrpos(substr($html, 0, $pos), '<');
        if ($start === false) { return ''; }
        $depth = 0; $i = $start; $len = strlen($html);
        while ($i < $len) {
            $open = strpos($html, '<div', $i);
            $close = strpos($html, '</div>', $i);
            if ($close === false) { break; }
            if ($open !== false && $open < $close) { $depth++; $i = $open + 4; }
            else { $depth--; $i = $close + 6; if ($depth <= 0) { return substr($html, $start, $i - $start); } }
        }
        return '';
    }

    /** Remove lazy-load do EWWW dos fragmentos (senão as imagens nunca carregam). */
    private function delazy($frag) {
        $frag = preg_replace('/src="data:image[^"]*"\s*/', '', $frag);
        $frag = str_replace(['data-src=', 'data-srcset='], ['src=', 'srcset='], $frag);
        $frag = str_replace([' lazyload', ' ewww_webp_lazy_load'], '', $frag);
        return $frag;
    }

    /** Baixa da origem o CSS por página + extrai code-css/js dos code blocks. */
    private function ensure_assets($src_id, $legacy_tree) {
        $dir = $this->assets_path();
        if (!is_dir($dir)) { wp_mkdir_p($dir); }
        $origin_host = preg_replace('#^https?://#', '', $this->origin_base());
        $local_host = preg_replace('#^https?://#', '', home_url());
        $css_path = "$dir/post-$src_id.css";
        if (!file_exists($css_path)) {
            $c = $this->fetch($this->origin_base() . "/wp-content/uploads/oxygen/css/$src_id.css");
            if ($c !== '') { $c = str_replace($origin_host, $local_host, $c); }
            file_put_contents($css_path, $c !== '' ? $c : '/* vazio */');
        }
        $css = ''; $js = '';
        $walk = function ($n) use (&$walk, &$css, &$js) {
            if (($n['name'] ?? '') === 'ct_code_block') {
                $o = $n['options']['original'] ?? [];
                if (!empty($o['code-css'])) { $css .= $o['code-css'] . "\n"; }
                if (!empty($o['code-js'])) { $js .= $o['code-js'] . "\n"; }
            }
            foreach (($n['children'] ?? []) as $c2) { $walk($c2); }
        };
        foreach (($legacy_tree['children'] ?? []) as $c3) { $walk($c3); }
        if ($css !== '') { file_put_contents("$dir/code-$src_id.css", str_replace($origin_host, $local_host, $css)); }
        if ($js !== '') { file_put_contents("$dir/code-$src_id.js", $js); }
    }

    /* ============================ Motor de conversão ============================ */

    private function convert($children, &$next_id, &$report, $src_id, $prod_html) {
        $out = [];
        foreach ((array)$children as $node) {
            if (!is_array($node)) { continue; }
            $name = $node['name'] ?? '';
            if ($name === '' || $name === 'root') {
                if (!empty($node['children'])) { $out = array_merge($out, $this->convert($node['children'], $next_id, $report, $src_id, $prod_html)); }
                continue;
            }
            $opts = (array)($node['options'] ?? []);
            $orig = (array)($opts['original'] ?? []);
            $selector = (string)($opts['selector'] ?? '');
            $type = self::$element_map[$name] ?? null;
            if ($type === null) {
                $report['unmapped'][$name] = ($report['unmapped'][$name] ?? 0) + 1;
                if (isset(self::$composite_hints[$name])) {
                    $report['refazer_no_builder'][$selector ?: $name] = self::$composite_hints[$name];
                }
                $type = 'OxygenElements\\Container';
            }
            $report['count']++;
            $props = [];
            $is_fragment = ($name === 'oxy_dynamic_list');

            // Classes: seletor próprio + classes custom + estruturais; display:none e modais escondem
            $class_names = $selector ? [$selector] : [];
            foreach ((array)($opts['classes'] ?? []) as $cn) { $class_names[] = $cn; }
            if (isset(self::$struct_map[$name])) { $class_names[] = self::$struct_map[$name]; }
            if (($orig['display'] ?? '') === 'none') { $class_names[] = 'agd-hidden'; }
            if (in_array($name, ['ct_modal', 'oxy_comments', 'oxy_comment_form'], true)) { $class_names[] = 'agd-hidden'; }
            if (!$is_fragment) {
                $uuids = [];
                foreach ($class_names as $cn) {
                    if (isset($this->selmap[$cn])) { $uuids[] = $this->selmap[$cn]; }
                    elseif ($cn !== '') { $report['pendentes'][$cn] = true; }
                }
                if ($uuids) { $props['meta']['classes'] = array_values(array_unique($uuids)); }
            }

            // Tag semântica
            $tag = null;
            if ($name === 'ct_section') { $tag = 'section'; }
            elseif ($name === 'ct_headline') { $tag = $orig['tag'] ?? 'h1'; }
            if ($tag) { $props['settings']['advanced']['tag'] = $tag; }

            // Atributos: id legado (CSS por id + âncoras) + AOS
            $attrs = [];
            if ($selector && !$is_fragment && $name !== 'ct_reusable') { $attrs[] = ['name' => 'id', 'value' => $selector]; }
            // AOS do classic -> animação de entrada NATIVA do Oxygen 6.
            // Nativo em vez de carregar a lib AOS: previsualiza no builder por
            // construção, tira uma dependência de JS da página e sobrevive a
            // qualquer mudança de tema/cache.
            if (($orig['aos-enable'] ?? '') === 'true') {
                $props['settings']['animations'] = ['entrance_animation' => self::entrance_from_aos($orig)];
            }
            if ($attrs) { $props['settings']['advanced']['attributes'] = $attrs; }

            // Placeholders: o span filho segura o shortcode; Text do O6 ignora filhos,
            // então a substituição TEM que ser inline e o filho, consumido.
            $text = $this->dyn((string)($opts['ct_content'] ?? ''));
            if ($text !== '' && strpos($text, 'ct-placeholder-') !== false && !empty($node['children'])) {
                $consumed = [];
                foreach ($node['children'] as $ci => $child) {
                    $cid = $child['options']['ct_id'] ?? null;
                    if ($cid && strpos($text, 'ct-placeholder-' . $cid) !== false) {
                        $copts = (array)($child['options'] ?? []);
                        $ctext = $this->dyn((string)($copts['ct_content'] ?? ''));
                        $ccls = trim((string)($copts['selector'] ?? '') . ' ' . implode(' ', (array)($copts['classes'] ?? [])));
                        $rep = '<span id="' . esc_attr((string)($copts['selector'] ?? '')) . '" class="' . esc_attr($ccls) . '">' . $ctext . '</span>';
                        $text = str_replace('<span id="ct-placeholder-' . $cid . '"></span>', $rep, $text);
                        $consumed[$ci] = true;
                    }
                }
                if ($consumed) { $node['children'] = array_values(array_diff_key($node['children'], $consumed)); }
            }

            $skip_children = false;
            $sub_kids = null;
            switch ($name) {
                case 'ct_headline': case 'ct_text_block': case 'ct_span':
                    if ($text !== '') {
                        $props['content']['content']['text'] = $text;
                        $meta = self::dynamic_meta($text);
                        if ($meta) { $props['content']['content']['text_dynamic_meta'] = $meta; }
                    }
                    break;

                case 'ct_image':
                    // Imagem dinâmica de ACF (attachment id): bind nativo media_library
                    if (($orig['image_type'] ?? '') === '2' && !empty($orig['attachment_id']) && strpos((string)$orig['attachment_id'], 'custom_acf_image_id') !== false) {
                        $path = preg_match("/settings_path='([^']+)'/", (string)$orig['attachment_id'], $mm) ? $mm[1] : '';
                        $key = $path ? $this->acf_key($path) : null;
                        if ($key) {
                            $sc = "[breakdance_dynamic field='acf_image_field_$key']";
                            $props['content']['image'] = [
                                'from' => 'media_library', 'media' => $sc,
                                'media_dynamic_meta' => self::dynamic_meta($sc),
                                'size' => $orig['attachment_size'] ?? 'full',
                                'alt' => 'from_media_library', 'lazy_load' => true,
                            ];
                            break;
                        }
                        $report['dinamico_falhou'][] = "$selector (acf $path)";
                    }
                    $src = $this->dyn($orig['attachment_url'] ?? ($orig['src'] ?? ''));
                    if (strpos($src, 'breakdance_dynamic') !== false) {
                        $type = 'OxygenElements\\Shortcode';
                        $props['content']['shortcode']['full_shortcode'] = '<img src="' . $src . '" alt="" style="max-width:100%;">';
                        break;
                    }
                    if ($src) {
                        $img = ['from' => 'url', 'url' => $this->localize($src), 'lazy_load' => true];
                        $alt = $orig['alt'] ?? '';
                        if (!$alt && !empty($orig['attachment_id']) && is_numeric($orig['attachment_id'])) {
                            $alt = (string)get_post_meta((int)$orig['attachment_id'], '_wp_attachment_image_alt', true);
                        }
                        if ($alt) { $img['alt_when_from_url'] = 'custom'; $img['custom_alt_when_from_url'] = $alt; }
                        else { $report['sem_alt']++; }
                        $props['content']['image'] = $img;
                    }
                    break;

                case 'ct_link':
                    if (!empty($orig['url'])) { $props['content']['content']['url'] = $this->localize($this->dyn($orig['url'])); }
                    break;

                case 'ct_link_text': case 'ct_link_button':
                    if (!empty($orig['url'])) { $props['content']['content']['url'] = $this->localize($this->dyn($orig['url'])); }
                    if ($text !== '') { $props['content']['content']['text'] = wp_strip_all_tags($text); }
                    break;

                case 'ct_fancy_icon':
                    // Ícone vira ELEMENTO DE ÍCONE nativo com o SVG embutido: fica
                    // editável no builder e para de depender do sprite da origem
                    // (que o classic gera por página — fonte de ícone invisível).
                    $icon_id = $orig['icon-id'] ?? '';
                    $svg = $icon_id ? $this->icon_svg($icon_id, $src_id) : '';
                    if ($svg) {
                        $type = 'OxygenElements\\SvgIcon2';
                        $props['content'] = ['content' => ['icon' => [
                            'id' => 0, 'slug' => 'custom', 'name' => $icon_id,
                            'iconSetSlug' => 'custom', 'svgCode' => $svg,
                        ]]];
                    } elseif ($icon_id) {
                        // sem desenho disponível: mantém a referência ao sprite
                        $props['content']['shortcode']['full_shortcode'] = '<svg style="fill:currentColor;"><use xlink:href="#' . esc_attr($icon_id) . '" href="#' . esc_attr($icon_id) . '"></use></svg>';
                        $report['icone_sem_svg'][$icon_id] = true;
                    }
                    break;

                case 'oxy_map':
                    // Mapa vira o elemento de mapa nativo (endereço e zoom preservados),
                    // em vez de um iframe cru — fica editável no painel.
                    $props['content']['content'] = [
                        'address' => (string)($orig['map_address'] ?? ''),
                        'zoom' => (float)($orig['map_zoom'] ?? 15),
                        'use_without_api_key' => true,
                    ];
                    break;

                case 'ct_video':
                    // use-custom traz o embed pronto (Vimeo etc); senão monta iframe 16:9 do embed_src
                    if (($orig['use-custom'] ?? '') === '1' && !empty($orig['custom-code'])) {
                        $code = preg_replace('#<script[^>]*player\.vimeo\.com/api/player\.js[^<]*</script>#', '', (string)$orig['custom-code']);
                        $props['content']['shortcode']['full_shortcode'] = $code;
                    } elseif (!empty($orig['embed_src'])) {
                        $props['content']['shortcode']['full_shortcode'] = '<div style="padding:56.25% 0 0 0;position:relative;width:100%"><iframe src="' . esc_url($orig['embed_src']) . '" frameborder="0" allow="autoplay; fullscreen; picture-in-picture" style="position:absolute;top:0;left:0;width:100%;height:100%;" loading="lazy"></iframe></div>';
                    } else { $report['dinamico_falhou'][] = "$selector (video sem fonte)"; }
                    break;

                case 'ct_reusable':
                    // Reutilizável do classic -> COMPONENTE NATIVO do Oxygen 6:
                    // o template vira um post oxygen_block (criado uma única vez) e
                    // cada uso passa a REFERENCIAR esse componente. Editar num lugar
                    // reflete em todos — a experiência do classic, com a tecnologia nova.
                    $vid = (int)($opts['view_id'] ?? 0);
                    $component_id = $vid ? $this->component_for_legacy_template($vid, $report) : 0;
                    if ($component_id) {
                        $type = 'OxygenElements\\Component';
                        $props = ['content' => ['content' => ['block' => ['componentId' => $component_id]]]];
                        $report['componentes'][$vid] = $component_id;
                    } else {
                        // sem view_id o classic não renderiza nada: o nó é descartado
                        $report['reusable_falhou'][] = $selector ?: ('ct_id ' . ($opts['ct_id'] ?? '?'));
                        $type = 'OxygenElements\\Container';
                    }
                    $skip_children = true;
                    break;

                case 'oxy_dynamic_list':
                    // Repetidor vira ELEMENTO NATIVO de loop: o card do item vira um
                    // Componente e a consulta do classic (query_args) é reaproveitada.
                    // Assim a listagem volta a se atualizar sozinha quando sai post novo
                    // — como fragmento congelado ela ficava presa no momento da migração.
                    $item_id = $this->component_for_loop_item($node, $src_id, $selector, $report);
                    if ($item_id) {
                        $type = 'OxygenElements\\PostsLoop';
                        $props['content']['repeated_block']['global_block'] = $item_id;
                        $qargs = self::query_from_legacy($orig);
                        if ($qargs !== '') {
                            $props['content']['query']['query'] = ['active' => 'text', 'text' => $qargs];
                        }
                        $report['loops'][$selector] = $item_id;
                    } else {
                        // sem template de item: congela o HTML da origem (comportamento antigo)
                        $frag = $this->extract_fragment($prod_html, $selector);
                        if ($frag) {
                            $props['content']['shortcode']['full_shortcode'] = $this->localize($this->delazy($frag));
                            $report['fragmentos'][] = $selector;
                        } else { $report['frag_falhou'][] = $selector; }
                    }
                    $skip_children = true;
                    break;

                case 'ct_code_block':
                    // code-css/js já foram pro arquivo por página em ensure_assets
                    $props['content']['shortcode']['full_shortcode'] = '';
                    break;

                case 'ct_shortcode': case 'ct_html':
                    if ($text !== '') { $props['content']['shortcode']['full_shortcode'] = $text; }
                    break;
            }

            // Background dinâmico (featured image em section de hero): o Container não
            // expõe background no schema — nó <style> resolve por post.
            $style_kid = null;
            if ($selector && !empty($orig['background-image']) && strpos((string)$orig['background-image'], "data='featured_image'") !== false) {
                $css = '#' . $selector . '{background-image:url("[breakdance_dynamic field=' . "'post_featured_image_url'" . ']")!important;background-size:' . esc_attr($orig['background-size'] ?? 'cover') . '!important;}';
                $style_kid = ['id' => $next_id++, 'data' => ['type' => 'OxygenElements\\Shortcode', 'properties' => ['content' => ['shortcode' => ['full_shortcode' => '<style>' . $css . '</style>']]]], 'children' => []];
            }

            // Vídeo de fundo de section
            $video_kid = null;
            if (!empty($orig['video_background'])) {
                $vurl = $orig['video_background'];
                $overlay = $orig['video_background_overlay'] ?? 'rgba(0,0,0,0.18)';
                $vslot = $this->selmap['agd-video-slot'] ?? null;
                $vhtml = '<div class="agd-video-bg"><video autoplay loop muted playsinline src="' . esc_url($vurl) . '"></video></div><div class="agd-video-overlay" style="background-color:' . esc_attr($overlay) . '"></div>';
                $vprops = ['content' => ['shortcode' => ['full_shortcode' => $vhtml]]];
                if ($vslot) { $vprops['meta']['classes'] = [$vslot]; }
                $video_kid = ['id' => $next_id++, 'data' => ['type' => 'OxygenElements\\Shortcode', 'properties' => $vprops], 'children' => []];
            }

            $kids = [];
            if ($sub_kids !== null) { $kids = $sub_kids; }
            elseif (!$skip_children && !empty($node['children'])) { $kids = $this->convert($node['children'], $next_id, $report, $src_id, $prod_html); }

            if ($name === 'ct_section') {
                // Inner-wrap replica o container interno do classic (largura 1280 via CSS)
                $inner_classes = ['ct-section-inner-wrap'];
                if (($orig['section-width'] ?? '') === 'full-width') { $inner_classes[] = 'agd-inner-full'; }
                $iuuids = [];
                foreach ($inner_classes as $cn) { if (isset($this->selmap[$cn])) { $iuuids[] = $this->selmap[$cn]; } }
                $inner = ['id' => $next_id++, 'data' => ['type' => 'OxygenElements\\Container', 'properties' => $iuuids ? ['meta' => ['classes' => $iuuids]] : new stdClass()], 'children' => $kids];
                $pre = [];
                if ($style_kid) { $pre[] = $style_kid; }
                if ($video_kid) { $pre[] = $video_kid; }
                $kids = array_merge($pre, [$inner]);
            } else {
                if ($video_kid) { array_unshift($kids, $video_kid); }
                if ($style_kid) { array_unshift($kids, $style_kid); }
            }

            $out[] = ['id' => $next_id++, 'data' => ['type' => $type, 'properties' => $props ?: new stdClass()], 'children' => $kids];
        }
        return $out;
    }

    /** Converte um par src=>dst. Devolve [tree, report]. */
    private function convert_pair($src_id, &$report) {
        $legacy = $this->get_legacy_tree($src_id);
        if (!$legacy) { return null; }
        $this->ensure_assets($src_id, $legacy['tree']);
        $prod_html = '';
        if (strpos(wp_json_encode($legacy['tree']), 'oxy_dynamic_list') !== false) {
            $pu = str_replace(home_url(), $this->origin_base(), get_permalink($src_id) ?: '');
            if ($pu) {
                $pu .= (strpos($pu, '?') === false ? '?' : '&') . 'LSCWP_CTRL=before_optm';
                $prod_html = $this->fetch($pu);
            }
        }
        // passada 1: coleta classes pendentes e registra (com conteúdo inerte)
        $next_id = 100;
        $r1 = self::empty_report();
        $this->convert($legacy['tree']['children'], $next_id, $r1, $src_id, $prod_html);
        $novos = $this->register_selectors(array_keys($r1['pendentes']));
        // passada 2: conversão final com todas as classes resolvendo
        $next_id = 100;
        $report = self::empty_report();
        $report['novos_seletores'] = $novos;
        $children = $this->convert($legacy['tree']['children'], $next_id, $report, $src_id, $prod_html);
        // assets da página como primeiro filho
        $base = $this->assets_url();
        $assets = '<link rel="stylesheet" href="' . $base . '/post-' . $src_id . '.css">';
        if (file_exists($this->assets_path() . '/code-' . $src_id . '.css')) { $assets .= '<link rel="stylesheet" href="' . $base . '/code-' . $src_id . '.css">'; }
        if (file_exists($this->assets_path() . '/code-' . $src_id . '.js')) { $assets .= '<script src="' . $base . '/code-' . $src_id . '.js" defer></script>'; }
        array_unshift($children, ['id' => $next_id++, 'data' => ['type' => 'OxygenElements\\Shortcode', 'properties' => ['content' => ['shortcode' => ['full_shortcode' => $assets]]]], 'children' => []]);
        // Regra 1 do contrato: status exported, sempre.
        return [
            'root' => ['id' => 1, 'data' => ['type' => 'root', 'properties' => []], 'children' => $children],
            '_nextNodeId' => $next_id,
            'status' => 'exported',
        ];
    }


    /**
     * Devolve o post_id do Componente nativo correspondente a um template
     * legado (ct_template usado como reusable). Cria na primeira vez, converte
     * a árvore do template para dentro dele e memoriza no mapa. Reentrante:
     * um reusable dentro de outro não entra em laço.
     */
    private function component_for_legacy_template($legacy_id, &$report)
    {
        $mapa = get_option(self::COMPONENT_MAP_OPTION, []);
        if (!is_array($mapa)) { $mapa = []; }
        if (!empty($mapa[$legacy_id]) && get_post_status($mapa[$legacy_id])) {
            return (int)$mapa[$legacy_id];
        }
        if (!empty($this->componentes_em_conversao[$legacy_id])) {
            return 0; // ciclo: um reusable referenciando a si mesmo
        }
        $legacy = get_post($legacy_id);
        if (!$legacy) { return 0; }

        $component_id = wp_insert_post([
            'post_type' => self::COMPONENT_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => $legacy->post_title ?: ('Componente ' . $legacy_id),
        ]);
        if (is_wp_error($component_id) || !$component_id) { return 0; }

        $this->componentes_em_conversao[$legacy_id] = true;
        $sub_report = self::empty_report();
        $tree = $this->convert_pair($legacy_id, $sub_report);
        unset($this->componentes_em_conversao[$legacy_id]);

        if (!$tree) { wp_delete_post($component_id, true); return 0; }
        // Texto e link viram campos editáveis por instância: o mesmo componente
        // serve páginas com rótulos diferentes sem duplicar a peça (o classic
        // não tinha isso — reusable era tudo-ou-nada).
        self::mark_editable_properties($tree['root'], $report);
        update_post_meta($component_id, '_oxygen_data', wp_slash(wp_json_encode(['tree_json_string' => wp_json_encode($tree)])));
        update_post_meta($component_id, self::BACKUP_MARKER, current_time('mysql'));
        clean_post_cache($component_id);

        $mapa[$legacy_id] = (int)$component_id;
        update_option(self::COMPONENT_MAP_OPTION, $mapa);
        $report['componentes_criados'][] = ['legado' => $legacy_id, 'componente' => (int)$component_id, 'titulo' => $legacy->post_title];
        return (int)$component_id;
    }


    /**
     * Marca as propriedades que fazem sentido variar por uso: o texto de cada
     * Text com conteúdo e a URL de cada link. O builder passa a oferecê-las no
     * painel da instância; sem valor definido, a instância herda o componente.
     */
    private static function mark_editable_properties(&$node, &$report)
    {
        $tipo = $node['data']['type'] ?? '';
        $marcar = function (&$n, $chave, $rotulo, $caminho) {
            $atuais = $n['data']['properties']['meta']['component']['editableProperties'] ?? [];
            foreach ($atuais as $e) { if (($e['propertyKey'] ?? '') === $chave) { return false; } }
            $atuais[] = ['propertyKey' => $chave, 'enabled' => true, 'label' => $rotulo, 'controlPath' => $caminho];
            $n['data']['properties']['meta']['component']['editableProperties'] = $atuais;
            return true;
        };
        if (substr($tipo, -14) === '\\ContainerLink' || substr($tipo, -9) === '\\TextLink') {
            if ($marcar($node, 'url_' . $node['id'], 'Link', 'content.content.url')) { $report['campos_editaveis'][] = 'url'; }
        }
        if (substr($tipo, -5) === '\\Text' || substr($tipo, -9) === '\\TextLink') {
            $txt = $node['data']['properties']['content']['content']['text'] ?? '';
            if (is_string($txt) && trim(wp_strip_all_tags($txt)) !== '') {
                if ($marcar($node, 'texto_' . $node['id'], 'Texto', 'content.content.text')) { $report['campos_editaveis'][] = 'texto'; }
            }
        }
        foreach ($node['children'] as &$filho) { self::mark_editable_properties($filho, $report); }
    }


    /**
     * Devolve o SVG de um ícone do classic. O classic injeta um sprite <symbol>
     * na página renderizada — extraímos de lá e transformamos num <svg> autônomo.
     * Cacheado por execução; o HTML da origem já é baixado para fragmentos.
     */
    private function icon_svg($icon_id, $src_id)
    {
        if (isset($this->icon_cache[$icon_id])) { return $this->icon_cache[$icon_id]; }
        if (!isset($this->sprite_html[$src_id])) {
            $url = str_replace(home_url(), $this->origin_base(), get_permalink($src_id) ?: '');
            $this->sprite_html[$src_id] = $url ? $this->fetch($url) : '';
        }
        $html = $this->sprite_html[$src_id];
        if ($html === '' || !preg_match('#<symbol([^>]*)id="' . preg_quote($icon_id, '#') . '"([^>]*)>(.*?)</symbol>#s', $html, $m)) {
            return '';
        }
        $attrs = $m[1] . ' ' . $m[2];
        $viewbox = preg_match('/viewBox="([^"]+)"/', $attrs, $vb) ? $vb[1] : '0 0 24 24';
        $svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="' . esc_attr($viewbox) . '" fill="currentColor">' . trim($m[3]) . '</svg>';
        $this->icon_cache[$icon_id] = $svg;
        return $svg;
    }


    /**
     * Cria (ou reaproveita) o Componente que serve de card para um repetidor.
     * Os filhos do oxy_dynamic_list no classic SÃO o template do item — convertê-los
     * para dentro de um oxygen_block é o que permite usar o loop nativo.
     */
    private function component_for_loop_item($node, $src_id, $selector, &$report)
    {
        if (empty($node['children'])) { return 0; }
        $chave = 'loop:' . $src_id . ':' . $selector;
        $mapa = get_option(self::COMPONENT_MAP_OPTION, []);
        if (!is_array($mapa)) { $mapa = []; }
        if (!empty($mapa[$chave]) && get_post_status($mapa[$chave])) { return (int)$mapa[$chave]; }

        $component_id = wp_insert_post([
            'post_type' => self::COMPONENT_POST_TYPE,
            'post_status' => 'publish',
            'post_title' => 'Item de listagem — ' . $selector,
        ]);
        if (is_wp_error($component_id) || !$component_id) { return 0; }

        $next_id = 100;
        $sub_report = self::empty_report();
        $filhos = $this->convert($node['children'], $next_id, $sub_report, $src_id, '');
        $tree = [
            'root' => ['id' => 1, 'data' => ['type' => 'root', 'properties' => []], 'children' => $filhos],
            '_nextNodeId' => $next_id,
            'status' => 'exported',
        ];
        self::mark_editable_properties($tree['root'], $sub_report);
        update_post_meta($component_id, '_oxygen_data', wp_slash(wp_json_encode(['tree_json_string' => wp_json_encode($tree)])));
        update_post_meta($component_id, self::BACKUP_MARKER, current_time('mysql'));
        clean_post_cache($component_id);

        $mapa[$chave] = (int)$component_id;
        update_option(self::COMPONENT_MAP_OPTION, $mapa);
        $report['componentes_criados'][] = ['loop' => $selector, 'componente' => (int)$component_id];
        return (int)$component_id;
    }


    /**
     * Monta a query-string do loop nativo a partir da configuração do classic.
     * O classic tem dois modos: "manual" (query_args já é uma query-string) e
     * "custom" (campos estruturados: tipos, quantidade, ordenação).
     */
    private static function query_from_legacy($orig)
    {
        $manual = trim((string)($orig['query_args'] ?? ''));
        if ($manual !== '') { return $manual; }
        $partes = [];
        $tipos = $orig['query_post_types'] ?? [];
        if (is_array($tipos) && $tipos) { $partes[] = 'post_type=' . implode(',', array_map('sanitize_key', $tipos)); }
        $qtd = (int)($orig['query_count'] ?? 0);
        if ($qtd) { $partes[] = 'posts_per_page=' . $qtd; }
        $ob = trim((string)($orig['query_order_by'] ?? ''));
        if ($ob !== '') { $partes[] = 'orderby=' . sanitize_key($ob); }
        $o = strtoupper(trim((string)($orig['query_order'] ?? '')));
        if ($o === 'ASC' || $o === 'DESC') { $partes[] = 'order=' . $o; }
        return implode('&', $partes);
    }


    /**
     * Caminho (índices) até o nó que corresponde a um id legado. O id pode ter
     * sobrevivido como atributo OU como classe (uuid no banco de seletores),
     * dependendo de como o elemento foi convertido — aceita os dois.
     */
    private static function caminho_por_id($node, $alvo, $trilha = [], $uuid = null)
    {
        foreach (($node['data']['properties']['settings']['advanced']['attributes'] ?? []) as $a) {
            if (($a['value'] ?? '') === $alvo) { return $trilha; }
        }
        if ($uuid && in_array($uuid, $node['data']['properties']['meta']['classes'] ?? [], true)) { return $trilha; }
        foreach ($node['children'] as $i => $filho) {
            $r = self::caminho_por_id($filho, $alvo, array_merge($trilha, [$i]), $uuid);
            if ($r !== null) { return $r; }
        }
        return null;
    }

    private static function empty_report() {
        return ['count' => 0, 'unmapped' => [], 'pendentes' => [], 'sem_alt' => 0, 'fragmentos' => [], 'frag_falhou' => [], 'reusable_falhou' => [], 'dinamico_falhou' => [], 'novos_seletores' => 0, 'componentes' => [], 'componentes_criados' => [], 'campos_editaveis' => [], 'icone_sem_svg' => [], 'loops' => [], 'refazer_no_builder' => []];
    }

    /* ============================ Pós-fix global ============================ */

    /**
     * Regras 2 e 3 do contrato aplicadas ao banco inteiro + caches. Idempotente.
     * Rodar depois de QUALQUER escrita fora das ferramentas oficiais.
     */
    public function post_fix() {
        $out = ['seletores_props' => 0, 'seletores_buckets' => 0, 'unidades' => 0, 'arvores_status' => 0];
        $raw = (string)get_option(self::SELECTORS_OPTION);
        $list = json_decode($raw, true);
        if (is_array($list)) {
            $fix_units = function (&$arr) use (&$fix_units, &$out) {
                foreach ($arr as &$v) {
                    if (is_array($v)) {
                        if (isset($v['unit']) && is_string($v['unit']) && !in_array($v['unit'], self::$css_units, true)) { $v['unit'] = 'custom'; $out['unidades']++; }
                        $fix_units($v);
                    }
                }
            };
            foreach ($list as &$s) {
                $p = $s['properties'] ?? null;
                if ($p === [] || $p === null) { $s['properties'] = self::inert_properties(); $out['seletores_props']++; continue; }
                if (is_array($p)) {
                    foreach ($s['properties'] as &$bucket) {
                        if ($bucket === []) { $bucket = self::inert_properties()['breakpoint_base']; $out['seletores_buckets']++; }
                    }
                    unset($bucket);
                    $fix_units($s['properties']);
                }
            }
            unset($s);
            if ($out['seletores_props'] + $out['seletores_buckets'] + $out['unidades'] > 0) {
                update_option(self::SELECTORS_OPTION, wp_json_encode($list));
            }
        }
        // status:exported em toda árvore convertida por este plugin que não tenha
        global $wpdb;
        $ids = $wpdb->get_col("SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '" . self::BACKUP_MARKER . "'");
        foreach ($ids as $pid) {
            $d = json_decode((string)get_post_meta($pid, '_oxygen_data', true), true);
            if (!$d || empty($d['tree_json_string'])) { continue; }
            $t = json_decode($d['tree_json_string'], true);
            if (is_array($t) && !isset($t['status'])) {
                $t['status'] = 'exported';
                update_post_meta($pid, '_oxygen_data', wp_slash(wp_json_encode(['tree_json_string' => wp_json_encode($t)])));
                clean_post_cache($pid);
                $out['arvores_status']++;
            }
        }
        // Caches: CSS gerado do Oxygen + object cache (CLI e web têm stores separados!) + LiteSpeed
        if (function_exists('Breakdance\\Render\\clearAllCssCachesAndDeleteCachedFiles')) {
            \Breakdance\Render\clearAllCssCachesAndDeleteCachedFiles();
            $out['css_cache'] = 'limpo';
        }
        wp_cache_flush();
        if ($this->get_settings()['purge_litespeed'] && has_action('litespeed_purge_all')) {
            do_action('litespeed_purge_all');
            $out['litespeed'] = 'purgado';
        }
        return $out;
    }

    /** Seletores utilitários + regras de canvas que toda conversão pressupõe. */
    public function bootstrap_selectors() {
        $raw = (string)get_option(self::SELECTORS_OPTION);
        $list = json_decode($raw, true);
        if (!is_array($list)) { return ['erro' => 'banco de seletores ausente (Oxygen 6 ativo?)']; }
        $have = [];
        foreach ($list as $s) { if (isset($s['name'])) { $have[$s['name']] = true; } }
        $added = [];
        $mk = function ($name, $props, $type = 'class') {
            return ['id' => wp_generate_uuid4(), 'name' => $name, 'type' => $type, 'locked' => false, 'children' => [], 'collection' => 'Conversor', 'properties' => $props];
        };
        $defs = [
            // display:none do legado (elementos escondidos, modais, comments)
            'agd-hidden' => ['breakpoint_base' => ['layout' => ['display' => 'none']]],
            // slot de vídeo de fundo — o CSS real vem do stylesheet universal importado
            'agd-video-slot' => self::inert_properties(),
            'agd-inner-full' => ['breakpoint_base' => ['size' => ['max_width' => ['number' => 100, 'unit' => '%', 'style' => '100%']]]],
            // Wrapper de fragmento encolhe em flex parent e esmaga grids/iframes.
            // (Animação de entrada é nativa do Oxygen 6 — ver entrance_from_aos.)
            '.oxy-shortcode:has(.oxy-dynamic-list)' => ['breakpoint_base' => ['size' => ['width' => ['number' => 100, 'unit' => '%', 'style' => '100%']]]],
            '.oxy-shortcode:has([class*="c-columns"])' => ['breakpoint_base' => ['size' => ['width' => ['number' => 100, 'unit' => '%', 'style' => '100%']]]],
            '.oxy-shortcode:has(iframe)' => ['breakpoint_base' => ['size' => ['width' => ['number' => 100, 'unit' => '%', 'style' => '100%']]]],
        ];
        // O elemento de ícone nativo dimensiona o svg em 1em e aplica max-width:100%;
        // com a classe legada presente, quem manda é o CSS da origem.
        $regra_icone = ".oxy-svg-icon2.ct-fancy-icon{width:auto!important;height:auto!important}"
                     . ".oxy-svg-icon2.ct-fancy-icon>svg{max-width:none!important;max-height:none!important}";
        foreach ($defs as $name => $props) {
            if (isset($have[$name])) { continue; }
            $list[] = $mk($name, $props, strpos($name, ':') !== false || strpos($name, '[') !== false ? 'custom' : 'class');
            $added[] = $name;
        }
        update_option(self::SELECTORS_OPTION, wp_json_encode($list));
        $dir = $this->assets_path();
        if (!is_dir($dir)) { wp_mkdir_p($dir); }
        file_put_contents($dir . '/occ-util.css', $regra_icone . "\n");
        $added[] = 'occ-util.css (dimensionamento de ícone)';
        return ['adicionados' => $added, 'nota' => 'Importe também o CSS universal do site de origem (type scale, core-sss, defaults :where) via insert-stylesheet — ver README.'];
    }

    /* ============================ Abilities: callbacks ============================ */

    const GLOBAL_CSS_OPTION = 'agd_occ_global_css';

    /**
     * Carrega no site os stylesheets globais da origem (escala tipográfica, grid de
     * colunas, defaults de elemento, largura de página). Sem eles a conversão sai
     * com o layout certo mas as proporções erradas — era o passo manual que mais
     * estragava resultado.
     */
    public function enqueue_legacy_css()
    {
        $lista = get_option(self::GLOBAL_CSS_OPTION, []);
        if (!is_array($lista) || !$lista) { return; }
        $base = $this->assets_url() . '/global/';
        foreach ($lista as $i => $arquivo) {
            wp_enqueue_style('agd-legacy-' . $i, $base . $arquivo, [], filemtime($this->assets_path() . '/global/' . $arquivo) ?: null);
        }
    }

    /**
     * Descobre os stylesheets que a origem carrega, baixa, localiza as URLs e
     * registra para enfileirar. Idempotente: re-executar atualiza os arquivos.
     */

    /**
     * Vistoria de um site classic. Roda sem Oxygen 6 — a ideia é instalar só o
     * Agent Connector + este plugin num site da frota e receber o retrato antes
     * de decidir/planejar a migração.
     */
    public function ability_audit_site($input)
    {
        global $wpdb;
        $detalhar = !empty($input['detalhar_paginas']);

        $linhas = $wpdb->get_results("SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_ct_builder_json'");
        if (!$linhas) {
            $sc = (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_ct_builder_shortcodes'");
            return ['erro' => 'nenhuma página do Oxygen classic encontrada', 'shortcodes_legados' => $sc];
        }

        $hist = []; $total = 0; $paginas = []; $dinamicos = []; $reusaveis = [];
        $loops = 0; $icones = 0; $codigo_php = 0;
        foreach ($linhas as $linha) {
            $t = json_decode($linha->meta_value, true);
            if (!$t) { continue; }
            $tipo_post = get_post_type($linha->post_id);
            $conta = 0;
            $pilha = [$t['root'] ?? $t];
            while ($pilha) {
                $n = array_pop($pilha);
                $nome = $n['name'] ?? '';
                if ($nome && $nome !== 'root') {
                    $total++; $conta++;
                    $hist[$nome] = ($hist[$nome] ?? 0) + 1;
                    if ($nome === 'ct_fancy_icon') { $icones++; }
                    if ($nome === 'oxy_dynamic_list') { $loops++; }
                    if ($nome === 'ct_reusable') {
                        $vid = (int) ($n['options']['view_id'] ?? 0);
                        $reusaveis[$vid ?: 0] = ($reusaveis[$vid ?: 0] ?? 0) + 1;
                    }
                    if ($nome === 'ct_code_block') {
                        $php = (string) ($n['options']['original']['code-php'] ?? '');
                        $limpo = trim(preg_replace('#<\?php|\?>|<!--.*?-->|//[^\n]*#s', '', $php));
                        if ($limpo !== '') { $codigo_php++; }
                    }
                }
                $blob = wp_json_encode($n['options'] ?? []);
                if (preg_match_all("/data='([a-z_]+)'/", (string) $blob, $mm)) {
                    foreach ($mm[1] as $d) { $dinamicos[$d] = ($dinamicos[$d] ?? 0) + 1; }
                }
                foreach (($n['children'] ?? []) as $c) { $pilha[] = $c; }
            }
            $paginas[] = ['id' => (int) $linha->post_id, 'tipo' => $tipo_post, 'titulo' => get_the_title($linha->post_id), 'elementos' => $conta];
        }

        // o que o conversor resolve sozinho x o que pede mão
        $automatico = 0; $manual = []; $sem_mapa = [];
        foreach ($hist as $nome => $qtd) {
            if (isset(self::$element_map[$nome])) { $automatico += $qtd; continue; }
            if (isset(self::$composite_hints[$nome])) { $manual[$nome] = ['usos' => $qtd, 'equivalente' => self::$composite_hints[$nome]]; continue; }
            $sem_mapa[$nome] = $qtd;
        }
        $cobertura = $total ? round($automatico / $total * 100, 1) : 0;

        arsort($hist); arsort($dinamicos);
        usort($paginas, function ($a, $b) { return $b['elementos'] - $a['elementos']; });

        $resultado = [
            'site' => home_url(),
            'oxygen_6_instalado' => defined('__BREAKDANCE_PLUGIN_FILE__'),
            'paginas_com_conteudo_classic' => count($paginas),
            'elementos_no_total' => $total,
            'cobertura_automatica_percent' => $cobertura,
            'traduzido_sozinho' => $automatico,
            'pede_mao_no_builder' => $manual,
            'sem_tradutor' => $sem_mapa,
            'pecas_reutilizaveis' => count($reusaveis),
            'listagens_dinamicas' => $loops,
            'icones' => $icones,
            'blocos_com_php' => $codigo_php,
            'campos_dinamicos' => array_slice($dinamicos, 0, 12, true),
            'elementos_mais_usados' => array_slice($hist, 0, 12, true),
        ];
        if ($detalhar) { $resultado['paginas'] = array_slice($paginas, 0, 60); }
        return $resultado;
    }

    public function ability_import_global_css($input)
    {
        $origem = $this->origin_base();
        if (!$origem || $origem === rtrim(home_url(), '/')) {
            return ['erro' => 'defina origin_url em set-settings (site classic de origem)'];
        }
        // cada tipo de página carrega um conjunto diferente de estilos no classic
        // (o CSS de template só aparece numa página daquele tipo), então varremos
        // uma amostra de cada tipo em vez de só a home.
        $urls = ['/'];
        foreach (['post', 'page'] as $tipo) {
            $amostra = get_posts(['post_type' => $tipo, 'posts_per_page' => 1, 'post_status' => 'publish', 'fields' => 'ids']);
            if ($amostra) { $urls[] = str_replace(home_url(), '', get_permalink($amostra[0])); }
        }
        foreach (get_post_types(['public' => true, '_builtin' => false], 'names') as $cpt) {
            $amostra = get_posts(['post_type' => $cpt, 'posts_per_page' => 1, 'post_status' => 'publish', 'fields' => 'ids']);
            if ($amostra) { $urls[] = str_replace(home_url(), '', get_permalink($amostra[0])); }
            $arquivo = get_post_type_archive_link($cpt);
            if ($arquivo) { $urls[] = str_replace(home_url(), '', $arquivo); }
        }
        if (!empty($input['urls_extras']) && is_array($input['urls_extras'])) {
            $urls = array_merge($urls, $input['urls_extras']);
        }
        $urls = array_values(array_unique(array_filter($urls)));

        $tags = [[]];
        $paginas_lidas = [];
        foreach ($urls as $u) {
            $html = $this->fetch($origem . '/' . ltrim($u, '/'));
            if ($html === '') { continue; }
            $paginas_lidas[] = $u;
            if (preg_match_all('#<link[^>]+rel=[\'"]stylesheet[\'"][^>]*>#i', $html, $achados)) {
                $tags[0] = array_merge($tags[0], $achados[0]);
            }
        }
        if (!$tags[0]) { return ['erro' => 'nenhum stylesheet encontrado na origem']; }
        $tags[0] = array_values(array_unique($tags[0]));
        $dir = $this->assets_path() . '/global';
        if (!is_dir($dir)) { wp_mkdir_p($dir); }
        $host_origem = preg_replace('#^https?://#', '', $origem);
        $baixados = []; $ignorados = [];
        foreach ($tags[0] as $tag) {
            if (!preg_match('#href=[\'"]([^\'"]+)#i', $tag, $m)) { continue; }
            $href = html_entity_decode($m[1]);
            if (strpos($href, '//') === 0) { $href = 'https:' . $href; }
            if (strpos($href, 'http') !== 0) { $href = $origem . '/' . ltrim($href, '/'); }
            // só o CSS do próprio site (nada de fontes/CDN de terceiros)
            if (strpos($href, $host_origem) === false) { $ignorados[] = basename(parse_url($href, PHP_URL_PATH)); continue; }
            $css = $this->fetch($href);
            if ($css === '') { $ignorados[] = basename(parse_url($href, PHP_URL_PATH)); continue; }
            $nome = sanitize_file_name(basename(parse_url($href, PHP_URL_PATH)));
            if (substr($nome, -4) !== '.css') { $nome .= '.css'; }
            file_put_contents($dir . '/' . $nome, $this->localize($css));
            $baixados[] = $nome;
        }
        $baixados = array_values(array_unique($baixados));
        update_option(self::GLOBAL_CSS_OPTION, $baixados);
        $this->post_fix();
        return ['importados' => $baixados, 'paginas_lidas' => $paginas_lidas, 'ignorados_de_terceiros' => array_values(array_unique($ignorados)), 'pasta' => $dir];
    }


    public function ability_inventory($input) {
        $args = ['post_type' => 'any', 'post_status' => 'any', 'posts_per_page' => -1, 'fields' => 'ids',
                 'meta_query' => ['relation' => 'OR',
                    ['key' => '_ct_builder_json', 'compare' => 'EXISTS'],
                    ['key' => '_ct_builder_shortcodes', 'compare' => 'EXISTS']]];
        if (!empty($input['post_type'])) { $args['post_type'] = $input['post_type']; }
        $ids = get_posts($args);
        $rows = [];
        foreach ($ids as $id) {
            $p = get_post($id);
            $rows[] = [
                'id' => $id, 'type' => $p->post_type, 'status' => $p->post_status, 'title' => $p->post_title,
                'slug' => $p->post_name,
                'convertido' => (bool)get_post_meta($id, self::BACKUP_MARKER, true),
                'tem_o6' => (bool)get_post_meta($id, '_oxygen_data', true),
            ];
        }
        return ['total' => count($rows), 'posts' => $rows];
    }

    public function ability_inspect($input) {
        $id = (int)$input['post_id'];
        $legacy = $this->get_legacy_tree($id);
        if (!$legacy) { return ['erro' => 'sem dados legados']; }
        $this->load_selmap();
        $hist = []; $classes_pendentes = []; $dinamicos = []; $reusaveis = [];
        $walk = function ($n) use (&$walk, &$hist, &$classes_pendentes, &$dinamicos, &$reusaveis) {
            $name = $n['name'] ?? '';
            if ($name) { $hist[$name] = ($hist[$name] ?? 0) + 1; }
            $opts = (array)($n['options'] ?? []);
            foreach (array_merge([(string)($opts['selector'] ?? '')], (array)($opts['classes'] ?? [])) as $cn) {
                if ($cn && !isset($this->selmap[$cn])) { $classes_pendentes[$cn] = true; }
            }
            $blob = wp_json_encode($opts);
            if (preg_match_all("/data='([a-z_]+)'/", $blob, $mm)) { foreach ($mm[1] as $d) { $dinamicos[$d] = ($dinamicos[$d] ?? 0) + 1; } }
            if ($name === 'ct_reusable') { $reusaveis[] = ['selector' => $opts['selector'] ?? '?', 'view_id' => $opts['view_id'] ?? null]; }
            foreach (($n['children'] ?? []) as $c) { $walk($c); }
        };
        foreach ($legacy['tree']['children'] as $c) { $walk($c); }
        return ['fonte' => $legacy['source'], 'elementos' => $hist, 'dinamicos' => $dinamicos,
                'reusaveis' => $reusaveis, 'classes_sem_seletor' => array_keys($classes_pendentes)];
    }

    public function ability_convert($input) {
        $src = (int)$input['post_id'];
        $dst = !empty($input['dst_post_id']) ? (int)$input['dst_post_id'] : $src;
        $dry = !isset($input['dry_run']) || $input['dry_run'];
        if (!$dry && !($input['overwrite'] ?? false) && get_post_meta($dst, '_oxygen_data', true)) {
            return ['erro' => "destino $dst já tem árvore Oxygen 6 — use overwrite=true"];
        }
        $this->load_selmap();
        $report = self::empty_report();
        $tree = $this->convert_pair($src, $report);
        if (!$tree) { return ['erro' => "post $src sem dados legados"]; }
        $result = ['src' => $src, 'dst' => $dst, 'dry_run' => $dry, 'report' => $report];
        if (!$dry) {
            update_post_meta($dst, '_oxygen_data', wp_slash(wp_json_encode(['tree_json_string' => wp_json_encode($tree)])));
            update_post_meta($dst, self::BACKUP_MARKER, current_time('mysql'));
            clean_post_cache($dst);
            $result['pos_fix'] = $this->post_fix();
            $result['gravado'] = true;
        }
        return $result;
    }

    public function ability_rollback($input) {
        $id = (int)$input['post_id'];
        if (!get_post_meta($id, self::BACKUP_MARKER, true)) { return ['erro' => 'sem marcador de conversão — não vou apagar árvore que não criei']; }
        delete_post_meta($id, '_oxygen_data');
        delete_post_meta($id, self::BACKUP_MARKER);
        clean_post_cache($id);
        return ['revertido' => $id];
    }


    public function ability_upgrade_repeaters($input)
    {
        global $wpdb;
        $dst = (int)$input['post_id'];
        $src_id = (int)$input['source_post_id'];
        $dry = !empty($input['dry_run']);
        $this->load_selmap();

        $leg = json_decode((string)get_post_meta($src_id, '_ct_builder_json', true), true);
        if (!$leg) { return ['erro' => "origem $src_id sem árvore legada"]; }
        $raw = $wpdb->get_var($wpdb->prepare("SELECT meta_value FROM {$wpdb->postmeta} WHERE post_id = %d AND meta_key = %s", $dst, '_oxygen_data'));
        $d = json_decode((string)$raw, true);
        if (!$d || empty($d['tree_json_string'])) { return ['erro' => "destino $dst sem árvore Oxygen 6"]; }
        $tree = json_decode($d['tree_json_string'], true);

        // repetidores do legado, com seu template de item e consulta
        $loops = []; $pais = [];
        $varre = function ($n, $pai) use (&$varre, &$loops, &$pais) {
            $sel = $n['options']['selector'] ?? '';
            if (($n['name'] ?? '') === 'oxy_dynamic_list') {
                $loops[$sel] = $n;
                $pais[$sel] = $pai;
            }
            foreach (($n['children'] ?? []) as $c) { $varre($c, $sel !== '' ? $sel : $pai); }
        };
        $varre($leg['root'] ?? $leg, '');
        if (!$loops) { return ['post_id' => $dst, 'nenhum_repetidor' => true]; }

        $report = self::empty_report();
        $trocados = [];
        $troca = function (&$no) use (&$troca, $loops, $src_id, &$report, &$trocados, $dry) {
            foreach ($no['children'] as &$filho) {
                $id_legado = '';
                foreach (($filho['data']['properties']['settings']['advanced']['attributes'] ?? []) as $a) {
                    if (($a['name'] ?? '') === 'id') { $id_legado = $a['value']; break; }
                }
                $classes = $filho['data']['properties']['meta']['classes'] ?? [];
                $sc = $filho['data']['properties']['content']['shortcode']['full_shortcode'] ?? '';
                $alvo = '';
                foreach ($loops as $sel => $_) {
                    if ($sel === '') { continue; }
                    if ($id_legado === $sel) { $alvo = $sel; break; }
                    if (!empty($this->selmap[$sel]) && in_array($this->selmap[$sel], $classes, true)) { $alvo = $sel; break; }
                    // o fragmento congelado carrega o id do repetidor dentro do HTML
                    if (is_string($sc) && $sc !== '' && strpos($sc, 'id="' . $sel . '"') !== false) { $alvo = $sel; break; }
                }
                if ($alvo !== '') {
                    $orig = $loops[$alvo]['options']['original'] ?? [];
                    if (!$dry) {
                        $item = $this->component_for_loop_item($loops[$alvo], $src_id, $alvo, $report);
                        if ($item) {
                            $props = $filho['data']['properties'];
                            unset($props['content']);
                            $props['content']['repeated_block']['global_block'] = $item;
                            $qargs = self::query_from_legacy($orig);
                            if ($qargs !== '') { $props['content']['query']['query'] = ['active' => 'text', 'text' => $qargs]; }
                            $filho['data']['type'] = 'OxygenElements\\PostsLoop';
                            $filho['data']['properties'] = $props;
                            $filho['children'] = [];
                            $trocados[] = ['seletor' => $alvo, 'componente' => $item, 'query' => $qargs];
                        }
                    } else {
                        $trocados[] = ['seletor' => $alvo, 'query' => self::query_from_legacy($orig)];
                    }
                    continue;
                }
                $troca($filho);
            }
        };
        $troca($tree['root']);

        // repetidor que não sobreviveu à conversão (fragmento falhou): insere o loop
        // dentro do ancestral que existe na árvore, em vez de deixar a seção vazia.
        $jaTrocados = array_column($trocados, 'seletor');
        foreach ($loops as $sel => $noLegado) {
            if ($sel === '' || in_array($sel, $jaTrocados, true)) { continue; }
            $paiId = $pais[$sel] ?? '';
            if ($paiId === '') { continue; }
            $caminho = self::caminho_por_id($tree['root'], $paiId, [], $this->selmap[$paiId] ?? null);
            if ($caminho === null) { continue; }
            if ($dry) { $trocados[] = ['seletor' => $sel, 'inserido_em' => $paiId, 'query' => self::query_from_legacy($noLegado['options']['original'] ?? [])]; continue; }
            $item = $this->component_for_loop_item($noLegado, $src_id, $sel, $report);
            if (!$item) { continue; }
            $ref = &$tree['root'];
            foreach ($caminho as $i) { $ref = &$ref['children'][$i]; }
            $props = ['content' => ['repeated_block' => ['global_block' => $item]]];
            $qargs = self::query_from_legacy($noLegado['options']['original'] ?? []);
            if ($qargs !== '') { $props['content']['query']['query'] = ['active' => 'text', 'text' => $qargs]; }
            $props['settings']['advanced']['attributes'] = [['name' => 'id', 'value' => $sel]];
            $ref['children'][] = [
                'id' => $tree['_nextNodeId']++,
                'data' => ['type' => 'OxygenElements\\PostsLoop', 'properties' => $props],
                'children' => [],
            ];
            unset($ref);
            $trocados[] = ['seletor' => $sel, 'inserido_em' => $paiId, 'componente' => $item, 'query' => $qargs];
        }

        if (!$dry && $trocados) {
            $wpdb->update($wpdb->postmeta, ['meta_value' => wp_json_encode(['tree_json_string' => wp_json_encode($tree)])], ['post_id' => $dst, 'meta_key' => '_oxygen_data']);
            clean_post_cache($dst);
            $this->post_fix();
        }
        return ['post_id' => $dst, 'origem' => $src_id, 'dry_run' => $dry, 'trocados' => $trocados];
    }

    public function ability_post_fix($input) {
        return $this->post_fix();
    }

    public function ability_bootstrap($input) {
        $this->load_selmap();
        return $this->bootstrap_selectors();
    }

    public function ability_set_settings($input) {
        $s = $this->get_settings();
        foreach (['origin_url', 'assets_dir'] as $k) { if (isset($input[$k])) { $s[$k] = (string)$input[$k]; } }
        if (isset($input['purge_litespeed'])) { $s['purge_litespeed'] = (bool)$input['purge_litespeed']; }
        update_option(self::SETTINGS_OPTION, $s);
        return $s;
    }

    public function ability_diagnose($input) {
        global $wpdb;
        $id = (int)$input['post_id'];
        $out = ['post_id' => $id];
        $d = json_decode((string)get_post_meta($id, '_oxygen_data', true), true);
        $out['tem_arvore_o6'] = (bool)($d && !empty($d['tree_json_string']));
        if ($out['tem_arvore_o6']) {
            $t = json_decode($d['tree_json_string'], true);
            $out['status_exported'] = isset($t['status']) && $t['status'] === 'exported';
            $out['nos'] = $this->count_nodes($t['root'] ?? []);
        }
        // Templates "everywhere" vazios sequestram a renderização — e template GANHA da árvore do post em CPTs
        // get_posts não alcança o post type de template (não é publicly_queryable):
        // ler o postmeta direto é o único jeito confiável.
        $tpls = $wpdb->get_col("SELECT DISTINCT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_oxygen_template_settings'");
        $suspeitos = []; $mal_encodados = [];
        foreach ($tpls as $tid) {
            // Formato oficial: JSON DUPLAMENTE encodado (decode 1 -> string, decode 2 -> array).
            // Single-encoded derruba o load_document de TODAS as páginas com 500.
            $raw = (string)get_post_meta($tid, '_oxygen_template_settings', true);
            $lvl1 = json_decode($raw, true);
            $ts = is_string($lvl1) ? json_decode($lvl1, true) : $lvl1;
            if (is_array($lvl1)) { $mal_encodados[] = $tid; }
            if (is_array($ts) && empty($ts['disabled'])) { $suspeitos[] = ['id' => $tid, 'titulo' => get_the_title($tid), 'settings' => $ts]; }
        }
        $out['templates_ativos'] = $suspeitos;
        if ($mal_encodados) { $out['templates_mal_encodados'] = $mal_encodados; $out['acao_encoding'] = 'regravar com wp_json_encode(wp_json_encode($settings))'; }
        // Seletores fora do contrato quebram o builder pra TODAS as páginas
        $list = json_decode((string)get_option(self::SELECTORS_OPTION), true);
        $quebrados = 0;
        if (is_array($list)) {
            foreach ($list as $s) {
                $p = $s['properties'] ?? null;
                if ($p === [] || $p === null) { $quebrados++; }
            }
        }
        $out['seletores_fora_do_contrato'] = $quebrados;
        if ($quebrados) { $out['acao'] = 'rodar oxygen-migrator/post-fix'; }
        return $out;
    }

    private function count_nodes($n) {
        $c = 1;
        foreach (($n['children'] ?? []) as $k) { $c += $this->count_nodes($k); }
        return $c;
    }
}

// A instância é criada pelo bootstrap, e só em ambiente de estúdio.

// Endpoint próprio: opera o conversor sem depender do Agent Connector.
