<?php
/**
 * AdSpirit Connector — Lead Store (persistência durável de submissões).
 *
 * Fase 1 da produtização. Garante que TODO lead é gravado localmente ANTES
 * de qualquer chamada externa. Sequência por design:
 *   validar → record_pending() → dispatch externo → mark() por integração
 *
 * INTEGRIDADE NUNCA BLOQUEIA O ENVIO: se o insert falhar (tabela ausente,
 * erro de DB), record_pending() retorna false e o caller segue o fluxo
 * normal — o lead ainda vai pro CRM/fanout e pro log legado (fallback).
 *
 * Storage: tabela própria {prefix}adspirit_submissions (dbDelta). Diferente
 * do submissions-log (wp_options, volátil, capado em 50 / TTL 30d) — esta é
 * append-only, sem TTL, com payload + status por integração pra reprocessar.
 *
 * REENVIO: reusa o submission_id ORIGINAL no header x-cf7-submission-id, então
 * o dedup do CRM promove/atualiza o lead em vez de criar duplicado. O reenvio
 * fala SÓ com o CRM (não re-dispara fanout) pra não duplicar linha no Sheets.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Lead_Store {
    const TABLE            = 'adspirit_submissions';
    const OPTION_DB_VERSION = 'adspirit_connector_submissions_db_version';
    const DB_VERSION        = '1';

    private static $instance = null;
    private static $available = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        // Auto-cria a tabela em update via GitHub Releases (o activation hook
        // NÃO roda em update). Guard barato: só roda dbDelta se a versão mudou.
        $this->maybe_install();

        add_action(
            'admin_post_adspirit_resend_submission',
            AdSpirit_Safe_Hook::action(array($this, 'handle_resend'), 'lead_resend')
        );
    }

    // ─────────────────────────────────────────────────────────
    // Schema / instalação
    // ─────────────────────────────────────────────────────────

    public static function table_name() {
        global $wpdb;
        return $wpdb->prefix . self::TABLE;
    }

    /** Cria/atualiza a tabela. Idempotente (dbDelta). Marca disponível ao fim. */
    public static function install() {
        return AdSpirit_Safe_Hook::try_run(function () {
            global $wpdb;
            $table = self::table_name();
            $charset_collate = $wpdb->get_charset_collate();

            $sql = "CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  submission_id varchar(191) NOT NULL DEFAULT '',
  source varchar(40) NOT NULL DEFAULT '',
  form_id varchar(100) NOT NULL DEFAULT '',
  status varchar(20) NOT NULL DEFAULT 'pending',
  name varchar(191) NOT NULL DEFAULT '',
  email varchar(191) NOT NULL DEFAULT '',
  phone varchar(60) NOT NULL DEFAULT '',
  company varchar(191) NOT NULL DEFAULT '',
  profile varchar(10) NOT NULL DEFAULT '',
  lead_id varchar(100) NOT NULL DEFAULT '',
  payload longtext NULL,
  integrations longtext NULL,
  created_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  updated_at datetime NOT NULL DEFAULT '1970-01-01 00:00:00',
  PRIMARY KEY  (id),
  KEY submission_id (submission_id),
  KEY status (status),
  KEY created_at (created_at)
) {$charset_collate};";

            require_once ABSPATH . 'wp-admin/includes/upgrade.php';
            dbDelta($sql);

            // Confirma que a tabela existe de fato antes de marcar disponível.
            $exists = $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
            if ($exists) {
                update_option(self::OPTION_DB_VERSION, self::DB_VERSION, true);
                self::$available = true;
                return true;
            }
            error_log('[AdSpirit Connector] Lead_Store: tabela não criou — usando fallback (log legado).');
            self::$available = false;
            return false;
        }, false, 'lead_store_install');
    }

    /** Roda install() só se a versão de schema mudou. Barato no caminho quente. */
    public function maybe_install() {
        if (get_option(self::OPTION_DB_VERSION) === self::DB_VERSION) return;
        self::install();
    }

    /**
     * Tabela disponível pra uso? Cacheado por request. Sem SHOW TABLES no
     * caminho quente — confia na option setada por install(). Se um insert
     * falhar em runtime (tabela sumiu), available() é rebaixado pra false.
     */
    public static function available() {
        if (self::$available !== null) return self::$available;
        self::$available = (get_option(self::OPTION_DB_VERSION) === self::DB_VERSION);
        return self::$available;
    }

    // ─────────────────────────────────────────────────────────
    // Gravação (record antes de qualquer chamada externa)
    // ─────────────────────────────────────────────────────────

    /**
     * Grava a submissão como 'pending' ANTES do dispatch. Retorna o row id,
     * ou FALSE se a gravação falhou — nesse caso o caller NÃO deve abortar
     * o envio (integridade não bloqueia o lead; cai no fallback do log legado).
     *
     * @param string $submission_id  ID idempotente (mesmo do header x-cf7-submission-id)
     * @param array  $payload        payload completo enviado ao CRM
     * @param string $source         cf7 | qualifier | qualifier_partial | gravity | ...
     * @param string $form_id
     * @return int|false
     */
    public static function record_pending($submission_id, array $payload, $source, $form_id = '') {
        if (!self::available()) return false;

        return AdSpirit_Safe_Hook::try_run(function () use ($submission_id, $payload, $source, $form_id) {
            global $wpdb;
            $contact = self::extract_contact($payload);
            $now = current_time('mysql');

            $ok = $wpdb->insert(self::table_name(), array(
                'submission_id' => substr((string) $submission_id, 0, 191),
                'source'        => substr((string) $source, 0, 40),
                'form_id'       => substr((string) $form_id, 0, 100),
                'status'        => 'pending',
                'name'          => $contact['name'],
                'email'         => $contact['email'],
                'phone'         => $contact['phone'],
                'company'       => $contact['company'],
                'profile'       => '',
                'lead_id'       => '',
                'payload'       => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'integrations'  => wp_json_encode(new stdClass()),
                'created_at'    => $now,
                'updated_at'    => $now,
            ));

            if ($ok === false) {
                // Tabela pode ter sumido — rebaixa pra fallback nesse request.
                self::$available = false;
                error_log('[AdSpirit Connector] Lead_Store insert falhou: ' . $wpdb->last_error);
                return false;
            }
            return (int) $wpdb->insert_id;
        }, false, 'lead_store_record');
    }

    /**
     * Marca o resultado de uma integração para a submissão. Recalcula o
     * status geral quando a integração for 'crm'. No-op se indisponível.
     *
     * @param string      $submission_id
     * @param string      $key       crm | fanout | capi | ga4
     * @param string      $status    sent | failed | dispatched
     * @param int         $http_code
     * @param string|null $error
     * @param array|null  $crm_body  resposta do CRM (extrai profile/lead_id)
     */
    public static function mark($submission_id, $key, $status, $http_code = 0, $error = null, $crm_body = null) {
        if (!self::available()) return;

        AdSpirit_Safe_Hook::try_run(function () use ($submission_id, $key, $status, $http_code, $error, $crm_body) {
            global $wpdb;
            $table = self::table_name();

            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, integrations FROM {$table} WHERE submission_id = %s ORDER BY id DESC LIMIT 1",
                (string) $submission_id
            ), ARRAY_A);
            if (!$row) return;

            $integrations = json_decode((string) $row['integrations'], true);
            if (!is_array($integrations)) $integrations = array();
            $integrations[(string) $key] = array(
                'status'    => (string) $status,
                'http_code' => (int) $http_code,
                'error'     => $error !== null ? substr((string) $error, 0, 300) : null,
                'at'        => current_time('mysql'),
            );

            $update = array(
                'integrations' => wp_json_encode($integrations),
                'updated_at'   => current_time('mysql'),
            );

            // O status geral é guiado pelo CRM (destino primário do lead).
            if ($key === 'crm') {
                $update['status'] = ($status === 'failed') ? 'failed' : 'sent';
            }
            // Extrai profile/lead_id da resposta do CRM (paths blocking).
            if (is_array($crm_body)) {
                $profile = isset($crm_body['profile']) ? (string) $crm_body['profile'] : '';
                $result  = isset($crm_body['result']) && is_array($crm_body['result']) ? $crm_body['result'] : array();
                $lead_id = isset($result['leadId']) ? (string) $result['leadId'] : '';
                if ($profile !== '') $update['profile'] = substr($profile, 0, 10);
                if ($lead_id !== '') $update['lead_id'] = substr($lead_id, 0, 100);
            }

            $wpdb->update($table, $update, array('id' => (int) $row['id']));
        }, null, 'lead_store_mark');
    }

    private static function extract_contact(array $payload) {
        $name = '';
        foreach (array('your-name', 'name', 'nome') as $k) {
            if (!empty($payload[$k])) { $name = (string) $payload[$k]; break; }
        }
        $email = '';
        foreach (array('your-email', 'email') as $k) {
            if (!empty($payload[$k])) { $email = (string) $payload[$k]; break; }
        }
        $phone = '';
        foreach (array('Telefone', 'telefone', 'phone') as $k) {
            if (!empty($payload[$k])) { $phone = (string) $payload[$k]; break; }
        }
        $company = '';
        foreach (array('empresa', 'company') as $k) {
            if (!empty($payload[$k])) { $company = (string) $payload[$k]; break; }
        }
        return array(
            'name'    => substr($name, 0, 191),
            'email'   => substr($email, 0, 191),
            'phone'   => substr($phone, 0, 60),
            'company' => substr($company, 0, 191),
        );
    }

    // ─────────────────────────────────────────────────────────
    // Query (pra aba Submissões)
    // ─────────────────────────────────────────────────────────

    public static function query($limit = 100, array $filters = array()) {
        if (!self::available()) return array();
        return AdSpirit_Safe_Hook::try_run(function () use ($limit, $filters) {
            global $wpdb;
            $table = self::table_name();
            $where = array('1=1');
            $args  = array();

            if (!empty($filters['source'])) { $where[] = 'source = %s'; $args[] = (string) $filters['source']; }
            if (!empty($filters['status'])) { $where[] = 'status = %s'; $args[] = (string) $filters['status']; }
            if (!empty($filters['search'])) {
                $like = '%' . $wpdb->esc_like((string) $filters['search']) . '%';
                $where[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s OR company LIKE %s)';
                array_push($args, $like, $like, $like, $like);
            }
            $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d';
            $args[] = (int) $limit;
            $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
            return is_array($rows) ? $rows : array();
        }, array(), 'lead_store_query');
    }

    public static function get($id) {
        if (!self::available()) return null;
        return AdSpirit_Safe_Hook::try_run(function () use ($id) {
            global $wpdb;
            $table = self::table_name();
            $row = $wpdb->get_row($wpdb->prepare("SELECT * FROM {$table} WHERE id = %d", (int) $id), ARRAY_A);
            return $row ?: null;
        }, null, 'lead_store_get');
    }

    /** Conta pendentes + falhos (pro badge na aba). */
    public static function count_unsent() {
        if (!self::available()) return 0;
        return (int) AdSpirit_Safe_Hook::try_run(function () {
            global $wpdb;
            $table = self::table_name();
            return (int) $wpdb->get_var("SELECT COUNT(*) FROM {$table} WHERE status IN ('pending','failed')");
        }, 0, 'lead_store_count');
    }

    // ─────────────────────────────────────────────────────────
    // Reenvio — reusa o submission_id (dedup do CRM não duplica)
    // ─────────────────────────────────────────────────────────

    public function handle_resend() {
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) wp_die('forbidden', 403);
        check_admin_referer('adspirit_resend_submission');

        $id  = isset($_POST['id']) ? (int) $_POST['id'] : 0;
        $row = self::get($id);
        $back = add_query_arg(
            array('page' => AdSpirit_Menu::PAGE_SLUG, 'tab' => 'submissions'),
            admin_url('admin.php')
        );

        if (!$row) {
            wp_safe_redirect(add_query_arg('resend', 'notfound', $back));
            exit;
        }

        $payload = json_decode((string) $row['payload'], true);
        if (!is_array($payload)) {
            wp_safe_redirect(add_query_arg('resend', 'badpayload', $back));
            exit;
        }

        $result = self::dispatch_to_crm((string) $row['submission_id'], $payload);
        self::mark(
            (string) $row['submission_id'],
            'crm',
            $result['ok'] ? 'sent' : 'failed',
            (int) $result['code'],
            $result['error'],
            $result['body']
        );

        wp_safe_redirect(add_query_arg('resend', $result['ok'] ? 'ok' : 'fail', $back));
        exit;
    }

    /**
     * Re-POSTa pro CRM com o submission_id ORIGINAL. Endpoint/headers/body
     * idênticos ao dispatch normal (contrato que o CRM consome não muda).
     * Blocking (ação manual do admin) pra capturar o resultado real.
     */
    private static function dispatch_to_crm($submission_id, array $payload) {
        $core = AdSpirit_Settings::get_core();
        if (empty($core['endpoint_url']) || empty($core['brand_slug']) || empty($core['secret'])) {
            return array('ok' => false, 'code' => 0, 'body' => null, 'error' => 'Conexão CRM incompleta.');
        }

        $endpoint = rtrim((string) $core['endpoint_url'], '/') . '/api/webhooks/contact-form-7';
        $response = wp_remote_post($endpoint, array(
            'timeout' => 10,
            'headers' => array(
                'Content-Type'        => 'application/json; charset=utf-8',
                'x-brand-slug'        => $core['brand_slug'],
                'x-cf7-secret'        => $core['secret'],
                'x-cf7-submission-id' => (string) $submission_id,
                'User-Agent'          => 'AdSpirit-Connector/' . ADSPIRIT_CONNECTOR_VERSION,
            ),
            'body' => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ));

        if (is_wp_error($response)) {
            return array('ok' => false, 'code' => 0, 'body' => null, 'error' => $response->get_error_message());
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        $body = json_decode((string) wp_remote_retrieve_body($response), true);
        $ok = ($code >= 200 && $code < 300);
        return array('ok' => $ok, 'code' => $code, 'body' => is_array($body) ? $body : null, 'error' => $ok ? null : ('HTTP ' . $code));
    }

    // ─────────────────────────────────────────────────────────
    // UI: render da aba Submissões (chamado pelo submissions-log)
    // ─────────────────────────────────────────────────────────

    public static function render_submissions_tab() {
        $filters = array(
            'source' => isset($_GET['sl_source']) ? sanitize_key((string) $_GET['sl_source']) : '',
            'status' => isset($_GET['sl_status']) ? sanitize_key((string) $_GET['sl_status']) : '',
            'search' => isset($_GET['sl_search']) ? sanitize_text_field((string) $_GET['sl_search']) : '',
        );
        $rows = self::query(100, $filters);
        $unsent = self::count_unsent();

        $notice = isset($_GET['resend']) ? sanitize_key((string) $_GET['resend']) : '';
        ?>
        <div class="as-card">
            <h2 class="as-section"><span class="as-kicker-inline">Diagnóstico</span>Submissões (registro durável)</h2>
            <p class="as-section-help">
                Toda submissão é gravada aqui <strong>antes</strong> de ir pro CRM — nenhum lead se perde,
                mesmo se uma integração falhar. <strong>Source of truth é o CRM</strong>; isto é a rede de segurança local.
                Use <strong>Reenviar</strong> num lead falho (reusa o ID original — o CRM não duplica).
            </p>

            <?php if ($notice === 'ok') : ?>
                <div class="as-notice info"><p>Lead reenviado ao CRM com sucesso.</p></div>
            <?php elseif ($notice === 'fail') : ?>
                <div class="as-notice danger"><p>Falha ao reenviar — veja o status na linha.</p></div>
            <?php elseif ($notice === 'notfound' || $notice === 'badpayload') : ?>
                <div class="as-notice warn"><p>Não foi possível reenviar (registro não encontrado ou payload inválido).</p></div>
            <?php endif; ?>

            <div style="display:flex; gap:16px; flex-wrap:wrap; margin:18px 0; font-size:13px;">
                <div><strong><?php echo count($rows); ?></strong> exibidas</div>
                <div<?php echo $unsent > 0 ? ' style="color:var(--as-danger);font-weight:600;"' : ''; ?>>
                    <strong><?php echo (int) $unsent; ?></strong> pendentes/falhos
                </div>
            </div>

            <form method="get" action="" style="margin-bottom:16px;">
                <input type="hidden" name="page" value="<?php echo esc_attr(AdSpirit_Menu::PAGE_SLUG); ?>">
                <input type="hidden" name="tab" value="submissions">
                <input type="search" name="sl_search" value="<?php echo esc_attr($filters['search']); ?>" placeholder="Buscar nome/email/telefone/empresa" style="min-width:260px;">
                <select name="sl_source">
                    <option value="">Todas origens</option>
                    <option value="cf7" <?php selected($filters['source'], 'cf7'); ?>>Contact Form 7</option>
                    <option value="qualifier" <?php selected($filters['source'], 'qualifier'); ?>>Qualifier</option>
                    <option value="qualifier_partial" <?php selected($filters['source'], 'qualifier_partial'); ?>>Qualifier (parcial)</option>
                </select>
                <select name="sl_status">
                    <option value="">Todos status</option>
                    <option value="sent" <?php selected($filters['status'], 'sent'); ?>>Enviado</option>
                    <option value="pending" <?php selected($filters['status'], 'pending'); ?>>Pendente</option>
                    <option value="failed" <?php selected($filters['status'], 'failed'); ?>>Falhou</option>
                </select>
                <button type="submit" class="button">Filtrar</button>
                <?php if ($filters['source'] || $filters['status'] || $filters['search']) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=submissions')); ?>" class="button-link">Limpar</a>
                <?php endif; ?>
            </form>

            <?php if (empty($rows)) : ?>
                <div class="as-notice"><p>Nenhuma submissão registrada (com os filtros atuais).</p></div>
            <?php else : ?>
                <table class="wp-list-table widefat striped">
                    <thead>
                        <tr>
                            <th style="width:120px;">Quando</th>
                            <th style="width:100px;">Origem</th>
                            <th>Contato</th>
                            <th style="width:90px;">Status</th>
                            <th style="width:80px;">Perfil</th>
                            <th style="width:120px;">Ação</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($rows as $r) :
                        $ts = isset($r['created_at']) ? strtotime((string) $r['created_at']) : 0;
                        $when = $ts ? human_time_diff($ts, time()) . ' atrás' : '—';
                        $status = (string) ($r['status'] ?? 'pending');
                        $badge = array('sent' => 'ok', 'pending' => 'warn', 'failed' => 'danger');
                        $status_cls = $badge[$status] ?? 'muted';
                        $can_resend = in_array($status, array('pending', 'failed'), true);
                    ?>
                        <tr>
                            <td title="<?php echo esc_attr((string) ($r['created_at'] ?? '')); ?>"><?php echo esc_html($when); ?></td>
                            <td><span class="as-badge muted"><?php echo esc_html((string) ($r['source'] ?? '')); ?></span></td>
                            <td>
                                <strong><?php echo esc_html((string) ($r['name'] ?? '—')); ?></strong><br>
                                <small><?php echo esc_html((string) ($r['email'] ?? '')); ?></small>
                                <?php if (!empty($r['phone'])) : ?><br><small><?php echo esc_html((string) $r['phone']); ?></small><?php endif; ?>
                                <?php if (!empty($r['company'])) : ?><br><small style="opacity:.7;"><?php echo esc_html((string) $r['company']); ?></small><?php endif; ?>
                            </td>
                            <td><span class="as-badge <?php echo esc_attr($status_cls); ?>"><?php echo esc_html($status); ?></span></td>
                            <td><?php echo $r['profile'] !== '' ? '<span class="as-badge accent">' . esc_html((string) $r['profile']) . '</span>' : '<span style="opacity:.4;">—</span>'; ?></td>
                            <td>
                                <?php if ($can_resend) : ?>
                                    <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="margin:0;">
                                        <input type="hidden" name="action" value="adspirit_resend_submission">
                                        <input type="hidden" name="id" value="<?php echo (int) $r['id']; ?>">
                                        <?php wp_nonce_field('adspirit_resend_submission'); ?>
                                        <button type="submit" class="button button-small" title="Reenvia ao CRM reusando o ID original (sem duplicar)">Reenviar</button>
                                    </form>
                                <?php else : ?>
                                    <span style="opacity:.4;">—</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        <?php
    }
}