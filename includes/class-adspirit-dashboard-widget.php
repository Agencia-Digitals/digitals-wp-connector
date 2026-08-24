<?php
/**
 * AdSpirit Connector — widget de leads no dashboard do WP.
 *
 * Padrão MonsterInsights/Site Kit: quem vive no wp-admin não abre outra
 * ferramenta. O widget dá presença diária ao AdSpirit — leads do mês, por
 * origem, pendentes — com link direto pro AdSpirit. Read-only, fail-soft, e só
 * aparece pra admin com o plugin conectado.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Dashboard_Widget {

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action(
            'wp_dashboard_setup',
            AdSpirit_Safe_Hook::action(array($this, 'register'), 'dashboard_widget')
        );
    }

    public function register() {
        if (!class_exists('AdSpirit_Menu') || !current_user_can(AdSpirit_Menu::CAPABILITY)) return;
        if (!class_exists('AdSpirit_Settings')) return;
        $core = AdSpirit_Settings::get_core();
        // Sem conexão = sem dado pra mostrar; não ocupa o dashboard à toa.
        if (empty($core['brand_slug']) || empty($core['secret'])) return;
        wp_add_dashboard_widget('adspirit_leads_widget', 'AdSpirit — Leads', array($this, 'render'));
    }

    /**
     * Resumo de mídia da marca, vindo do AdSpirit.
     *
     * O widget sabia só o que passou por este site. Investimento e cliques
     * moram no AdSpirit — trazer pra cá é o que faz o painel do WordPress
     * responder "e aí, como foi o mês" sem sair daqui.
     *
     * Cache de 1h e fail-soft: se o AdSpirit não responder, o widget mostra
     * a parte de leads normalmente. Nunca deixa o painel quebrado por causa
     * de um número extra.
     */
    private function resumo_midia() {
        $cache = get_transient('adspirit_widget_midia');
        if (is_array($cache)) return $cache;
        if (!class_exists('AdSpirit_Settings')) return null;
        $core = AdSpirit_Settings::get_core();
        if (empty($core['endpoint_url']) || empty($core['brand_slug']) || empty($core['secret'])) return null;

        $resp = wp_remote_get(
            rtrim((string) $core['endpoint_url'], '/') . '/api/wp/resumo?brand_slug='
                . rawurlencode((string) $core['brand_slug']),
            array('timeout' => 6, 'headers' => array('x-cf7-secret' => (string) $core['secret']))
        );
        if (is_wp_error($resp) || (int) wp_remote_retrieve_response_code($resp) !== 200) return null;
        $d = json_decode((string) wp_remote_retrieve_body($resp), true);
        if (!is_array($d) || empty($d['ok'])) return null;
        set_transient('adspirit_widget_midia', $d, HOUR_IN_SECONDS);
        return $d;
    }

    /** Centavos → "R$ 1.234" (sem centavos: é resumo, não extrato). */
    private static function reais($cents) {
        return 'R$ ' . number_format(((int) $cents) / 100, 0, ',', '.');
    }

    public function render() {
        $data = AdSpirit_Safe_Hook::try_run(array($this, 'collect'), null, 'dashboard_widget_collect');
        $core = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();
        $crm_url = !empty($core['endpoint_url']) ? rtrim((string) $core['endpoint_url'], '/') . '/leads' : '';
        $submissions_url = class_exists('AdSpirit_Menu')
            ? admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=submissions')
            : '';

        if (!is_array($data)) {
            echo '<p>Ainda sem leads. Assim que um formulário do site for enviado, o resumo aparece aqui.</p>';
            if ($crm_url) {
                echo '<p><a class="button button-primary" target="_blank" rel="noopener noreferrer" href="' . esc_url($crm_url) . '">Abrir o AdSpirit</a></p>';
            }
            return;
        }

        $delta = null;
        if ($data['prev_month'] > 0) {
            $delta = (int) round((($data['month'] - $data['prev_month']) / $data['prev_month']) * 100);
        }
        ?>
        <style>
        .as-w-midia { margin: 12px 0 14px; padding: 12px 0 0; border-top: 1px solid #f0f0f1; }
        .as-w-midia-linha { display: flex; gap: 26px; flex-wrap: wrap; margin-bottom: 10px; }
        .as-w-num { font-size: 19px; font-weight: 600; line-height: 1.1; display: block; color: #1d2327; }
        .as-w-rot { font-size: 11px; color: #646970; }
        .as-w-plataformas { margin: 0; }
        .as-w-plataformas li { display: flex; align-items: baseline; gap: 8px; margin: 3px 0; }
        .as-w-plataformas li span { flex: 1; }
        .as-w-plataformas li small { color: #646970; }
        </style>
        <div style="display:flex; gap:18px; align-items:baseline; flex-wrap:wrap; margin-bottom:10px;">
            <div>
                <span style="font-size:28px; font-weight:700; line-height:1;"><?php echo (int) $data['month']; ?></span>
                <span style="color:#646970;"> leads este mês</span>
            </div>
            <?php if ($delta !== null) : ?>
                <span style="font-weight:600; color:<?php echo $delta >= 0 ? '#00844b' : '#b32d2e'; ?>;">
                    <?php echo ($delta >= 0 ? '+' : '') . (int) $delta; ?>% vs mês anterior
                </span>
            <?php endif; ?>
        </div>

        <?php
        $midia = $this->resumo_midia();
        if (is_array($midia) && (int) ($midia['investimento_cents'] ?? 0) > 0) :
            $cpl = $midia['custo_por_lead_cents'] ?? null;
        ?>
            <div class="as-w-midia">
                <div class="as-w-midia-linha">
                    <div>
                        <span class="as-w-num"><?php echo esc_html(self::reais($midia['investimento_cents'])); ?></span>
                        <span class="as-w-rot">investidos em 30 dias</span>
                    </div>
                    <div>
                        <span class="as-w-num"><?php echo $cpl !== null ? esc_html(self::reais($cpl)) : '—'; ?></span>
                        <span class="as-w-rot">por lead</span>
                    </div>
                </div>
                <?php if (!empty($midia['plataformas'])) : ?>
                    <ul class="as-w-plataformas">
                        <?php foreach ($midia['plataformas'] as $p) : ?>
                            <li>
                                <span><?php echo esc_html($p['plataforma']); ?></span>
                                <strong><?php echo esc_html(self::reais($p['investimento_cents'])); ?></strong>
                                <small><?php echo esc_html(number_format((int) $p['cliques'], 0, ',', '.')); ?> cliques</small>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <?php if (!empty($data['by_source'])) : ?>
            <p style="margin:0 0 4px; color:#646970; text-transform:uppercase; font-size:11px; letter-spacing:.04em;">Por origem</p>
            <ul style="margin:0 0 12px;">
                <?php foreach ($data['by_source'] as $row) :
                    // Mesma tradução da aba Submissões — o dicionário próprio que
                    // existia aqui dava dois nomes ao mesmo formulário.
                    $label = (string) $row['source'];
                    if (class_exists('AdSpirit_Payload_View')) {
                        $ident = AdSpirit_Payload_View::form_identity($label, (string) ($row['form_id'] ?? ''));
                        $label = $ident['form'] !== '' ? $ident['form'] : $ident['engine'];
                    } ?>
                    <li style="display:flex; justify-content:space-between; margin:2px 0;">
                        <span><?php echo esc_html($label); ?></span>
                        <strong><?php echo (int) $row['n']; ?></strong>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <?php if ($data['unsent'] > 0) : ?>
            <p style="background:#fcf0f1; border-left:4px solid #b32d2e; padding:6px 10px; margin:0 0 12px;">
                <strong><?php echo (int) $data['unsent']; ?></strong> lead(s) ainda não entregue(s) ao AdSpirit.
                <?php if ($submissions_url) : ?>
                    <a href="<?php echo esc_url($submissions_url); ?>">Ver e reenviar</a>
                <?php endif; ?>
            </p>
        <?php endif; ?>

        <?php if (!empty($data['recent'])) : ?>
            <p style="margin:0 0 4px; color:#646970; text-transform:uppercase; font-size:11px; letter-spacing:.04em;">Últimos leads</p>
            <ul style="margin:0 0 12px;">
                <?php foreach ($data['recent'] as $r) :
                    $ts = !empty($r['created_at']) ? strtotime((string) $r['created_at'] . ' UTC') : 0;
                    $who = $r['name'] !== '' ? $r['name'] : ($r['email'] !== '' ? $r['email'] : '—'); ?>
                    <li style="display:flex; justify-content:space-between; gap:10px; margin:2px 0;">
                        <span style="overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"><?php echo esc_html($who); ?></span>
                        <span style="color:#646970; flex-shrink:0;"><?php echo $ts ? esc_html(human_time_diff($ts, time())) : '—'; ?></span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>

        <p style="display:flex; gap:8px; margin:0;">
            <?php if ($crm_url) : ?>
                <a class="button button-primary" target="_blank" rel="noopener noreferrer" href="<?php echo esc_url($crm_url); ?>">Abrir o AdSpirit</a>
            <?php endif; ?>
            <?php if ($submissions_url) : ?>
                <a class="button" href="<?php echo esc_url($submissions_url); ?>">Leads enviados</a>
            <?php endif; ?>
        </p>
        <?php
    }

    /**
     * Coleta os números. Lead Store quando disponível; sem tabela, cai pro
     * log legado (últimos leads apenas). Retorna null se não há nada.
     * Fronteiras de mês no fuso DO SITE, convertidas pra UTC (created_at é UTC).
     */
    public function collect() {
        if (class_exists('AdSpirit_Lead_Store') && AdSpirit_Lead_Store::available()) {
            global $wpdb;
            $table = AdSpirit_Lead_Store::table_name();

            $local_now = current_time('timestamp');
            $month_start = get_gmt_from_date(date('Y-m-01 00:00:00', $local_now), 'Y-m-d H:i:s');
            $prev_start  = get_gmt_from_date(date('Y-m-01 00:00:00', strtotime('first day of last month', $local_now)), 'Y-m-d H:i:s');

            // Parciais fora das contagens: o lead completo tem linha própria.
            $month = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND source <> %s",
                $month_start, 'qualifier_partial'
            ));
            $prev = (int) $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s AND created_at < %s AND source <> %s",
                $prev_start, $month_start, 'qualifier_partial'
            ));
            $by_source = $wpdb->get_results($wpdb->prepare(
                "SELECT source, COUNT(*) AS n FROM {$table}
                 WHERE created_at >= %s AND source <> %s
                 GROUP BY source ORDER BY n DESC LIMIT 4",
                $month_start, 'qualifier_partial'
            ), ARRAY_A);
            $recent = $wpdb->get_results($wpdb->prepare(
                "SELECT name, email, created_at FROM {$table}
                 WHERE source <> %s ORDER BY id DESC LIMIT 5",
                'qualifier_partial'
            ), ARRAY_A);

            if ($month === 0 && empty($recent)) return null;
            return array(
                'month'      => $month,
                'prev_month' => $prev,
                'by_source'  => is_array($by_source) ? $by_source : array(),
                'unsent'     => AdSpirit_Lead_Store::count_unsent(),
                'recent'     => is_array($recent) ? array_map(function ($r) {
                    return array(
                        'name'       => (string) ($r['name'] ?? ''),
                        'email'      => (string) ($r['email'] ?? ''),
                        'created_at' => (string) ($r['created_at'] ?? ''),
                    );
                }, $recent) : array(),
            );
        }

        // Fallback: log legado em options (50 itens / 30 dias) — só recentes.
        $legacy = get_option('adspirit_recent_submissions', array());
        if (!is_array($legacy) || empty($legacy)) return null;
        $recent = array();
        foreach (array_slice($legacy, 0, 5) as $item) {
            if (!is_array($item)) continue;
            $recent[] = array(
                'name'       => (string) ($item['name'] ?? ''),
                'email'      => (string) ($item['email'] ?? ''),
                'created_at' => isset($item['at']) ? gmdate('Y-m-d H:i:s', (int) strtotime((string) $item['at'])) : '',
            );
        }
        if (empty($recent)) return null;
        return array('month' => count($legacy), 'prev_month' => 0, 'by_source' => array(), 'unsent' => 0, 'recent' => $recent);
    }
}
