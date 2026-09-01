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

    // Connector 3.0: TTL CONSERVADOR da tabela (antes: append-only infinito).
    // Purga SÓ linhas 'sent' (lead confirmado no CRM — a auditoria de longo
    // prazo vive lá; aqui é rede de segurança). pending/failed ficam PRA
    // SEMPRE: são exatamente os leads que ainda precisam de resgate.
    const TTL_DAYS_SENT = 90;

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
        // Connector 3.0: reenvio em massa (padrão Fluent API Logs "bulk replay").
        add_action(
            'admin_post_adspirit_resend_bulk',
            AdSpirit_Safe_Hook::action(array($this, 'handle_resend_bulk'), 'lead_resend_bulk')
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
     * Connector 3.0 — QUARENTENA DE SPAM (padrão WPForms: spam nunca é
     * descartado direto, vai pra revisão). Grava a submissão bloqueada com
     * status 'spam' + motivo. Fica FORA do retry e do badge (ambos filtram
     * pending/failed) e some no TTL de 30 dias. Falso positivo? O botão
     * Reenviar despacha normalmente ("não era spam").
     *
     * Anti-flood: no máx 10 registros de spam por minuto — enxurrada de bot
     * não incha a tabela; o excedente segue indo só pro log circular.
     */
    public static function record_spam(array $payload, $source, $form_id, $reason) {
        if (!self::available()) return false;
        $bucket = 'adspirit_spamq_' . gmdate('YmdHi');
        $n = (int) get_transient($bucket);
        if ($n >= 10) return false;
        set_transient($bucket, $n + 1, 120);

        return AdSpirit_Safe_Hook::try_run(function () use ($payload, $source, $form_id, $reason) {
            global $wpdb;
            $contact = self::extract_contact($payload);
            $now = current_time('mysql', true);
            $wpdb->insert(self::table_name(), array(
                'submission_id' => substr('spam-' . $source . '-' . time() . '-' . wp_generate_password(6, false), 0, 191),
                'source'        => substr((string) $source, 0, 40),
                'form_id'       => substr((string) $form_id, 0, 100),
                'status'        => 'spam',
                'name'          => $contact['name'],
                'email'         => $contact['email'],
                'phone'         => $contact['phone'],
                'company'       => $contact['company'],
                'profile'       => '',
                'lead_id'       => '',
                'last_error'    => substr((string) $reason, 0, 300),
                'payload'       => wp_json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'integrations'  => wp_json_encode(new stdClass()),
                'created_at'    => $now,
                'updated_at'    => $now,
            ));
            return true;
        }, false, 'lead_store_record_spam');
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
            // Connector 3.0 — histórico de tentativas (padrão Fluent API
            // Logs): cada POST (inicial, cron, manual) vira uma linha no
            // histórico, capado nas últimas 5. É o que a aba Submissões
            // expande pra diagnosticar sem SSH.
            $hist = isset($integrations['crm_attempts']) && is_array($integrations['crm_attempts'])
                ? $integrations['crm_attempts'] : array();
            $hist[] = array(
                'at'    => current_time('mysql', true),
                'code'  => $code,
                'error' => !empty($result['error']) ? substr((string) $result['error'], 0, 160) : null,
            );
            $integrations['crm_attempts'] = array_slice($hist, -5);

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
     * P0-3 / Connector 3.0 — cron de retry UNIVERSAL. Re-POSTa SÓ pro CRM
     * (nunca fanout/CAPI/GA4 — esses rodaram no submit original; retry não
     * pode duplicar linha no Sheets), reusando o submission_id original
     * (dedup do CRM não duplica lead).
     *
     * Escopo (3.0): TODAS as fontes que passam pelo dispatcher canônico —
     * cf7, qualifier, native, gravity/wpforms/elementor/fluent, woocommerce.
     * Única exclusão: qualifier_partial. O parcial usa submission_id com
     * sufixo -p (idempotency própria); re-empurrar um parcial DEPOIS do envio
     * final chegaria fora de ordem no CRM e o processor de parciais poderia
     * reprocessar um lead já promovido. Parcial falho fica visível na aba
     * Submissões com reenvio manual.
     *
     * Respeita backoff e o teto de MAX_ATTEMPTS; no máx 5 POSTs por execução
     * pra não segurar o wp-cron.
     */
    public function run_retry() {
        if (!self::available()) return;
        AdSpirit_Safe_Hook::try_run(function () {
            global $wpdb;
            $table = self::table_name();
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT id, submission_id, attempts, updated_at, payload FROM {$table}
                 WHERE source <> %s AND status IN ('pending','failed') AND attempts < %d
                 ORDER BY id ASC LIMIT 20",
                'qualifier_partial',
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

        // Connector 3.0: purga TTL no mesmo cron (barato, sem cron novo).
        // DELETE capado em 200 linhas por execução — dreno gradual, nunca
        // um DELETE gigante segurando lock. Só 'sent' (ver TTL_DAYS_SENT).
        AdSpirit_Safe_Hook::try_run(function () {
            global $wpdb;
            $table = self::table_name();
            $cutoff = gmdate('Y-m-d H:i:s', time() - self::TTL_DAYS_SENT * DAY_IN_SECONDS);
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE status = %s AND created_at < %s LIMIT 200",
                'sent',
                $cutoff
            ));
            // Quarentena de spam expira mais rápido (30d) — ninguém revisa
            // spam de um mês atrás e o volume tende a ser maior.
            $spam_cutoff = gmdate('Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS);
            $wpdb->query($wpdb->prepare(
                "DELETE FROM {$table} WHERE status = %s AND created_at < %s LIMIT 200",
                'spam',
                $spam_cutoff
            ));
        }, null, 'lead_store_ttl_purge');
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
                localmente, mas <strong>não chegam ao AdSpirit</strong> e o reenvio automático foi
                suspenso pra esses leads (repetir não resolve credencial errada).
                Reconecte em <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=connection')); ?>">Conexão com o AdSpirit</a>
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

    public static function query($limit = 100, array $filters = array(), $offset = 0) {
        if (!self::available()) return array();
        return AdSpirit_Safe_Hook::try_run(function () use ($limit, $filters, $offset) {
            global $wpdb;
            $table = self::table_name();
            $where = array('1=1');
            $args  = array();

            if (!empty($filters['source'])) { $where[] = 'source = %s'; $args[] = (string) $filters['source']; }
            if (!empty($filters['status'])) {
                // "problemas" = tudo que não chegou ao CRM e ainda incomoda
                // (o resolvido manualmente sai do radar de propósito).
                if ($filters['status'] === 'problemas') {
                    $where[] = "status IN ('pending','failed')";
                } else {
                    $where[] = 'status = %s';
                    $args[] = (string) $filters['status'];
                }
            }
            if (!empty($filters['search'])) {
                $like = '%' . $wpdb->esc_like((string) $filters['search']) . '%';
                $where[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s OR company LIKE %s)';
                array_push($args, $like, $like, $like, $like);
            }
            $sql = "SELECT * FROM {$table} WHERE " . implode(' AND ', $where) . ' ORDER BY id DESC LIMIT %d OFFSET %d';
            $args[] = (int) $limit;
            $args[] = max(0, (int) $offset);
            $rows = $wpdb->get_results($wpdb->prepare($sql, $args), ARRAY_A);
            return is_array($rows) ? $rows : array();
        }, array(), 'lead_store_query');
    }

    /** Total de linhas pro filtro atual (paginação da aba Submissões). */
    public static function count_filtered(array $filters = array()) {
        if (!self::available()) return 0;
        return (int) AdSpirit_Safe_Hook::try_run(function () use ($filters) {
            global $wpdb;
            $table = self::table_name();
            $where = array('1=1');
            $args  = array();
            if (!empty($filters['source'])) { $where[] = 'source = %s'; $args[] = (string) $filters['source']; }
            if (!empty($filters['status'])) {
                // "problemas" = tudo que não chegou ao CRM e ainda incomoda
                // (o resolvido manualmente sai do radar de propósito).
                if ($filters['status'] === 'problemas') {
                    $where[] = "status IN ('pending','failed')";
                } else {
                    $where[] = 'status = %s';
                    $args[] = (string) $filters['status'];
                }
            }
            if (!empty($filters['search'])) {
                $like = '%' . $wpdb->esc_like((string) $filters['search']) . '%';
                $where[] = '(name LIKE %s OR email LIKE %s OR phone LIKE %s OR company LIKE %s)';
                array_push($args, $like, $like, $like, $like);
            }
            $sql = "SELECT COUNT(*) FROM {$table} WHERE " . implode(' AND ', $where);
            return (int) (empty($args) ? $wpdb->get_var($sql) : $wpdb->get_var($wpdb->prepare($sql, $args)));
        }, 0, 'lead_store_count_filtered');
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

    /**
     * Conta pendentes + falhos (pro badge na aba). Exclui qualifier_partial:
     * parcial abandonado com POST falho fica pendente pra sempre (está fora
     * do cron de retry por design) e inflaria o badge com falso positivo —
     * o lead completo correspondente tem linha própria.
     */
    public static function count_unsent() {
        if (!self::available()) return 0;
        return (int) AdSpirit_Safe_Hook::try_run(function () {
            global $wpdb;
            $table = self::table_name();
            return (int) $wpdb->get_var($wpdb->prepare(
                // 'resolved' fica de fora de propósito: é o "marcar como
                // resolvido" — o lead foi tratado por fora e não deve mais
                // acender o aviso.
                "SELECT COUNT(*) FROM {$table} WHERE status IN ('pending','failed') AND source <> %s",
                'qualifier_partial'
            ));
        }, 0, 'lead_store_count');
    }

    /**
     * Quantos leads estão em quarentena aguardando revisão humana.
     *
     * Bloqueio de spam é decisão AUTOMÁTICA sobre dinheiro entrando, e até
     * agora era silenciosa: o painel contava os bloqueios como métrica, mas
     * nunca avisava. Aconteceu de verdade — um lead perfil A barrado por
     * análise de texto só apareceu porque o Pedro reparou na lista, não
     * porque o plugin disse alguma coisa (incidente 28/08).
     *
     * 'resolved' fica fora pelo mesmo motivo de count_unsent(): é o "já
     * olhei isso", e reacender o aviso depois de revisado seria ruído.
     */
    public static function count_quarantined() {
        if (!self::available()) return 0;
        return (int) AdSpirit_Safe_Hook::try_run(function () {
            global $wpdb;
            $table = self::table_name();
            return (int) $wpdb->get_var(
                "SELECT COUNT(*) FROM {$table} WHERE status = 'spam'"
            );
        }, 0, 'lead_store_count_quarantine');
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
        // Mesma conversão do reenvio em lote — ver o comentário lá.
        if (class_exists('AdSpirit_Form_Qualifier')) {
            $payload = AdSpirit_Form_Qualifier::para_canonico(
                $payload,
                (string) ($row['form_id'] ?? '')
            );
        }
        $result = self::dispatch_to_crm((string) $row['submission_id'], $payload);
        self::mark_crm_attempt((string) $row['submission_id'], $result);

        wp_safe_redirect(add_query_arg('resend', $result['ok'] ? 'ok' : 'fail', $back));
        exit;
    }

    /**
     * Connector 3.0 — reenvio em massa. Mesmo caminho do reenvio unitário
     * (dispatch_to_crm + mark_crm_attempt, submission_id original), capado em
     * 20 por request pra não estourar timeout de admin. O botão unitário da
     * linha também chega aqui (name="single").
     */
    public function handle_resend_bulk() {
        if (!current_user_can(AdSpirit_Menu::CAPABILITY)) wp_die('forbidden', 403);
        check_admin_referer('adspirit_resend_bulk');

        $single = isset($_POST['single']) ? (int) $_POST['single'] : 0;
        $ids = $single > 0
            ? array($single)
            : (isset($_POST['ids']) && is_array($_POST['ids']) ? array_map('intval', $_POST['ids']) : array());
        $ids = array_slice(array_filter(array_unique($ids)), 0, 20);

        $back = add_query_arg(
            array('page' => AdSpirit_Menu::PAGE_SLUG, 'tab' => 'submissions'),
            admin_url('admin.php')
        );
        if (empty($ids)) {
            wp_safe_redirect(add_query_arg('resend', 'none', $back));
            exit;
        }

        // "Marcar como resolvido" (Pedro 08-20): o aviso precisa ter saída.
        // Lead tratado por fora (ligaram pra pessoa, era teste, duplicado)
        // sai do radar sem sumir do histórico — status 'resolved' fica fora
        // do contador que acende o ponto âmbar, mas a linha continua lá,
        // filtrável por "Resolvido manualmente".
        if (!empty($_POST['resolve'])) {
            $n = 0;
            foreach ($ids as $id) {
                $row = self::get($id);
                if (!$row || !in_array((string) ($row['status'] ?? ''), array('pending', 'failed'), true)) continue;
                self::set_status($id, 'resolved');
                $n++;
            }
            wp_safe_redirect(add_query_arg(array('resend' => 'resolved', 'r_ok' => $n), $back));
            exit;
        }
        if (!empty($_POST['unresolve'])) {
            $n = 0;
            foreach ($ids as $id) {
                $row = self::get($id);
                if (!$row || (string) ($row['status'] ?? '') !== 'resolved') continue;
                self::set_status($id, 'pending');
                $n++;
            }
            wp_safe_redirect(add_query_arg(array('resend' => 'reopened', 'r_ok' => $n), $back));
            exit;
        }

        $ok = 0;
        $fail = 0;
        foreach ($ids as $id) {
            $row = self::get($id);
            if (!$row || !in_array((string) ($row['status'] ?? ''), array('pending', 'failed', 'spam'), true)) continue;
            $payload = json_decode((string) $row['payload'], true);
            if (!is_array($payload)) { $fail++; continue; }
            // A quarentena guarda o payload como veio do navegador (certo pra
            // investigar). O CRM espera as chaves canônicas — sem converter,
            // o resgate de um falso positivo respondia "submission has
            // neither email nor phone" com o telefone ali na tela.
            if (class_exists('AdSpirit_Form_Qualifier')) {
                $payload = AdSpirit_Form_Qualifier::para_canonico(
                    $payload,
                    (string) ($row['form_id'] ?? '')
                );
            }
            $result = self::dispatch_to_crm((string) $row['submission_id'], $payload);
            self::mark_crm_attempt((string) $row['submission_id'], $result);
            if (!empty($result['ok'])) { $ok++; } else { $fail++; }
        }

        wp_safe_redirect(add_query_arg(array('resend' => 'bulk', 'r_ok' => $ok, 'r_fail' => $fail), $back));
        exit;
    }

    /** Troca o status de uma linha (usado por resolver/reabrir). */
    public static function set_status($id, $status) {
        if (!self::available()) return false;
        return AdSpirit_Safe_Hook::try_run(function () use ($id, $status) {
            global $wpdb;
            $wpdb->update(
                self::table_name(),
                array('status' => (string) $status, 'updated_at' => current_time('mysql', true)),
                array('id' => (int) $id)
            );
            return true;
        }, false, 'lead_store_set_status');
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
        // Paginação: 50 por página, filtros preservados nos links.
        $per_page = 50;
        $page = isset($_GET['sl_page']) ? max(1, (int) $_GET['sl_page']) : 1;
        $total = self::count_filtered($filters);
        $pages = max(1, (int) ceil($total / $per_page));
        if ($page > $pages) $page = $pages;
        $rows = self::query($per_page, $filters, ($page - 1) * $per_page);
        $unsent = self::count_unsent();

        $notice = isset($_GET['resend']) ? sanitize_key((string) $_GET['resend']) : '';
        $n_ok = isset($_GET['r_ok']) ? (int) $_GET['r_ok'] : 0;
        if ($notice === 'resolved') {
            echo '<div class="as-notice"><p>' . (int) $n_ok . ' lead(s) marcados como resolvidos — saíram do aviso de pendentes e continuam no histórico.</p></div>';
        } elseif ($notice === 'reopened') {
            echo '<div class="as-notice"><p>' . (int) $n_ok . ' lead(s) reabertos — voltam a contar como pendentes.</p></div>';
        }
        ?>
        <div class="as-card">
            <h2 class="as-section"><span class="as-kicker-inline">Diagnóstico</span>Submissões (registro durável)</h2>
            <p class="as-section-help">
                Toda submissão é gravada aqui <strong>antes</strong> de ir pro CRM — nenhum lead se perde,
                mesmo se uma integração falhar. <strong>A ficha completa mora no AdSpirit</strong>; isto é a rede de segurança local.
                Leads pendentes/falhos de <strong>todas as origens</strong> (CF7, qualifier, form nativo,
                Gravity/WPForms/Elementor/Fluent e WooCommerce — exceto parciais do qualifier)
                são <strong>reenviados automaticamente</strong> (a cada 15min,
                backoff 15min/1h/6h/24h, máx <?php echo (int) self::MAX_ATTEMPTS; ?> tentativas, sempre com o ID original — o CRM não duplica;
                o reenvio automático fala só com o CRM, nunca repete Sheets/CAPI/GA4).
                <strong>Reenviar</strong> manual continua disponível pra qualquer lead falho.
            </p>

            <?php if ($notice === 'ok') : ?>
                <div class="as-notice info"><p>Lead reenviado ao AdSpirit.</p></div>
            <?php elseif ($notice === 'fail') : ?>
                <div class="as-notice danger"><p>Falha ao reenviar — veja o status na linha.</p></div>
            <?php elseif ($notice === 'notfound' || $notice === 'badpayload') : ?>
                <div class="as-notice warn"><p>Não foi possível reenviar (registro não encontrado ou payload inválido).</p></div>
            <?php elseif ($notice === 'bulk') :
                $r_ok = isset($_GET['r_ok']) ? (int) $_GET['r_ok'] : 0;
                $r_fail = isset($_GET['r_fail']) ? (int) $_GET['r_fail'] : 0; ?>
                <div class="as-notice <?php echo $r_fail > 0 ? 'warn' : 'info'; ?>"><p>
                    Reenvio em massa: <strong><?php echo $r_ok; ?></strong> enviado(s),
                    <strong><?php echo $r_fail; ?></strong> falha(s).
                </p></div>
            <?php elseif ($notice === 'none') : ?>
                <div class="as-notice warn"><p>Nenhum lead selecionado pra reenviar.</p></div>
            <?php endif; ?>

            <div style="display:flex; gap:16px; flex-wrap:wrap; margin:18px 0; font-size:13px;">
                <div><strong><?php echo count($rows); ?></strong> de <strong><?php echo (int) $total; ?></strong> (página <?php echo (int) $page; ?>/<?php echo (int) $pages; ?>)</div>
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
                    <option value="qualifier" <?php selected($filters['source'], 'qualifier'); ?>>Avaliação (AdSpirit)</option>
                    <option value="qualifier_partial" <?php selected($filters['source'], 'qualifier_partial'); ?>>Avaliação — parcial (AdSpirit)</option>
                    <option value="native" <?php selected($filters['source'], 'native'); ?>>Formulário do AdSpirit</option>
                    <option value="gravity" <?php selected($filters['source'], 'gravity'); ?>>Gravity Forms</option>
                    <option value="wpforms" <?php selected($filters['source'], 'wpforms'); ?>>WPForms</option>
                    <option value="elementor" <?php selected($filters['source'], 'elementor'); ?>>Elementor</option>
                    <option value="fluent" <?php selected($filters['source'], 'fluent'); ?>>Fluent Forms</option>
                    <option value="woocommerce" <?php selected($filters['source'], 'woocommerce'); ?>>WooCommerce</option>
                    <option value="generic" <?php selected($filters['source'], 'generic'); ?>>Detector automático</option>
                </select>
                <select name="sl_status">
                    <option value="">Todos status</option>
                    <option value="problemas" <?php selected($filters['status'], 'problemas'); ?>>Só problemas (pendente + falhou)</option>
                    <option value="sent" <?php selected($filters['status'], 'sent'); ?>>Enviado</option>
                    <option value="pending" <?php selected($filters['status'], 'pending'); ?>>Pendente</option>
                    <option value="failed" <?php selected($filters['status'], 'failed'); ?>>Falhou</option>
                    <option value="resolved" <?php selected($filters['status'], 'resolved'); ?>>Resolvido manualmente</option>
                    <option value="spam" <?php selected($filters['status'], 'spam'); ?>>Spam (quarentena)</option>
                </select>
                <button type="submit" class="button">Filtrar</button>
                <?php if ($filters['source'] || $filters['status'] || $filters['search']) : ?>
                    <a href="<?php echo esc_url(admin_url('admin.php?page=' . AdSpirit_Menu::PAGE_SLUG . '&tab=submissions')); ?>" class="button-link">Limpar</a>
                <?php endif; ?>
            </form>

            <?php if (empty($rows)) : ?>
                <div class="as-notice"><p>Nenhuma submissão registrada (com os filtros atuais).</p></div>
            <?php else : ?>
                <?php // Form ÚNICO pra tabela inteira (bulk + botão por linha via
                      // name="single") — form aninhado por linha é HTML inválido. ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <input type="hidden" name="action" value="adspirit_resend_bulk">
                <?php wp_nonce_field('adspirit_resend_bulk'); ?>
                <p style="margin:0 0 8px; display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
                    <button type="submit" class="button" title="Reenvia os leads marcados (máx. 20 por vez), reusando o ID original — o CRM não duplica">Reenviar selecionados</button>
                    <?php if ($filters['status'] === 'resolved') : ?>
                        <button type="submit" name="unresolve" value="1" class="button" title="Volta a contar como pendente">Reabrir selecionados</button>
                    <?php else : ?>
                        <button type="submit" name="resolve" value="1" class="button" title="Tira do aviso de pendentes sem apagar do histórico — pra lead já tratado por fora, teste ou duplicado">Marcar como resolvido</button>
                    <?php endif; ?>
                    <a class="button-link" href="<?php echo esc_url(add_query_arg(array('page' => AdSpirit_Menu::PAGE_SLUG, 'tab' => 'submissions', 'sl_status' => 'problemas'), admin_url('admin.php'))); ?>">Ver só os problemas</a>
                    <?php // O reenvio processa no máximo 20 por vez. Marcar 60
                          // e ver 20 acontecerem é o tipo de silêncio que faz
                          // alguém achar que a ferramenta engoliu o resto. ?>
                    <span id="as-contagem" style="color:#666;"></span>
                </p>
                <?php
                // `striped` do WP sai de propósito: ele zebra por
                // :nth-child, e cada linha de dados agora tem uma linha de
                // detalhe irmã (mesmo fechada, ela conta no DOM) — o
                // zebrado nativo ficaria todo na mesma cor. A alternância
                // passa a ser marcada no PHP, que sabe qual linha é qual.
                $alt = false;
                ?>
                <script>
                (function () {
                    // O script fica ANTES da tabela no HTML, então na hora em
                    // que ele roda a caixa ainda não existe no DOM. Esperar o
                    // documento montar é o que faz a ligação acontecer — sem
                    // isso a caixa aparece e não faz nada.
                    function ligar() {
                    var mestre = document.getElementById('as-marcar-todas');
                    if (!mestre) return;
                    var form = mestre.closest('form');
                    var contagem = document.getElementById('as-contagem');
                    var TETO = 20; // o mesmo do handler, em array_slice

                    function caixas() {
                        return Array.prototype.slice.call(
                            form.querySelectorAll('input[name="ids[]"]')
                        );
                    }

                    function atualizar() {
                        var todas = caixas();
                        var marcadas = todas.filter(function (c) { return c.checked; });
                        // Meio-marcado quando a seleção é parcial: sem isto a
                        // caixa do topo mente sobre o estado da lista.
                        mestre.checked = todas.length > 0 && marcadas.length === todas.length;
                        mestre.indeterminate = marcadas.length > 0 && marcadas.length < todas.length;

                        if (!contagem) return;
                        if (marcadas.length === 0) {
                            contagem.textContent = '';
                        } else if (marcadas.length > TETO) {
                            contagem.textContent = marcadas.length + ' marcadas — o reenvio processa '
                                + TETO + ' por vez, o resto fica pra próxima rodada';
                        } else {
                            contagem.textContent = marcadas.length
                                + (marcadas.length === 1 ? ' marcada' : ' marcadas');
                        }
                    }

                    mestre.addEventListener('change', function () {
                        caixas().forEach(function (c) { c.checked = mestre.checked; });
                        atualizar();
                    });
                    form.addEventListener('change', function (e) {
                        if (e.target && e.target.name === 'ids[]') atualizar();
                    });
                    atualizar();
                    }

                    if (document.readyState === 'loading') {
                        document.addEventListener('DOMContentLoaded', ligar);
                    } else {
                        ligar();
                    }
                })();
                </script>
                <table class="wp-list-table widefat as-striped">
                    <thead>
                        <tr>
                            <th style="width:28px;">
                                <input type="checkbox" id="as-marcar-todas"
                                       title="Marcar todas as linhas desta página"
                                       aria-label="Marcar todas as linhas desta página">
                            </th>
                            <th style="width:100px;">Quando</th>
                            <th style="width:150px;">Formulário</th>
                            <th style="width:60px;">Perfil</th>
                            <th>Contato</th>
                            <th style="width:115px;">Canal</th>
                            <th style="width:170px;">Status</th>
                            <th style="width:105px;">Ação</th>
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
                        $badge = array('sent' => 'ok', 'pending' => 'warn', 'failed' => 'danger', 'spam' => 'muted', 'resolved' => 'muted');
                        $status_cls = $badge[$status] ?? 'muted';
                        // Spam reenviável de propósito: falso positivo sai da
                        // quarentena pelo mesmo botão ("não era spam").
                        $can_resend = in_array($status, array('pending', 'failed', 'spam'), true);
                        $alt = !$alt;
                    ?>
                        <tr class="<?php echo $alt ? 'as-row-alt' : ''; ?>">
                            <td>
                                <?php if ($can_resend) : ?>
                                    <input type="checkbox" name="ids[]" value="<?php echo (int) $r['id']; ?>">
                                <?php endif; ?>
                            </td>
                            <td title="<?php echo esc_attr($when_title); ?>"><?php echo esc_html($when); ?></td>
                            <td>
                                <?php
                                // Qual formulário, e em que motor. Antes saía a
                                // chave interna ("form", "qualifier", "cf7"),
                                // que não diz nada a quem opera — e dois
                                // formulários do mesmo motor eram idênticos.
                                $ident = class_exists('AdSpirit_Payload_View')
                                    ? AdSpirit_Payload_View::form_identity((string) ($r['source'] ?? ''), (string) ($r['form_id'] ?? ''))
                                    : array('form' => '', 'engine' => (string) ($r['source'] ?? ''));
                                ?>
                                <div class="as-origin">
                                    <?php if ($ident['form'] !== '') : ?>
                                        <span class="as-origin-form"><?php echo esc_html($ident['form']); ?></span>
                                    <?php endif; ?>
                                    <span class="as-origin-engine"><?php echo esc_html($ident['engine']); ?></span>
                                </div>
                                <?php
                                // Integrações secundárias da linha (fanout etc.)
                                // — o CRM já é o status principal ao lado.
                                $integ_row = json_decode((string) ($r['integrations'] ?? ''), true);
                                if (is_array($integ_row)) {
                                    $done = array();
                                    foreach (array('fanout' => 'webhooks', 'capi' => 'Meta', 'ga4' => 'GA4') as $ik => $ilabel) {
                                        if (!empty($integ_row[$ik])) $done[] = $ilabel;
                                    }
                                    if ($done) {
                                        echo '<div class="as-origin-also">também em ' . esc_html(implode(', ', $done)) . '</div>';
                                    }
                                }
                                ?>
                            </td>
                            <td><?php echo $r['profile'] !== '' ? '<span class="as-badge accent">' . esc_html((string) $r['profile']) . '</span>' : '<span style="opacity:.4;">—</span>'; ?></td>
                            <td>
                                <?php
                                $pl = json_decode((string) ($r['payload'] ?? ''), true);
                                $has_detail = is_array($pl) && !empty($pl);
                                $detail_id = 'as-detail-' . (int) $r['id'];
                                ?>
                                <strong><?php echo esc_html((string) ($r['name'] ?? '—')); ?></strong><br>
                                <small><?php echo esc_html((string) ($r['email'] ?? '')); ?></small>
                                <?php if (!empty($r['phone'])) : ?><br><small><?php echo esc_html((string) $r['phone']); ?></small><?php endif; ?>
                                <?php if (!empty($r['company'])) : ?><br><small style="opacity:.7;"><?php echo esc_html((string) $r['company']); ?></small><?php endif; ?>
                                <?php if ($has_detail) : ?>
                                    <?php // Abrir o detalhe é sobre ESTA pessoa — mora
                                          // junto do contato, não na coluna de ações
                                          // sobre a entrega. Em bloco próprio pra não
                                          // encostar na última linha do contato. ?>
                                    <div class="as-contact-action">
                                        <button type="button" class="as-detail-toggle" aria-expanded="false"
                                                aria-controls="<?php echo esc_attr($detail_id); ?>">
                                            <span class="as-detail-chevron" aria-hidden="true"></span>Ver detalhes
                                        </button>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                // Canal de origem — Google Ads, Meta, busca,
                                // direto. Derivado do click id / UTM / referrer
                                // que já viajam no payload.
                                $chan = (class_exists('AdSpirit_Payload_View') && is_array($pl))
                                    ? AdSpirit_Payload_View::channel_identity($pl)
                                    : array('label' => '—', 'kind' => 'direct');
                                ?>
                                <span class="as-channel as-channel--<?php echo esc_attr($chan['kind']); ?>">
                                    <?php echo esc_html($chan['label']); ?>
                                </span>
                            </td>
                            <td>
                                <?php
                                // Ponto + frase, não badge: o badge é UPPERCASE
                                // com letter-spacing, feito pra rótulo de uma
                                // palavra. "ENVIADO AO ADSPIRIT" gritaria e não
                                // caberia. O ponto colorido já é o padrão de
                                // saúde do design system (as-dot na navegação).
                                ?>
                                <span class="as-status as-status--<?php echo esc_attr($status_cls); ?>">
                                    <span class="as-status-dot" aria-hidden="true"></span><?php
                                    echo esc_html(class_exists('AdSpirit_Payload_View')
                                        ? AdSpirit_Payload_View::status_label($status)
                                        : $status);
                                ?></span>
                                <?php $att = (int) ($r['attempts'] ?? 0); ?>
                                <?php if ($att > 1) : ?>
                                    <div class="as-status-note"><?php echo (int) $att; ?> tentativas</div>
                                <?php endif; ?>
                                <?php $lerr = (string) ($r['last_error'] ?? ''); ?>
                                <?php if ($lerr !== '' && $status !== 'sent') : ?>
                                    <div class="as-status-note" title="<?php echo esc_attr($lerr); ?>"><?php echo esc_html(mb_substr($lerr, 0, 60)); ?><?php echo mb_strlen($lerr) > 60 ? '…' : ''; ?></div>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                // Lead que chegou no CRM tem id de lá (leadId
                                // na resposta). Em vez de espelhar qualificação,
                                // temperatura e dono — que são do CRM e
                                // duplicariam a fonte —, o plugin LINKA.
                                $crm_lead_url = '';
                                $lead_id_row  = (string) ($r['lead_id'] ?? '');
                                if ($lead_id_row !== '' && class_exists('AdSpirit_Settings')) {
                                    $core_row = AdSpirit_Settings::get_core();
                                    if (!empty($core_row['endpoint_url'])) {
                                        $crm_lead_url = rtrim((string) $core_row['endpoint_url'], '/') . '/leads/' . rawurlencode($lead_id_row);
                                    }
                                }
                                ?>
                                <div class="as-row-actions">
                                    <?php if ($can_resend) : ?>
                                        <button type="submit" class="button button-small" name="single" value="<?php echo (int) $r['id']; ?>" title="Reenvia ao AdSpirit reusando o ID original — não duplica o lead">Reenviar</button>
                                    <?php endif; ?>
                                    <?php if ($crm_lead_url !== '') : ?>
                                        <a class="as-crm-link" href="<?php echo esc_url($crm_lead_url); ?>"
                                           target="_blank" rel="noopener"
                                           title="Abre a ficha completa deste lead no AdSpirit">Ver no AdSpirit</a>
                                    <?php endif; ?>
                                    <?php if (!$can_resend && $crm_lead_url === '') : ?>
                                        <span style="opacity:.4;">—</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php if ($has_detail) : ?>
                            <tr class="as-detail-row" id="<?php echo esc_attr($detail_id); ?>" hidden>
                                <td colspan="8">
                                    <?php
                                    // Bloco "Entrega": histórico de tentativas ao
                                    // CRM. Vive aqui, junto do resto do
                                    // diagnóstico, em vez de espremido no Status.
                                    $integ = json_decode((string) ($r['integrations'] ?? ''), true);
                                    $hist = is_array($integ) && isset($integ['crm_attempts']) && is_array($integ['crm_attempts'])
                                        ? $integ['crm_attempts'] : array();
                                    $extra = array();
                                    if (!empty($hist)) {
                                        $rows_hist = array();
                                        foreach (array_reverse($hist) as $h) {
                                            $when_h = !empty($h['at']) ? get_date_from_gmt((string) $h['at'], 'd/m H:i') : '—';
                                            $code   = (int) ($h['code'] ?? 0);
                                            $val    = 'HTTP ' . $code;
                                            if (!empty($h['error'])) $val .= ' · ' . mb_substr((string) $h['error'], 0, 80);
                                            $rows_hist[] = array('label' => $when_h, 'value' => $val, 'key' => '');
                                        }
                                        $extra[] = array('title' => 'Tentativas de entrega', 'rows' => $rows_hist, 'tone' => 'muted');
                                    }
                                    echo class_exists('AdSpirit_Payload_View')
                                        ? AdSpirit_Payload_View::render_panel($pl, $extra) // escapado no render
                                        : '';
                                    ?>
                                </td>
                            </tr>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </form>
                <script>
                // Toggle do painel de detalhe. Delegação num handler só — a
                // tabela pagina em 20 linhas e não faz sentido um listener
                // por linha. <details> não serve aqui: o painel é uma <tr>
                // irmã, não filha do gatilho.
                (function () {
                    var root = document.querySelector('.adspirit-app') || document;
                    root.addEventListener('click', function (e) {
                        var btn = e.target.closest('.as-detail-toggle');
                        if (!btn) return;
                        e.preventDefault();
                        var row = document.getElementById(btn.getAttribute('aria-controls'));
                        if (!row) return;
                        var open = btn.getAttribute('aria-expanded') === 'true';
                        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
                        if (open) row.setAttribute('hidden', 'hidden');
                        else row.removeAttribute('hidden');
                    });
                })();
                </script>
                <?php if ($pages > 1) :
                    $base_args = array_filter(array(
                        'page' => AdSpirit_Menu::PAGE_SLUG,
                        'tab' => 'submissions',
                        'sl_source' => $filters['source'],
                        'sl_status' => $filters['status'],
                        'sl_search' => $filters['search'],
                    ));
                ?>
                    <div style="display:flex; gap:8px; align-items:center; margin-top:12px;">
                        <?php if ($page > 1) : ?>
                            <a class="button" href="<?php echo esc_url(add_query_arg(array_merge($base_args, array('sl_page' => $page - 1)), admin_url('admin.php'))); ?>">‹ Anteriores</a>
                        <?php endif; ?>
                        <span style="font-size:12px; opacity:.7;">página <?php echo (int) $page; ?> de <?php echo (int) $pages; ?></span>
                        <?php if ($page < $pages) : ?>
                            <a class="button" href="<?php echo esc_url(add_query_arg(array_merge($base_args, array('sl_page' => $page + 1)), admin_url('admin.php'))); ?>">Próximas ›</a>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <?php
    }
}