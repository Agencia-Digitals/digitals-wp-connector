<?php
/**
 * AdSpirit Submissions Log — visualização rápida das últimas N submissões
 * recebidas pelo plugin (CF7 + form qualifier).
 *
 * Substitui o uso de TablePress como "planilha de leads" no fluxo antigo.
 * NÃO é source of truth (o CRM é) — é dashboard local pra:
 *   - Front-end dev validar que o form tá funcionando depois de configurar
 *   - Comercial conferir leads recentes sem trocar de aba
 *   - Debug rápido quando "sumiu lead" sem precisar logar no CRM
 *
 * Storage: wp_options ('adspirit_recent_submissions') — array LIFO, max 50
 * entries, TTL 30 dias (auto-cleanup). Dados sensíveis truncados
 * (pain textarea limitada a 200 chars). Privacidade: visível só pra users
 * com manage_options.
 *
 * Hook: do_action('adspirit_lead_dispatched', $payload, $response_body,
 *   $source) — disparado por CF7 handler + qualifier handler após
 *   dispatch bem-sucedido.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Submissions_Log {
    const OPTION_KEY = 'adspirit_recent_submissions';
    const MAX_ENTRIES = 50;
    const TTL_DAYS = 30;
    const TEXTAREA_TRUNCATE = 200;

    private static $instance = null;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('adspirit_lead_dispatched',
            AdSpirit_Safe_Hook::action(array($this, 'record'), 'submissions_record'), 10, 3);
        add_action('adspirit_connector_render_tab_submissions',
            AdSpirit_Safe_Hook::action(array($this, 'render_tab'), 'submissions_tab'));
        add_action('admin_post_adspirit_clear_submissions',
            AdSpirit_Safe_Hook::action(array($this, 'handle_clear'), 'submissions_clear'));
    }

    public function render_tab() {
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) return;

        $filters = array(
            'source' => isset($_GET['sl_source']) ? sanitize_key((string) $_GET['sl_source']) : '',
            'profile' => isset($_GET['sl_profile']) ? sanitize_key((string) $_GET['sl_profile']) : '',
            'search' => isset($_GET['sl_search']) ? sanitize_text_field((string) $_GET['sl_search']) : '',
        );
        $entries = self::get_entries($filters);
        $summary = self::summary();

        ?>
        <div class="as-card">
            <h2 class="as-section"><span class="as-kicker-inline">Diagnóstico</span>Submissões recentes</h2>
            <p class="as-section-help">
                Últimas <?php echo (int) self::MAX_ENTRIES; ?> submissões recebidas pelo plugin (CF7 + form qualifier).
                Substitui o uso do TablePress como "planilha local de leads". <strong>Source of truth é o CRM</strong> —
                cada linha tem link pro lead correspondente.
                Retenção: <?php echo (int) self::TTL_DAYS; ?> dias.
            </p>

            <div style="display:flex; gap:16px; flex-wrap:wrap; margin:18px 0; font-size:13px;">
                <div><strong><?php echo (int) $summary['total']; ?></strong> total</div>
                <div><strong><?php echo (int) $summary['cf7']; ?></strong> via CF7</div>
                <div><strong><?php echo (int) $summary['qualifier']; ?></strong> via Qualifier</div>
                <div style="opacity:0.6">|</div>
                <div>Perfil A: <strong><?php echo (int) $summary['a']; ?></strong></div>
                <div>B: <strong><?php echo (int) $summary['b']; ?></strong></div>
                <div>C: <strong><?php echo (int) $summary['c']; ?></strong></div>
                <div>D: <strong><?php echo (int) $summary['d']; ?></strong></div>
                <?php if ($summary['no_profile'] > 0) : ?>
                <div style="opacity:0.7">sem perfil: <strong><?php echo (int) $summary['no_profile']; ?></strong></div>
                <?php endif; ?>
            </div>

            <form method="get" action="" style="margin-bottom:16px;">
                <input type="hidden" name="page" value="<?php echo esc_attr(AdSpirit_Menu::PAGE_SLUG); ?>">
                <input type="hidden" name="tab" value="submissions">
                <input type="search" name="sl_search" value="<?php echo esc_attr($filters['search']); ?>" placeholder="Buscar por nome/email/telefone/empresa" style="min-width:260px;">
                <select name="sl_source">
                    <option value="">Todas origens</option>
                    <option value="cf7" <?php selected($filters['source'], 'cf7'); ?>>Contact Form 7</option>
                    <option value="qualifier" <?php selected($filters['source'], 'qualifier'); ?>>Qualifier (completo)</option>
                    <option value="qualifier_partial" <?php selected($filters['source'], 'qualifier_partial'); ?>>Qualifier (parcial)</option>
                </select>
                <select name="sl_profile">
                    <option value="">Todos perfis</option>
                    <option value="A" <?php selected($filters['profile'], 'A'); ?>>A</option>
                    <option value="B" <?php selected($filters['profile'], 'B'); ?>>B</option>
                    <option value="C" <?php selected($filters['profile'], 'C'); ?>>C</option>
                    <option value="D" <?php selected($filters['profile'], 'D'); ?>>D</option>
                </select>
                <button type="submit" class="button">Filtrar</button>
                <?php if ($filters['source'] || $filters['profile'] || $filters['search']) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=submissions')); ?>" class="button-link">Limpar filtros</a>
                <?php endif; ?>
            </form>

            <?php if (empty($entries)) : ?>
                <div class="as-notice" style="margin-top:12px;">
                    <p>
                        <?php if ($summary['total'] === 0) : ?>
                            Nenhuma submissão registrada ainda. Submissões aparecem aqui automaticamente
                            quando o CF7 ou o <code>[adspirit_form_qualifier]</code> são preenchidos no site.
                        <?php else : ?>
                            Nenhuma submissão bate com os filtros atuais.
                        <?php endif; ?>
                    </p>
                </div>
            <?php else : ?>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th style="width:130px;">Quando</th>
                            <th style="width:110px;">Origem</th>
                            <th>Contato</th>
                            <th style="width:90px;">Perfil</th>
                            <th>Empresa</th>
                            <th style="width:130px;">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($entries as $e) :
                        $lead_url = self::lead_url($e['lead_id'] ?? '');
                        $when = isset($e['ts']) ? human_time_diff((int) $e['ts'], time()) . ' atrás' : '—';
                        $source_label = array(
                            'cf7' => 'CF7',
                            'qualifier' => 'Qualifier',
                            'qualifier_partial' => 'Qualifier (parcial)',
                        );
                        $src = $source_label[$e['source'] ?? 'cf7'] ?? ($e['source'] ?? '?');
                        $profile = strtoupper((string) ($e['profile'] ?? ''));
                    ?>
                        <tr>
                            <td title="<?php echo esc_attr(date_i18n('Y-m-d H:i', (int) ($e['ts'] ?? 0))); ?>"><?php echo esc_html($when); ?></td>
                            <td><span class="as-badge"><?php echo esc_html($src); ?></span></td>
                            <td>
                                <strong><?php echo esc_html($e['name'] ?? '—'); ?></strong><br>
                                <small><?php echo esc_html($e['email'] ?? ''); ?></small><br>
                                <small><?php echo esc_html($e['phone'] ?? ''); ?></small>
                            </td>
                            <td>
                                <?php if ($profile) : ?>
                                    <span class="as-badge as-badge-profile-<?php echo esc_attr(strtolower($profile)); ?>"><?php echo esc_html($profile); ?></span>
                                <?php else : ?>
                                    <span style="opacity:0.5;">—</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo esc_html($e['company'] ?? ''); ?></td>
                            <td>
                                <?php if ($lead_url) : ?>
                                    <a href="<?php echo esc_url($lead_url); ?>" target="_blank" rel="noopener" class="button button-small">Ver no CRM ↗</a>
                                <?php else : ?>
                                    <span style="opacity:0.4;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>

                <p style="margin-top:18px;">
                    <a href="<?php echo esc_url(admin_url('admin-post.php')); ?>?action=adspirit_clear_submissions&_wpnonce=<?php echo esc_attr(wp_create_nonce('adspirit_clear_submissions')); ?>"
                       class="button-link delete"
                       onclick="return confirm('Limpar todas as submissões do log local? (não afeta dados no CRM)');">Limpar log local</a>
                </p>
            <?php endif; ?>
        </div>

        <style>
            .as-badge { display:inline-block; padding:2px 8px; border-radius:3px; background:#e5e5e5; color:#333; font-size:11px; font-weight:600; text-transform:uppercase; letter-spacing:0.04em; }
            .as-badge-profile-a { background:#d4edda; color:#155724; }
            .as-badge-profile-b { background:#cfe2ff; color:#084298; }
            .as-badge-profile-c { background:#fff3cd; color:#856404; }
            .as-badge-profile-d { background:#f8d7da; color:#721c24; }
        </style>
        <?php
    }

    public function handle_clear() {
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) wp_die('forbidden', 403);
        check_admin_referer('adspirit_clear_submissions');
        self::clear();
        wp_safe_redirect(add_query_arg(
            array('page' => AdSpirit_Menu::PAGE_SLUG, 'tab' => 'submissions', 'cleared' => '1'),
            admin_url('admin.php')
        ));
        exit;
    }

    /**
     * Hook handler. Recebe payload + response_body do CRM + source
     * ('cf7' ou 'qualifier'). Grava entry truncada em wp_options.
     */
    public function record($payload, $response_body, $source = 'cf7') {
        if (!is_array($payload)) return;

        $entry = array(
            'ts' => time(),
            'source' => sanitize_key((string) $source),
            'form_id' => isset($payload['_form_id']) ? (string) $payload['_form_id'] : '',
            'name' => isset($payload['your-name']) ? self::trunc((string) $payload['your-name'], 100) : '',
            'email' => isset($payload['your-email']) ? self::trunc((string) $payload['your-email'], 100) : '',
            'phone' => isset($payload['Telefone']) ? self::trunc((string) $payload['Telefone'], 30) : '',
            'company' => isset($payload['empresa']) ? self::trunc((string) $payload['empresa'], 100) : '',
            'pain' => isset($payload['pain']) ? self::trunc((string) $payload['pain'], self::TEXTAREA_TRUNCATE) : '',
            'profile' => null,
            'deal_id' => null,
            'lead_id' => null,
            'matched_rule_id' => null,
        );

        if (is_array($response_body)) {
            $entry['profile'] = isset($response_body['profile']) ? (string) $response_body['profile'] : null;
            $entry['matched_rule_id'] = isset($response_body['matched_rule_id']) ? (string) $response_body['matched_rule_id'] : null;
            $result = isset($response_body['result']) && is_array($response_body['result']) ? $response_body['result'] : array();
            $entry['deal_id'] = isset($result['dealId']) ? (string) $result['dealId'] : null;
            $entry['lead_id'] = isset($result['leadId']) ? (string) $result['leadId'] : null;
        }

        $all = (array) get_option(self::OPTION_KEY, array());
        array_unshift($all, $entry);
        // Cap por tamanho
        if (count($all) > self::MAX_ENTRIES) $all = array_slice($all, 0, self::MAX_ENTRIES);
        // Cap por TTL
        $cutoff = time() - (self::TTL_DAYS * DAY_IN_SECONDS);
        $all = array_values(array_filter($all, function ($e) use ($cutoff) {
            return isset($e['ts']) && (int) $e['ts'] >= $cutoff;
        }));

        update_option(self::OPTION_KEY, $all, false);
    }

    /** Retorna entries pra render (LIFO já aplicado no record). */
    public static function get_entries($filters = array()) {
        $all = (array) get_option(self::OPTION_KEY, array());
        if (empty($filters)) return $all;

        return array_values(array_filter($all, function ($e) use ($filters) {
            if (!empty($filters['source']) && ($e['source'] ?? '') !== $filters['source']) return false;
            if (!empty($filters['profile']) && ($e['profile'] ?? '') !== $filters['profile']) return false;
            if (!empty($filters['search'])) {
                $needle = strtolower((string) $filters['search']);
                $haystack = strtolower(($e['name'] ?? '') . ' ' . ($e['email'] ?? '') . ' ' . ($e['phone'] ?? '') . ' ' . ($e['company'] ?? ''));
                if (strpos($haystack, $needle) === false) return false;
            }
            return true;
        }));
    }

    /** Conta por source e por profile (pra header/stats). */
    public static function summary() {
        $all = (array) get_option(self::OPTION_KEY, array());
        $total = count($all);
        $cf7 = 0; $qualifier = 0;
        $a = 0; $b = 0; $c = 0; $d = 0; $no_profile = 0;
        foreach ($all as $e) {
            if (($e['source'] ?? '') === 'cf7') $cf7++;
            elseif (($e['source'] ?? '') === 'qualifier') $qualifier++;
            $p = strtoupper((string) ($e['profile'] ?? ''));
            if ($p === 'A') $a++;
            elseif ($p === 'B') $b++;
            elseif ($p === 'C') $c++;
            elseif ($p === 'D') $d++;
            else $no_profile++;
        }
        return compact('total', 'cf7', 'qualifier', 'a', 'b', 'c', 'd', 'no_profile');
    }

    private static function trunc($str, $max) {
        $str = trim($str);
        if (mb_strlen($str) <= $max) return $str;
        return mb_substr($str, 0, $max - 1) . '…';
    }

    /** Link pro lead no CRM se tiver lead_id + endpoint_url. */
    public static function lead_url($lead_id) {
        if (empty($lead_id)) return '';
        $core = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();
        $endpoint = isset($core['endpoint_url']) ? rtrim((string) $core['endpoint_url'], '/') : '';
        if ($endpoint === '') return '';
        return $endpoint . '/leads/' . rawurlencode($lead_id);
    }

    public static function clear() {
        delete_option(self::OPTION_KEY);
    }
}
