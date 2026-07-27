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
 * P0-3 (envio confiável): o dispatch CF7 agora é blocking e o status reflete a
 * resposta REAL do CRM (2xx=sent · 4xx/5xx=failed · timeout/rede=pending).
 * Cron de retry a cada 15min re-POSTa pending/failed com backoff
 * (15min/1h/6h/24h, máx 5 tentativas), reusando o submission_id — e SÓ o CRM:
 * fanout/CAPI/GA4 rodam UMA vez, no submit original, nunca no retry.
 * 401/403 = credenciais → failed definitivo (sem loop) + aviso no painel.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Lead_Store {
    const TABLE            = 'adspirit_submissions';
    const OPTION_DB_VERSION = 'adspirit_connector_submissions_db_version';
    const DB_VERSION        = '2'; // v2 (P0-3): + attempts, last_error

    // P0-3: retry automático
    const CRON_HOOK         = 'adspirit_lead_store_retry';
    const CRON_INTERVAL_KEY = 'adspirit_15min';
    const MAX_ATTEMPTS      = 5;
    const OPTION_AUTH_ERROR = 'adspirit_connector_crm_auth_error';

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

        // P0-3: cron de retry (15min). Agendado aqui (boot) e não só na
        // ativação — cobre update via GitHub, onde o activation hook não roda.
        add_filter('cron_schedules', array(__CLASS__, 'register_cron_interval'));
        add_action(
            self::CRON_HOOK,
            AdSpirit_Safe_Hook::action(array($this, 'run_retry'), 'lead_store_retry')
        );
        if (self::available() && !wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time() + 15 * MINUTE_IN_SECONDS, self::CRON_INTERVAL_KEY, self::CRON_HOOK);
        }

        // P0-3: aviso de credenciais rejeitadas (401/403) nas páginas do plugin.
        add_action(
            'admin_notices',
            AdSpirit_Safe_Hook::action(array($this, 'render_auth_error_notice'), 'lead_store_auth_notice')
        );
    }

    /** Intervalo custom de 15min. Estático e idempotente (filter cron_schedules). */
    public static function register_cron_interval($schedules) {
        if (!is_array($schedules)) $schedules = array();
        if (!isset($schedules[self::CRON_INTERVAL_KEY])) {
            $schedules[self::CRON_INTERVAL_KEY] = array(
                'interval' => 15 * MINUTE_IN_SECONDS,
                'display'  => 'A cada 15 minutos (AdSpirit)',
            );
        }
        return $schedules;
    }

    /** Desagenda o cron de retry. Chamado na desativação do plugin. */
    public static function unschedule() {
        wp_clear_scheduled_hook(self::CRON_HOOK);
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
  attempts smallint(5) unsigned NOT NULL DEFAULT 0,
  last_error varchar(300) NOT NULL DEFAULT '',
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

            // P0-3: confirma também as colunas do schema v2. Sem isso, um
            // ALTER falho (permissão, etc) marcaria a versão mesmo assim e o
            // UPDATE de attempts/last_error falharia silencioso — status
            // ficaria 'pending' pra sempre e o backoff nunca cresceria
            // (retry eterno de 15min). Coluna ausente = indisponível → o
            // fluxo inteiro cai no fallback legado, como antes da Fase 1.
            $has_v2_cols = $exists
                && $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'attempts'") !== null
                && $wpdb->get_var("SHOW COLUMNS FROM {$table} LIKE 'last_error'") !== null;

            if ($exists && $has_v2_cols) {
                update_option(self::OPTION_DB_VERSION, self::DB_VERSION, true);
                self::$available = true;
                return true;
            }
            error_log('[AdSpirit Connector] Lead_Store: ' . ($exists
                ? 'colunas do schema v2 (attempts/last_error) não criaram — usando fallback (log legado).'
                : 'tabela não criou — usando fallback (log legado).'));
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
            // GMT (segundo arg true): gravamos em UTC e comparamos como UTC no
            // render → "X atrás" fica correto independente do fuso do site.
            $now = current_time('mysql', true);

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
                'at'        => current_time('mysql', true),
            );

            $update = array(
                'integrations' => wp_json_encode($integrations),
                'updated_at'   => current_time('mysql', true),
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

    /**
     * P0-3 — registra o resultado de UMA tentativa de envio ao CRM (inicial,
     * retry do cron ou reenvio manual) e devolve o status computado:
     *   2xx            → 'sent'   (limpa o aviso de credenciais)
     *   401/403        → 'failed' DEFINITIVO — attempts vai pra MAX_ATTEMPTS
     *                    (cron não pega mais) + option de aviso no painel
     *   outros 4xx/5xx → 'failed' (código + corpo resumido em last_error)
     *   código 0       → 'pending' (timeout/rede — o cron tenta de novo)
     *
     * O status é computado SEMPRE (pro caller logar); a linha só é atualizada
     * se a tabela estiver disponível.
     *
     * @param string $submission_id
     * @param array  $result  retorno de dispatch_to_crm(): ok, code, body, error
     * @return string sent|failed|pending
     */
    public static function mark_crm_attempt($submission_id, array $result) {
        $code = isset($result['code']) ? (int) $result['code'] : 0;
        $ok   = !empty($result['ok']);

        if ($ok) {
            $status = 'sent';
        } elseif ($code === 401 || $code === 403) {
            $status = 'failed';
        } elseif ($code > 0) {
            $status = 'failed';
        } else {
            $status = 'pending';
        }

        // Aviso de credenciais: seta em 401/403, limpa em qualquer sucesso.
        if ($code === 401 || $code === 403) {
            update_option(self::OPTION_AUTH_ERROR, array(
                'code'   => $code,
                'detail' => isset($result['error']) ? substr((string) $result['error'], 0, 300) : '',
                'at'     => current_time('mysql', true),
            ), false);
        } elseif ($ok && get_option(self::OPTION_AUTH_ERROR)) {
            delete_option(self::OPTION_AUTH_ERROR);
        }

        if (!self::available()) return $status;

        AdSpirit_Safe_Hook::try_run(function () use ($submission_id, $result, $status, $code, $ok) {
            global $wpdb;
            $table = self::table_name();
            $row = $wpdb->get_row($wpdb->prepare(
                "SELECT id, attempts, integrations FROM {$table} WHERE submission_id = %s ORDER BY id DESC LIMIT 1",
                (string) $submission_id
            ), ARRAY_A);
            if (!$row) return;

            $attempts = (int) $row['attempts'] + 1;
            // 401/403: credenciais erradas não se resolvem repetindo — trava
            // no teto pra tirar do radar do cron (reenvio manual segue possível).
            if ($code === 401 || $code === 403) {
                $attempts = max($attempts, self::MAX_ATTEMPTS);
            }

            $integrations = json_decode((string) $row['integrations'], true);
            if (!is_array($integrations)) $integrations = array();
            $integrations['crm'] = array(
                'status'    => $status,
                'http_code' => $code,
                'error'     => !empty($result['error']) ? substr((string) $result['error'], 0, 300) : null,
                'at'        => current_time('mysql', true),
            );

            $update = array(
                'status'       => $status,
                'attempts'     => $attempts,
                'last_error'   => $ok ? '' : substr((string) ($result['error'] ?? ''), 0, 300),
                'integrations' => wp_json_encode($integrations),
                'updated_at'   => current_time('mysql', true),
            );

            // Extrai profile/lead_id da resposta do CRM (mesma lógica do mark()).
            if ($ok && isset($result['body']) && is_array($result['body'])) {
                $body    = $result['body'];
                $profile = isset($body['profile']) ? (string) $body['profile'] : '';
                $res     = isset($body['result']) && is_array($body['result']) ? $body['result'] : array();
                $lead_id = isset($res['leadId']) ? (string) $res['leadId'] : '';
                if ($profile !== '') $update['profile'] = substr($profile, 0, 10);
                if ($lead_id !== '') $update['lead_id'] = substr($lead_id, 0, 100);
            }

            $wpdb->update($table, $update, array('id' => (int) $row['id']));
        }, null, 'lead_store_mark_attempt');

        return $status;
    }

    /**
     * P0-3 — espera (s) antes da PRÓXIMA tentativa, dado o nº de tentativas
     * já feitas: 1→15min · 2→1h · 3→6h · 4+→24h. attempts=0 (linha legada ou
     * gravada sem POST concluído) conta como 1.
     */
    public static function backoff_seconds($attempts) {
        $map = array(1 => 15 * MINUTE_IN_SECONDS, 2 => HOUR_IN_SECONDS, 3 => 6 * HOUR_IN_SECONDS, 4 => DAY_IN_SECONDS);
        $a = max(1, min(4, (int) $attempts));
        return $map[$a];
    }

    /**
     * P0-3 — cron de retry. Re-POSTa SÓ pro CRM (nunca fanout/CAPI/GA4 — esses
     * rodaram no submit original; retry não pode duplicar linha no Sheets),
     * reusando o submission_id original (dedup do CRM não duplica lead).
     * Escopo: source='cf7' (qualifier/nativo têm fluxo próprio e o parcial do
     * qualifier não deve ser re-empurrado por cron). Respeita backoff e o teto
     * de MAX_ATTEMPTS; no máx 5 POSTs por execução pra não segurar o wp-cron.
     */
    public function run_retry() {
        if (!self::available()) return;
        AdSpirit_Safe_Hook::try_run(function () {
            global $wpdb;
            $table = self::table_name();
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, submission_id, attempts, updated_at, payload FROM {$table}
                 WHERE source = %s AND status IN ('pending','failed') AND attempts < %d
                 ORDER BY id ASC LIMIT 20",
                'cf7',
                self::MAX_ATTEMPTS
            ), ARRAY_A);
            if (!is_array($rows) || empty($rows)) return;

            $now = time();
            $posted = 0;
            foreach ($rows as $row) {
                $last = !empty($row['updated_at']) ? strtotime((string) $row['updated_at'] . ' UTC') : 0;
                if ($last && ($last + self::backoff_seconds((int) $row['attempts'])) > $now) {
                    continue; // ainda dentro do backoff
                }
                $payload = json_decode((string) $row['payload'], true);
                if (!is_array($payload)) continue;

                $result = self::dispatch_to_crm((string) $row['submission_id'], $payload, 10);
                self::mark_crm_attempt((string) $row['submission_id'], $result);

                if (++$posted >= 5) break;
            }
        }, null, 'lead_store_retry_run');
    }

    /** P0-3 — aviso persistente quando o CRM rejeitou as credenciais (401/403). */
    public function render_auth_error_notice() {
        if (!is_admin() || !class_exists('AdSpirit_Menu')) return;
        if (!isset($_GET['page']) || $_GET['page'] !== AdSpirit_Menu::PAGE_SLUG) return;
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) return;
        $err = get_option(self::OPTION_AUTH_ERROR);
        if (empty($err) || !is_array($err)) return;
        ?>
        <div class="notice notice-error">
            <p>
                <strong>AdSpirit Connector:</strong> o CRM rejeitou as credenciais do plugin
                (HTTP <?php echo (int) ($err['code'] ?? 0); ?>). Os leads estão sendo gravados
                localmente, mas <strong>não chegam ao CRM</strong> e o reenvio automático foi
                suspenso pra esses leads (repetir não resolve credencial errada).
                Reconecte em <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=connection')); ?>">Conexão CRM</a>
                e use <em>Reenviar</em> na aba Submissões.
            </p>
        </div>
        <?php
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

        // P0-3: passa pelo mesmo registrador do dispatch/retry — attempts e
        // last_error ficam verdadeiros também no reenvio manual (e um 2xx
        // aqui limpa o aviso de credenciais).
        $result = self::dispatch_to_crm((string) $row['submission_id'], $payload);
        self::mark_crm_attempt((string) $row['submission_id'], $result);

        wp_safe_redirect(add_query_arg('resend', $result['ok'] ? 'ok' : 'fail', $back));
        exit;
    }

    /**
     * POSTa pro CRM com o submission_id ORIGINAL. Endpoint/headers/body
     * idênticos ao dispatch legado (contrato que o CRM consome não muda).
     * Blocking pra capturar o resultado real. P0-3: público — é o caminho
     * único de envio (dispatch inicial do CF7, cron de retry e reenvio
     * manual), então "o que conta como sucesso" vive num lugar só.
     *
     * @param string $submission_id
     * @param array  $payload
     * @param int    $timeout  s — 5 no submit do visitante (bounded), 10 em cron/manual
     * @return array ok(bool) | code(int, 0 = rede/timeout) | body(array|null) | error(string|null)
     */
    public static function dispatch_to_crm($submission_id, array $payload, $timeout = 10) {
        $core = AdSpirit_Settings::get_core();
        if (empty($core['endpoint_url']) || empty($core['brand_slug']) || empty($core['secret'])) {
            return array('ok' => false, 'code' => 0, 'body' => null, 'error' => 'Conexão CRM incompleta.');
        }

        $endpoint = rtrim((string) $core['endpoint_url'], '/') . '/api/webhooks/contact-form-7';
        $response = wp_remote_post($endpoint, array(
            'timeout' => max(1, (int) $timeout),
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
        $raw_body = (string) wp_remote_retrieve_body($response);
        $body = json_decode($raw_body, true);
        $ok = ($code >= 200 && $code < 300);
        // P0-3: corpo resumido no erro — "HTTP 422 — {motivo}" diagnostica
        // sozinho na aba Submissões, sem caçar log do CRM.
        $error = null;
        if (!$ok) {
            $summary = trim(preg_replace('/\s+/', ' ', wp_strip_all_tags($raw_body)));
            $error = 'HTTP ' . $code . ($summary !== '' ? ' — ' . substr($summary, 0, 180) : '');
        }
        return array('ok' => $ok, 'code' => $code, 'body' => is_array($body) ? $body : null, 'error' => $error);
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
                Leads CF7 pendentes/falhos são <strong>reenviados automaticamente</strong> (a cada 15min,
                backoff 15min/1h/6h/24h, máx <?php echo (int) self::MAX_ATTEMPTS; ?> tentativas, sempre com o ID original — o CRM não duplica;
                o reenvio automático fala só com o CRM, nunca repete Sheets/CAPI/GA4).
                <strong>Reenviar</strong> manual continua disponível pra qualquer lead falho.
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
                        // created_at é gravado em GMT → parse como UTC e compara
                        // com time() (UTC). title mostra a hora no fuso do site.
                        $ts = !empty($r['created_at']) ? strtotime((string) $r['created_at'] . ' UTC') : 0;
                        $when = $ts ? human_time_diff($ts, time()) . ' atrás' : '—';
                        $when_title = $ts ? get_date_from_gmt((string) $r['created_at'], 'Y-m-d H:i') : '';
                        $status = (string) ($r['status'] ?? 'pending');
                        $badge = array('sent' => 'ok', 'pending' => 'warn', 'failed' => 'danger');
                        $status_cls = $badge[$status] ?? 'muted';
                        $can_resend = in_array($status, array('pending', 'failed'), true);
                    ?>
                        <tr>
                            <td title="<?php echo esc_attr($when_title); ?>"><?php echo esc_html($when); ?></td>
                            <td><span class="as-badge muted"><?php echo esc_html((string) ($r['source'] ?? '')); ?></span></td>
                            <td>
                                <strong><?php echo esc_html((string) ($r['name'] ?? '—')); ?></strong><br>
                                <small><?php echo esc_html((string) ($r['email'] ?? '')); ?></small>
                                <?php if (!empty($r['phone'])) : ?><br><small><?php echo esc_html((string) $r['phone']); ?></small><?php endif; ?>
                                <?php if (!empty($r['company'])) : ?><br><small style="opacity:.7;"><?php echo esc_html((string) $r['company']); ?></small><?php endif; ?>
                            </td>
                            <td>
                                <span class="as-badge <?php echo esc_attr($status_cls); ?>"><?php echo esc_html($status); ?></span>
                                <?php $att = (int) ($r['attempts'] ?? 0); ?>
                                <?php if ($att > 1) : ?>
                                    <br><small style="opacity:.7;"><?php echo (int) $att; ?> tentativas</small>
                                <?php endif; ?>
                                <?php $lerr = (string) ($r['last_error'] ?? ''); ?>
                                <?php if ($lerr !== '' && $status !== 'sent') : ?>
                                    <br><small style="opacity:.7;" title="<?php echo esc_attr($lerr); ?>"><?php echo esc_html(mb_substr($lerr, 0, 60)); ?><?php echo mb_strlen($lerr) > 60 ? '…' : ''; ?></small>
                                <?php endif; ?>
                            </td>
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