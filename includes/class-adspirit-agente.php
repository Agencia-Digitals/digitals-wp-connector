<?php
/**
 * AdSpirit Connector — Operações do agente.
 *
 * Substitui o que a gente realmente usa do Agent Connector, sem carregar o que
 * ele tem de perigoso.
 *
 * O que aquele plugin oferece: shell-exec, process-exec, wp-cli, php-eval,
 * file-read/write/delete e gerador de link de login de administrador. Isso é
 * execução remota irrestrita. No nosso subdomínio tudo bem — é nosso servidor.
 * No site do cliente é uma porta que a gente não teria como fechar depois.
 *
 * Aqui a escolha é oposta: cada operação tem nome, escopo declarado e efeito
 * previsível. O agente não "roda um comando"; ele chama uma das operações
 * desta lista. Se precisar de algo novo, alguém escreve a operação e ela passa
 * por revisão — que é exatamente a barreira que um shell não tem.
 *
 * Toda operação exige AdSpirit_Ambiente::pode_operar_pelo_agente(): pessoa da
 * Digitals com permissão de administrar. E toda escrita fica registrada.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Agente {

    const OPTION_HISTORICO = 'adspirit_agente_historico';
    const CATEGORIA = 'adspirit';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('wp_abilities_api_categories_init', array($this, 'registrar_categoria'));
        add_action('abilities_api_init', array($this, 'registrar'));
        add_action('wp_abilities_api_init', array($this, 'registrar'));
    }

    public function registrar_categoria() {
        if (!function_exists('wp_register_ability_category')) return;
        wp_register_ability_category(self::CATEGORIA, array(
            'label' => 'AdSpirit',
            'description' => 'Diagnóstico e manutenção do site pelo AdSpirit.',
        ));
    }

    private function permissao() {
        return function () {
            if (class_exists('AdSpirit_Ambiente')) {
                return AdSpirit_Ambiente::pode_operar_pelo_agente();
            }
            return current_user_can('manage_options');
        };
    }

    private function meta($somente_leitura) {
        return array(
            'public' => true,
            'show_in_rest' => true,
            'mcp' => array('public' => true),
            'annotations' => array(
                'readonly' => (bool) $somente_leitura,
                'destructive' => false,
                'idempotent' => true,
            ),
        );
    }

    public function registrar() {
        if (!function_exists('wp_register_ability')) return;
        $perm = $this->permissao();

        wp_register_ability(self::CATEGORIA . '/diagnostico', array(
            'category' => self::CATEGORIA,
            'meta' => $this->meta(true),
            'label' => 'Diagnóstico do site',
            'description' => 'Retrato do site: versões, plugins ativos, estado da conexão com o AdSpirit, medição configurada e conflitos de pixel. Somente leitura.',
            'input_schema' => array(
                'type' => 'object',
                'properties' => new stdClass(),
                // Sem `default`, chamar sem parâmetro nenhum é recusado como
                // "input não é do tipo object". Operação sem argumento precisa
                // poder ser chamada sem argumento.
                'default' => new stdClass(),
            ),
            'execute_callback' => array($this, 'diagnostico'),
            'permission_callback' => $perm,
        ));

        wp_register_ability(self::CATEGORIA . '/verificar-pixel', array(
            'category' => self::CATEGORIA,
            'meta' => $this->meta(true),
            'label' => 'Verificar pixel duplicado',
            'description' => 'Roda a varredura de conflito de pixel na hora e devolve o relatório, incluindo em qual trecho de código está a cópia colada à mão. Somente leitura.',
            'input_schema' => array(
                'type' => 'object',
                'properties' => new stdClass(),
                // Sem `default`, chamar sem parâmetro nenhum é recusado como
                // "input não é do tipo object". Operação sem argumento precisa
                // poder ser chamada sem argumento.
                'default' => new stdClass(),
            ),
            'execute_callback' => array($this, 'verificar_pixel'),
            'permission_callback' => $perm,
        ));

        wp_register_ability(self::CATEGORIA . '/desligar-trecho-duplicado', array(
            'category' => self::CATEGORIA,
            'meta' => $this->meta(false),
            'label' => 'Desligar o trecho que duplica o pixel',
            'description' => 'Desativa o trecho de código que a última varredura apontou como cópia do pixel colada à mão. Só age sobre esse trecho — não aceita alvo arbitrário. Reversível pelo painel do gerenciador de trechos.',
            'input_schema' => array(
                'type' => 'object',
                'properties' => new stdClass(),
                // Sem `default`, chamar sem parâmetro nenhum é recusado como
                // "input não é do tipo object". Operação sem argumento precisa
                // poder ser chamada sem argumento.
                'default' => new stdClass(),
            ),
            'execute_callback' => array($this, 'desligar_trecho'),
            'permission_callback' => $perm,
        ));

        wp_register_ability(self::CATEGORIA . '/limpar-cache', array(
            'category' => self::CATEGORIA,
            'meta' => $this->meta(false),
            'label' => 'Limpar cache do site',
            'description' => 'Limpa cache de objeto e de página. Não altera conteúdo nem configuração.',
            'input_schema' => array(
                'type' => 'object',
                'properties' => new stdClass(),
                // Sem `default`, chamar sem parâmetro nenhum é recusado como
                // "input não é do tipo object". Operação sem argumento precisa
                // poder ser chamada sem argumento.
                'default' => new stdClass(),
            ),
            'execute_callback' => array($this, 'limpar_cache'),
            'permission_callback' => $perm,
        ));

        wp_register_ability(self::CATEGORIA . '/sincronizar-config', array(
            'category' => self::CATEGORIA,
            'meta' => $this->meta(false),
            'label' => 'Buscar configuração no AdSpirit',
            'description' => 'Força a busca da configuração de medição no AdSpirit. Em modo observação não escreve nada — só relata o que mudaria.',
            'input_schema' => array(
                'type' => 'object',
                'properties' => new stdClass(),
                // Sem `default`, chamar sem parâmetro nenhum é recusado como
                // "input não é do tipo object". Operação sem argumento precisa
                // poder ser chamada sem argumento.
                'default' => new stdClass(),
            ),
            'execute_callback' => array($this, 'sincronizar_config'),
            'permission_callback' => $perm,
        ));

        wp_register_ability(self::CATEGORIA . '/anti-spam', array(
            'category' => self::CATEGORIA,
            'meta' => $this->meta(false),
            'label' => 'Anti-spam: listas de bloqueio e proteção de volume',
            'description' => 'Lê e edita a defesa anti-spam deste site: listas de bloqueio (telefone, e-mail por regex, palavra) e o limite de envios por IP, que é o que segura ataque de volume. Sem `acao`, só devolve o estado atual. Existe porque isso não deveria exigir wp-admin — em vários sites ele está escondido por plugin de segurança, e a mesma pessoa costuma atacar mais de um site.',
            'input_schema' => array(
                'type' => 'object',
                'default' => new stdClass(),
                'properties' => array(
                    'acao' => array('type' => 'string', 'enum' => array('ler', 'adicionar', 'remover', 'proteger'), 'description' => 'Padrão: ler. `proteger` liga/desliga o limite por IP.'),
                    'tipo' => array('type' => 'string', 'enum' => array('telefone', 'email', 'palavra'), 'description' => 'Qual lista mexer.'),
                    'valor' => array('type' => 'string', 'description' => 'O que adicionar ou remover.'),
                    'limite_por_ip' => array('type' => 'integer', 'description' => 'Com acao=proteger: envios por minuto por IP. 0 desliga o limite.'),
                ),
            ),
            'execute_callback' => array($this, 'blocklist'),
            'permission_callback' => $perm,
        ));

        wp_register_ability(self::CATEGORIA . '/desempenho', array(
            'category' => self::CATEGORIA,
            'meta' => $this->meta(false),
            'label' => 'Desempenho da página',
            'description' => 'Lê e ajusta as otimizações de saída deste site: travar a geometria do herói pra não haver salto de layout e dispensar biblioteca de carrossel não usada. O adiamento do vídeo de fundo saiu na 2.79.0 — custava a entrada do herói e não movia a nota. Sem `acao`, só devolve o estado. Nada disso toca o conteúdo salvo do construtor — atua no HTML de saída, e desligar volta tudo ao original.',
            'input_schema' => array(
                'type' => 'object',
                'default' => new stdClass(),
                'properties' => array(
                    'acao' => array('type' => 'string', 'enum' => array('ler', 'ajustar'), 'description' => 'Padrão: ler.'),
                    'travar_hero' => array('type' => 'boolean'),
                    'dispensar_carrossel' => array('type' => 'boolean', 'description' => 'Deixa de carregar a biblioteca de carrossel em página que não tem carrossel. Só age com prova de não-uso.'),
                ),
            ),
            'execute_callback' => array($this, 'desempenho'),
            'permission_callback' => $perm,
        ));

        wp_register_ability(self::CATEGORIA . '/historico', array(
            'category' => self::CATEGORIA,
            'meta' => $this->meta(true),
            'label' => 'Histórico de operações do agente',
            'description' => 'O que o agente fez neste site, quem disparou e quando. Somente leitura.',
            'input_schema' => array(
                'type' => 'object',
                'properties' => new stdClass(),
                // Sem `default`, chamar sem parâmetro nenhum é recusado como
                // "input não é do tipo object". Operação sem argumento precisa
                // poder ser chamada sem argumento.
                'default' => new stdClass(),
            ),
            'execute_callback' => array($this, 'historico'),
            'permission_callback' => $perm,
        ));
    }

    // ─────────────────────────────────────────────────────────
    // Registro do que foi feito
    // ─────────────────────────────────────────────────────────

    /**
     * Toda escrita entra aqui. Sem isso, "o agente mexeu no site" vira uma
     * afirmação que ninguém consegue conferir depois.
     */
    private function registrar_acao($operacao, $detalhe) {
        $u = wp_get_current_user();
        $lista = get_option(self::OPTION_HISTORICO, array());
        if (!is_array($lista)) $lista = array();
        array_unshift($lista, array(
            'quando' => current_time('mysql'),
            'quem' => $u && $u->user_email ? $u->user_email : 'desconhecido',
            'operacao' => (string) $operacao,
            'detalhe' => (string) $detalhe,
        ));
        update_option(self::OPTION_HISTORICO, array_slice($lista, 0, 100), false);
    }

    public function historico() {
        $l = get_option(self::OPTION_HISTORICO, array());
        return array('acoes' => is_array($l) ? $l : array());
    }

    // ─────────────────────────────────────────────────────────
    // Operações
    // ─────────────────────────────────────────────────────────

    public function diagnostico() {
        $core = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();
        $plugins = array();
        if (function_exists('get_option')) {
            $ativos = (array) get_option('active_plugins', array());
            foreach ($ativos as $p) $plugins[] = $p;
        }

        return array(
            'site' => array(
                'url' => home_url('/'),
                'host' => class_exists('AdSpirit_Ambiente') ? AdSpirit_Ambiente::host() : '',
                'estudio' => class_exists('AdSpirit_Ambiente') ? AdSpirit_Ambiente::e_estudio() : null,
                'wp' => get_bloginfo('version'),
                'php' => PHP_VERSION,
            ),
            'connector' => array(
                'versao' => defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : '',
                'marca' => isset($core['brand_slug']) ? $core['brand_slug'] : '',
                'conectado' => class_exists('AdSpirit_Connect') ? AdSpirit_Connect::is_connected() : null,
                'modo_config' => class_exists('AdSpirit_Config_Sync') ? AdSpirit_Config_Sync::modo() : '',
            ),
            'medicao' => class_exists('AdSpirit_Pixel_Conflito') ? self::marcar_cegueira(AdSpirit_Pixel_Conflito::relatorio()) : array(),
            'plugins_ativos' => $plugins,
        );
    }

    public function verificar_pixel() {
        if (!class_exists('AdSpirit_Pixel_Conflito')) {
            return array('erro' => 'Módulo de conflito de pixel não está disponível.');
        }
        return self::marcar_cegueira(AdSpirit_Pixel_Conflito::instance()->verificar());
    }

    /**
     * Anexa ao relatório uma frase que quem lê NÃO pode ignorar.
     *
     * A varredura lê o HTML do servidor; Tag Manager e Site Kit injetam
     * depois, no navegador. Lista vazia significa "não vi daqui", não
     * "está desligado" — e essa diferença já custou um diagnóstico errado
     * (29/08: GA4 e Clarity dados como desligados num site que media tudo
     * pelo GTM). Campos crus podem ser lidos por olho apressado ou por
     * agente; a ressalva vai em texto, junto do dado.
     */
    /** Lê/ajusta as otimizações de saída (ver AdSpirit_Performance). */
    public function desempenho($input) {
        if (!class_exists('AdSpirit_Performance')) {
            return array('ok' => false, 'erro' => 'Módulo de desempenho não disponível nesta versão.');
        }
        $cfg = AdSpirit_Performance::config();
        $acao = isset($input['acao']) ? (string) $input['acao'] : 'ler';

        if ($acao !== 'ajustar') {
            return array('ok' => true, 'config' => $cfg);
        }

        $patch = array();
        foreach (array('travar_hero', 'dispensar_carrossel') as $k) {
            if (array_key_exists($k, $input)) $patch[$k] = !empty($input[$k]) ? '1' : '0';
        }
        if (!$patch) return array('ok' => false, 'erro' => 'Nada pra ajustar.');

        update_option(AdSpirit_Performance::OPTION, array_merge($cfg, $patch), false);
        if (has_action('litespeed_purge_all')) do_action('litespeed_purge_all');

        $this->registrar_acao('desempenho', wp_json_encode($patch));
        return array('ok' => true, 'config' => AdSpirit_Performance::config());
    }

    /** Nome da opção → rótulo, pra não repetir string solta. */
    private static function listas_de_bloqueio() {
        return array(
            'telefone' => array('chave' => 'blocklist_phones', 'rotulo' => 'telefones'),
            'email'    => array('chave' => 'blocklist_emails', 'rotulo' => 'e-mails'),
            'palavra'  => array('chave' => 'blocklist_words',  'rotulo' => 'palavras'),
        );
    }

    /**
     * Lê e edita a lista de bloqueio do anti-spam.
     *
     * Guarda uma linha por entrada (é o formato que o anti-spam já lê) e
     * deduplica sem mexer na ordem — quem abrir a tela depois encontra o que
     * espera. Adicionar o que já existe não é erro: é no-op, e o retorno diz
     * isso, pra quem chama não ficar em dúvida se funcionou.
     */
    public function blocklist($input) {
        if (!class_exists('AdSpirit_Settings')) {
            return array('ok' => false, 'erro' => 'Configurações não disponíveis.');
        }
        $listas = self::listas_de_bloqueio();
        $cfg = AdSpirit_Settings::get_antispam();

        $ler_tudo = function () use ($listas, &$cfg) {
            $out = array();
            foreach ($listas as $tipo => $meta) {
                $bruto = isset($cfg[$meta['chave']]) ? (string) $cfg[$meta['chave']] : '';
                $itens = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $bruto))));
                $out[$tipo] = $itens;
            }
            return $out;
        };

        $acao = isset($input['acao']) ? (string) $input['acao'] : 'ler';
        if ($acao === 'ler' || $acao === '') {
            return array(
                'ok' => true,
                'listas' => $ler_tudo(),
                'protecao_de_volume' => array(
                    'ligado' => ($cfg['rate_limit'] ?? '0') === '1',
                    'limite_por_ip_por_minuto' => (int) ($cfg['rate_limit_max'] ?? 0),
                ),
            );
        }

        // Limite por IP: a defesa contra volume. Vinha desligada por padrão,
        // o que deixa o site exposto a alguém que submete em looping — o
        // custo não é só lead sujo, é carga no servidor.
        if ($acao === 'proteger') {
            $limite = isset($input['limite_por_ip']) ? (int) $input['limite_por_ip'] : 5;
            if ($limite < 0) return array('ok' => false, 'erro' => 'limite_por_ip não pode ser negativo.');
            AdSpirit_Settings::update_antispam($limite === 0
                ? array('rate_limit' => '0')
                : array('rate_limit' => '1', 'rate_limit_max' => $limite));
            $cfg = AdSpirit_Settings::get_antispam();
            $this->registrar_acao('anti-spam', $limite === 0 ? 'limite por IP desligado' : "limite por IP: {$limite}/min");
            return array(
                'ok' => true,
                'mudou' => true,
                'limite_por_ip' => $limite === 0 ? 0 : (int) $cfg['rate_limit_max'],
                'ligado' => ($cfg['rate_limit'] ?? '0') === '1',
            );
        }

        $tipo = isset($input['tipo']) ? (string) $input['tipo'] : '';
        $valor = isset($input['valor']) ? trim((string) $input['valor']) : '';
        if (!isset($listas[$tipo])) {
            return array('ok' => false, 'erro' => 'tipo deve ser telefone, email ou palavra.');
        }
        if ($valor === '') {
            return array('ok' => false, 'erro' => 'valor vazio.');
        }

        $chave = $listas[$tipo]['chave'];
        $atual = isset($cfg[$chave]) ? (string) $cfg[$chave] : '';
        $itens = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $atual))));

        // Telefone é comparado por dígitos no anti-spam; guardar normalizado
        // evita duas linhas ("11 96043-9444" e "11960439444") pra mesma pessoa.
        $comparavel = ($tipo === 'telefone')
            ? preg_replace('/\D+/', '', $valor)
            : strtolower($valor);
        if ($tipo === 'telefone') {
            if (strlen($comparavel) < 8) {
                return array('ok' => false, 'erro' => 'Telefone precisa de ao menos 8 dígitos.');
            }
            $valor = $comparavel;
        }

        $existe = false;
        foreach ($itens as $i) {
            $c = ($tipo === 'telefone') ? preg_replace('/\D+/', '', $i) : strtolower($i);
            if ($c === $comparavel) { $existe = true; break; }
        }

        if ($acao === 'adicionar') {
            if ($existe) {
                return array('ok' => true, 'mudou' => false, 'nota' => 'Já estava na lista.', 'listas' => $ler_tudo());
            }
            $itens[] = $valor;
        } elseif ($acao === 'remover') {
            if (!$existe) {
                return array('ok' => true, 'mudou' => false, 'nota' => 'Não estava na lista.', 'listas' => $ler_tudo());
            }
            $itens = array_values(array_filter($itens, function ($i) use ($tipo, $comparavel) {
                $c = ($tipo === 'telefone') ? preg_replace('/\D+/', '', $i) : strtolower($i);
                return $c !== $comparavel;
            }));
        } else {
            return array('ok' => false, 'erro' => 'acao deve ser ler, adicionar ou remover.');
        }

        AdSpirit_Settings::update_antispam(array($chave => implode("\n", $itens)));
        $cfg = AdSpirit_Settings::get_antispam();

        $this->registrar_acao('anti-spam', $acao . ' ' . $listas[$tipo]['rotulo'] . ': ' . $valor);
        return array('ok' => true, 'mudou' => true, 'listas' => $ler_tudo());
    }

    private static function marcar_cegueira($relatorio) {
        if (!is_array($relatorio) || empty($relatorio['varredura_cega'])) return $relatorio;
        $fontes = !empty($relatorio['cega_por']) && is_array($relatorio['cega_por'])
            ? implode(', ', $relatorio['cega_por'])
            : 'Tag Manager';
        $relatorio['ATENCAO'] = 'Esta varredura lê o HTML do servidor e NÃO enxerga o que ' . $fontes
            . ' injeta pelo navegador. Portanto lista vazia (ga4_na_pagina, clarity_na_pagina, etc.) '
            . 'significa INDETERMINADO, nunca "desligado". Para afirmar que um canal está desligado, '
            . 'é preciso abrir a página num navegador de verdade ou conferir dentro do Tag Manager.';
        return $relatorio;
    }

    public function desligar_trecho() {
        if (!class_exists('AdSpirit_Pixel_Conflito')) {
            return array('ok' => false, 'erro' => 'Módulo de conflito de pixel não está disponível.');
        }
        $r = AdSpirit_Pixel_Conflito::relatorio();
        $alvo = isset($r['trecho_pra_desligar']) ? $r['trecho_pra_desligar'] : null;
        if (!is_array($alvo) || empty($alvo['id'])) {
            return array('ok' => false, 'erro' => 'Nenhum trecho identificado. Rode a verificação antes.');
        }

        global $wpdb;
        $feito = $wpdb->update(
            $alvo['tabela'],
            array($alvo['coluna_ativo'] => 0),
            array('id' => (int) $alvo['id']),
            array('%d'), array('%d')
        );
        if ($feito === false) {
            return array('ok' => false, 'erro' => 'Não consegui desativar o trecho.');
        }

        wp_cache_flush();
        do_action('litespeed_purge_all');
        $this->registrar_acao('desligar-trecho-duplicado',
            sprintf('%s: "%s" (id %d)', $alvo['origem'], $alvo['nome'], (int) $alvo['id']));

        return array(
            'ok' => true,
            'desligado' => $alvo['nome'],
            'onde' => $alvo['origem'],
            'como_reverter' => 'Reative o trecho pelo painel do ' . $alvo['origem'] . '.',
            'relatorio' => AdSpirit_Pixel_Conflito::instance()->verificar(),
        );
    }

    public function limpar_cache() {
        wp_cache_flush();
        do_action('litespeed_purge_all');
        if (function_exists('rocket_clean_domain')) rocket_clean_domain();
        $this->registrar_acao('limpar-cache', 'cache de objeto e de página');
        return array('ok' => true);
    }

    public function sincronizar_config() {
        if (!class_exists('AdSpirit_Config_Sync')) {
            return array('ok' => false, 'erro' => 'Módulo de configuração não está disponível.');
        }
        $r = AdSpirit_Config_Sync::instance()->buscar(true);
        $modo = AdSpirit_Config_Sync::modo();
        if ($modo === 'aplicando') {
            $this->registrar_acao('sincronizar-config', 'resultado: ' . var_export($r, true));
        }
        return array(
            'ok' => (bool) $r,
            'resultado' => $r,
            'modo' => $modo,
            'comparacao' => AdSpirit_Config_Sync::comparacao(),
            'erro' => AdSpirit_Config_Sync::erro(),
        );
    }
}
