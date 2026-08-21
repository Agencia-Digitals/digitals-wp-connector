<?php
/**
 * AdSpirit Connector — Log de e-mails.
 *
 * Substitui o WP Mail Log: registra toda tentativa de envio do site
 * (destinatário, assunto, resultado e erro) pra diagnosticar entrega —
 * principalmente nas automações de marketing, onde "não chegou" é a
 * reclamação mais cara de investigar sem histórico.
 *
 * Guarda em tabela própria, com retenção configurável (padrão 30 dias),
 * pra não inchar wp_options nem o banco do cliente.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Mail_Log {

    const OPTION_RETENCAO = 'adspirit_mail_log_retencao_dias';
    const CRON_LIMPEZA = 'adspirit_mail_log_limpeza';
    const VERSAO_TABELA = 1;

    private static $instance = null;
    private $ultimo = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_filter('wp_mail', AdSpirit_Safe_Hook::filter(array($this, 'antes_de_enviar'), 'mail_log'), 999);
        add_action('wp_mail_succeeded', AdSpirit_Safe_Hook::action(array($this, 'deu_certo'), 'mail_log'));
        add_action('wp_mail_failed', AdSpirit_Safe_Hook::action(array($this, 'deu_errado'), 'mail_log'));
        add_action('init', AdSpirit_Safe_Hook::action(array($this, 'agendar_limpeza'), 'mail_log'));
        add_action(self::CRON_LIMPEZA, AdSpirit_Safe_Hook::action(array($this, 'limpar_antigos'), 'mail_log'));
    }

    public static function tabela() {
        global $wpdb;
        return $wpdb->prefix . 'adspirit_mail_log';
    }

    /** Cria a tabela na primeira escrita — sem depender de hook de ativação. */
    private function garantir_tabela() {
        global $wpdb;
        if (get_option('adspirit_mail_log_tabela') == self::VERSAO_TABELA) return;
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $tabela = self::tabela();
        $collate = $wpdb->get_charset_collate();
        dbDelta("CREATE TABLE {$tabela} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            enviado_em datetime NOT NULL,
            destinatario varchar(255) NOT NULL DEFAULT '',
            assunto varchar(255) NOT NULL DEFAULT '',
            sucesso tinyint(1) NOT NULL DEFAULT 0,
            erro text NULL,
            origem varchar(120) NOT NULL DEFAULT '',
            PRIMARY KEY (id),
            KEY enviado_em (enviado_em),
            KEY destinatario (destinatario)
        ) {$collate};");
        update_option('adspirit_mail_log_tabela', self::VERSAO_TABELA, false);
    }

    /** Quem disparou o e-mail — ajuda a separar automação de formulário. */
    private function descobrir_origem() {
        $pilha = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 12);
        foreach ($pilha as $quadro) {
            $arquivo = isset($quadro['file']) ? $quadro['file'] : '';
            if (!$arquivo || strpos($arquivo, 'wp-includes') !== false) continue;
            if (strpos($arquivo, 'class-adspirit-mail-log') !== false) continue;
            $relativo = str_replace(WP_CONTENT_DIR, '', $arquivo);
            return substr($relativo, 0, 110);
        }
        return '';
    }

    public function antes_de_enviar($args) {
        $para = isset($args['to']) ? $args['to'] : '';
        if (is_array($para)) $para = implode(', ', $para);
        $this->ultimo = array(
            'destinatario' => substr((string) $para, 0, 255),
            'assunto' => substr((string) (isset($args['subject']) ? $args['subject'] : ''), 0, 255),
            'origem' => $this->descobrir_origem(),
        );
        return $args;
    }

    public function deu_certo($info) {
        $registro = $this->ultimo;
        if (!$registro && is_array($info)) {
            $para = isset($info['to']) ? $info['to'] : '';
            if (is_array($para)) $para = implode(', ', $para);
            $registro = array('destinatario' => (string) $para, 'assunto' => (string) (isset($info['subject']) ? $info['subject'] : ''), 'origem' => '');
        }
        $this->gravar($registro, true, null);
    }

    public function deu_errado($erro) {
        $mensagem = is_wp_error($erro) ? $erro->get_error_message() : (string) $erro;
        $this->gravar($this->ultimo, false, $mensagem);
    }

    private function gravar($registro, $sucesso, $erro) {
        if (!is_array($registro)) return;
        global $wpdb;
        $this->garantir_tabela();
        $wpdb->insert(self::tabela(), array(
            'enviado_em' => current_time('mysql'),
            'destinatario' => $registro['destinatario'],
            'assunto' => $registro['assunto'],
            'sucesso' => $sucesso ? 1 : 0,
            'erro' => $erro ? substr($erro, 0, 2000) : null,
            'origem' => isset($registro['origem']) ? $registro['origem'] : '',
        ));
        $this->ultimo = null;
    }

    public function agendar_limpeza() {
        if (!wp_next_scheduled(self::CRON_LIMPEZA)) {
            wp_schedule_event(time() + HOUR_IN_SECONDS, 'daily', self::CRON_LIMPEZA);
        }
    }

    public function limpar_antigos() {
        global $wpdb;
        $dias = (int) get_option(self::OPTION_RETENCAO, 30);
        if ($dias < 1) $dias = 30;
        $tabela = self::tabela();
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$tabela} WHERE enviado_em < DATE_SUB(NOW(), INTERVAL %d DAY)", $dias
        ));
    }

    /** Últimos envios, pra tela do connector. */
    public static function recentes($limite = 100, $apenas_falhas = false) {
        global $wpdb;
        $tabela = self::tabela();
        if ($wpdb->get_var("SHOW TABLES LIKE '{$tabela}'") !== $tabela) return array();
        $onde = $apenas_falhas ? 'WHERE sucesso = 0' : '';
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$tabela} {$onde} ORDER BY id DESC LIMIT %d", (int) $limite
        ), ARRAY_A);
    }

    /** Resumo pra saúde do site: quantos falharam nas últimas 24h. */
    public static function resumo_24h() {
        global $wpdb;
        $tabela = self::tabela();
        if ($wpdb->get_var("SHOW TABLES LIKE '{$tabela}'") !== $tabela) return array('total' => 0, 'falhas' => 0);
        $linha = $wpdb->get_row(
            "SELECT COUNT(*) total, SUM(CASE WHEN sucesso = 0 THEN 1 ELSE 0 END) falhas
             FROM {$tabela} WHERE enviado_em > DATE_SUB(NOW(), INTERVAL 1 DAY)", ARRAY_A
        );
        return array('total' => (int) $linha['total'], 'falhas' => (int) $linha['falhas']);
    }
}
