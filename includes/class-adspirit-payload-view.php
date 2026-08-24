<?php
/**
 * AdSpirit Connector — leitura humana do payload de uma submissão.
 *
 * A aba Submissões guardava o payload e mostrava só o JSON cru. Serve pra
 * depurar, não pra ler: quem quer saber "o que essa pessoa respondeu" tinha
 * que garimpar chave por chave no meio de telemetria, UTM e versão do PHP.
 *
 * Esta classe traduz o payload em três blocos, do mais humano ao mais
 * técnico:
 *
 *   respostas — o que a pessoa preencheu, com o rótulo do formulário
 *   origem    — de onde ela veio (campanha, página, dispositivo, tempo)
 *   técnico   — o resto, pra diagnóstico
 *
 * O JSON continua disponível (regra do Pedro: não matar o JSON, deixar como
 * visão avançada). Aqui ele só deixa de ser a ÚNICA visão.
 *
 * Só leitura: nenhum método muda payload, option ou linha de banco.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Payload_View {

    /**
     * Chaves que NÃO são resposta da pessoa — carimbo do sistema. Ficam no
     * bloco técnico. Prefixos `_adspirit_` e `_wpcf7` entram por regra, sem
     * precisar listar um a um.
     */
    private static function is_technical($key) {
        $k = (string) $key;
        if (strpos($k, '_adspirit_') === 0) return true;
        if (strpos($k, '_wpcf7') === 0) return true;
        $fixed = array(
            'cf7_time', 'cf7_url', 'submission_id', 'form_id', 'form_kind',
            'brand_slug', 'source', 'nonce', 'client_ip', 'user_agent',
        );
        return in_array($k, $fixed, true);
    }

    /**
     * Rótulo humano de uma chave, em cascata do mais específico pro mais
     * genérico:
     *
     *   1. label do formulário nativo que originou a submissão
     *   2. title da etapa no roteiro custom do qualifier
     *   3. dicionário canônico (Configurações → mapeamento, sincronizado
     *      com o CRM — o time edita lá e reflete aqui)
     *   4. dicionário embutido das chaves do roteiro padrão da Digitals
     *   5. a própria chave, só que legível
     */
    public static function labels_for_payload(array $payload) {
        $labels = array();

        // (4) base: chaves do roteiro padrão que não estão no canônico.
        $labels = array(
            'your-name'               => 'Nome',
            'your-email'              => 'Email',
            'Telefone'                => 'Telefone',
            'instagram'               => 'Instagram',
            'site-empresa'            => 'Site ou perfil',
            'empresa'                 => 'Empresa',
            'cargo'                   => 'Cargo',
            'Numero-funcionarios'     => 'Tamanho da empresa',
            'nicho'                   => 'Setor de atuação',
            'ExperienciacomMarketing' => 'Experiência com marketing',
            'revenue'                 => 'Faturamento mensal',
            'Investimento'            => 'Investimento em tráfego',
            'urgencia'                => 'Quando pretende começar',
            'pain'                    => 'O que motivou a busca',
            'message'                 => 'Mensagem',
            'mensagem'                => 'Mensagem',
            'notes'                   => 'Observações',
        );

        // (3) canônico do tenant vence a base — é o que o time editou.
        if (class_exists('AdSpirit_Settings') && method_exists('AdSpirit_Settings', 'canonical_fields')) {
            foreach ((array) AdSpirit_Settings::canonical_fields() as $k => $v) {
                if ($v !== '' && $v !== $k) $labels[$k] = (string) $v;
            }
        }

        // (2) roteiro custom do qualifier: o title da etapa É a pergunta.
        if (class_exists('AdSpirit_Form_Qualifier') && method_exists('AdSpirit_Form_Qualifier', 'get_steps')) {
            foreach ((array) AdSpirit_Form_Qualifier::get_steps() as $step) {
                if (!is_array($step) || empty($step['title'])) continue;
                $title = (string) $step['title'];
                if (!empty($step['fieldKey'])) {
                    $key = !empty($step['canonical']) ? $step['canonical'] : $step['fieldKey'];
                    $labels[(string) $key] = $title;
                }
                $fields = isset($step['fields']) && is_array($step['fields']) ? $step['fields'] : array();
                foreach ($fields as $f) {
                    if (empty($f['key'])) continue;
                    $key = !empty($f['canonical']) ? $f['canonical'] : $f['key'];
                    // Etapa com vários campos: o title é da etapa toda ("Seu
                    // nome" pra nome+sobrenome). O placeholder distingue.
                    $labels[(string) $key] = (count($fields) > 1 && !empty($f['placeholder']))
                        ? (string) $f['placeholder']
                        : $title;
                }
            }
        }

        // (1) form nativo que originou esta submissão — o mais específico.
        $form_id = '';
        foreach (array('_adspirit_form_id', 'form_id') as $k) {
            if (!empty($payload[$k])) { $form_id = (string) $payload[$k]; break; }
        }
        if ($form_id !== '' && class_exists('AdSpirit_Form') && method_exists('AdSpirit_Form', 'get_forms')) {
            $forms = (array) AdSpirit_Form::get_forms();
            if (isset($forms[$form_id]) && is_array($forms[$form_id])) {
                foreach ((array) ($forms[$form_id]['steps'] ?? array()) as $step) {
                    foreach ((array) ($step['fields'] ?? array()) as $f) {
                        if (empty($f['name']) || empty($f['label'])) continue;
                        $labels[(string) $f['name']] = (string) $f['label'];
                    }
                }
            }
        }

        return $labels;
    }

    /**
     * Rótulos do bloco técnico. Sem isto, `cf7_time` viraria "Cf7 time" —
     * legível, mas não explica o que é.
     */
    private static function technical_label($key) {
        $map = array(
            'cf7_time'                 => 'Enviado em',
            'cf7_url'                  => 'Página do envio',
            'submission_id'            => 'ID da submissão',
            '_adspirit_form_kind'      => 'Tipo de formulário',
            '_adspirit_form_id'        => 'Formulário',
            '_adspirit_partial'        => 'Envio parcial',
            '_adspirit_step_durations' => 'Tempo por etapa',
            'client_ip'                => 'IP',
            'user_agent'               => 'Navegador (bruto)',
            'brand_slug'               => 'Marca',
            'source'                   => 'Origem do registro',
        );
        return isset($map[$key]) ? $map[$key] : self::humanize_key($key);
    }

    /**
     * Identidade do formulário: QUAL formulário e em QUE motor.
     *
     * A coluna Origem mostrava a chave interna — "form", "qualifier",
     * "cf7". Quem opera não tem como saber o que isso quer dizer, e dois
     * formulários diferentes do mesmo motor ficavam indistinguíveis. Aqui
     * `source` (o motor) e `form_id` (qual deles) viram nome de gente.
     *
     * @return array{form:string, engine:string} form pode vir vazio quando
     *         o motor não expõe título — aí a coluna mostra só o motor.
     */
    public static function form_identity($source, $form_id = '') {
        $src = strtolower(trim((string) $source));
        $fid = trim((string) $form_id);

        $engines = array(
            'cf7'               => 'Contact Form 7',
            'native'            => 'AdSpirit',
            'qualifier'         => 'AdSpirit',
            'qualifier_partial' => 'AdSpirit',
            'gravity'           => 'Gravity Forms',
            'wpforms'           => 'WPForms',
            'elementor'         => 'Elementor',
            'fluent'            => 'Fluent Forms',
            'woocommerce'       => 'WooCommerce',
            'generic'           => 'Detector automático',
        );
        $engine = isset($engines[$src]) ? $engines[$src] : self::humanize_key($src);

        $form = '';
        if ($src === 'qualifier' || $src === 'qualifier_partial') {
            // O roteiro custom pode ter nome próprio; senão é o de avaliação.
            $form = 'Avaliação de novos clientes';
            if (class_exists('AdSpirit_Form_Qualifier') && method_exists('AdSpirit_Form_Qualifier', 'get_steps')) {
                foreach ((array) AdSpirit_Form_Qualifier::get_steps() as $st) {
                    if (!empty($st['isIntro']) && !empty($st['title'])) { $form = (string) $st['title']; break; }
                }
            }
            if ($src === 'qualifier_partial') $form .= ' (parcial)';
        } elseif ($src === 'native' && $fid !== '') {
            $forms = (class_exists('AdSpirit_Form') && method_exists('AdSpirit_Form', 'get_forms'))
                ? (array) AdSpirit_Form::get_forms() : array();
            $form = (isset($forms[$fid]['title']) && $forms[$fid]['title'] !== '')
                ? (string) $forms[$fid]['title'] : $fid;
        } elseif ($src === 'cf7' && $fid !== '' && function_exists('get_the_title')) {
            // Form do CF7 é um post; o título é o nome que o time deu.
            $t = (string) get_the_title((int) $fid);
            $form = $t !== '' ? $t : 'Formulário #' . $fid;
        } elseif ($src === 'woocommerce') {
            $form = $fid !== '' ? self::humanize_key(preg_replace('/^woo-/', '', $fid)) : 'Pedido';
        } elseif ($fid !== '') {
            // Gravity/WPForms/Elementor/Fluent expõem o título por APIs
            // próprias; sem carregar cada plugin, o número já distingue.
            $form = ctype_digit($fid) ? 'Formulário #' . $fid : self::humanize_key($fid);
        }

        return array('form' => $form, 'engine' => $engine);
    }

    /** (5) Fallback: `Numero-funcionarios` → "Numero funcionarios". */
    public static function humanize_key($key) {
        $s = str_replace(array('-', '_'), ' ', (string) $key);
        $s = trim(preg_replace('/\s+/', ' ', $s));
        if ($s === '') return (string) $key;
        return function_exists('mb_strtoupper')
            ? mb_strtoupper(mb_substr($s, 0, 1)) . mb_substr($s, 1)
            : ucfirst($s);
    }

    /** Valor legível: array vira lista, vazio vira string vazia. */
    private static function scalar($value) {
        if (is_array($value)) {
            $flat = array();
            foreach ($value as $k => $v) {
                if (is_array($v)) $v = wp_json_encode($v, JSON_UNESCAPED_UNICODE);
                if ($v === '' || $v === null) continue;
                $flat[] = (is_int($k) ? '' : $k . ': ') . (string) $v;
            }
            return implode(' · ', $flat);
        }
        if (is_bool($value)) return $value ? 'sim' : 'não';
        return (string) ($value === null ? '' : $value);
    }

    /**
     * Bloco "origem": o que explica de onde veio o lead. Sai da telemetria,
     * que é um array grande demais pra jogar inteiro na tela.
     */
    private static function origin_rows(array $payload) {
        $t = isset($payload['_adspirit_telemetry']) && is_array($payload['_adspirit_telemetry'])
            ? $payload['_adspirit_telemetry'] : array();
        if (empty($t)) return array();

        $rows = array();
        $push = function ($label, $value) use (&$rows) {
            $v = self::scalar($value);
            if ($v !== '') $rows[] = array('label' => $label, 'value' => $v);
        };

        // Campanha: utm_last é o que atribuiu a conversão; utm_first, a
        // descoberta. Mostra os dois quando diferem.
        $fmt_utm = function ($u) {
            if (!is_array($u)) return '';
            $parts = array();
            foreach (array('source', 'medium', 'campaign', 'content', 'term') as $k) {
                if (!empty($u[$k])) $parts[] = (string) $u[$k];
            }
            return implode(' / ', $parts);
        };
        $last  = $fmt_utm(isset($t['utm_last']) ? $t['utm_last'] : null);
        $first = $fmt_utm(isset($t['utm_first']) ? $t['utm_first'] : null);
        $push('Campanha', $last !== '' ? $last : $first);
        if ($first !== '' && $last !== '' && $first !== $last) {
            $push('Primeiro contato', $first);
        }

        $push('Página de entrada', isset($t['landing_page']) ? $t['landing_page'] : '');
        $push('Página onde converteu', isset($t['conversion_page']) ? $t['conversion_page'] : '');
        $push('Veio de', isset($t['referrer']) ? $t['referrer'] : '');

        // Ciclo de decisão: quanto tempo entre conhecer o site e converter.
        // Já estava no payload (first_seen_at) e nunca aparecia — é a
        // diferença entre um lead que decidiu na hora e um que amadureceu.
        $push('Conheceu o site', self::decision_cycle($t));

        // Click id: prova de mídia paga. Mostra qual plataforma, não o valor
        // (que é um hash sem serventia pra quem lê).
        $clicks = array('gclid' => 'Google Ads', 'fbclid' => 'Meta Ads', 'ttclid' => 'TikTok Ads',
                        'li_fat_id' => 'LinkedIn Ads', 'gbraid' => 'Google Ads', 'wbraid' => 'Google Ads');
        $paid = array();
        foreach ($clicks as $k => $plat) {
            if (!empty($t[$k]) && !in_array($plat, $paid, true)) $paid[] = $plat;
        }
        if ($paid) $push('Clique pago de', implode(', ', $paid));

        $device = array();
        foreach (array('ua_device', 'ua_os', 'ua_browser') as $k) {
            if (!empty($t[$k])) $device[] = (string) $t[$k];
        }
        if ($device) $push('Dispositivo', implode(' · ', $device));

        // Tempo: sinal de qualidade (preenchimento de 4s é robô).
        if (!empty($t['time_in_form_ms'])) {
            $push('Tempo preenchendo', self::duration((int) $t['time_in_form_ms']));
        }
        if (!empty($t['pages_in_session'])) {
            $n = (int) $t['pages_in_session'];
            $push('Páginas na visita', $n . ($n === 1 ? ' página' : ' páginas'));
        }

        return $rows;
    }

    /**
     * "na mesma visita" | "há 6 dias" — distância entre a primeira vez que
     * o visitante foi visto e a conversão. Lead que decidiu na hora e lead
     * que amadureceu duas semanas pedem abordagens diferentes.
     */
    private static function decision_cycle(array $t) {
        $first = isset($t['first_seen_at']) ? trim((string) $t['first_seen_at']) : '';
        if ($first === '') return '';
        $ts = strtotime($first);
        if (!$ts) return '';
        $end = isset($t['last_seen_at']) ? strtotime((string) $t['last_seen_at']) : 0;
        if (!$end) $end = time();
        $days = (int) floor(($end - $ts) / 86400);
        if ($days <= 0) return 'na mesma visita';
        if ($days === 1) return 'no dia anterior';
        return 'há ' . $days . ' dias';
    }

    /**
     * Bloco "Comportamento": o que a pessoa fez na página antes de enviar.
     * Vem do tracker (behavior_v1) e nunca era exibido. Só entram sinais
     * ACIONÁVEIS — nada de despejar o objeto inteiro na tela:
     *
     *   rolagem      leu a página ou converteu no topo
     *   rage_clicks  clicou repetido no mesmo ponto = algo travou (bug)
     *   tab_switches saiu e voltou = comparou, pesquisou
     *   copiou       copiou conteúdo = interesse alto
     */
    private static function behavior_rows(array $payload) {
        $t = isset($payload['_adspirit_telemetry']) && is_array($payload['_adspirit_telemetry'])
            ? $payload['_adspirit_telemetry'] : array();
        $b = isset($t['behavior_v1']) && is_array($t['behavior_v1']) ? $t['behavior_v1'] : array();
        if (empty($b)) return array();

        $rows = array();
        $push = function ($label, $value) use (&$rows) {
            if ($value !== '' && $value !== null) $rows[] = array('label' => $label, 'value' => $value);
        };

        if (isset($b['scroll_max_pct']) && (int) $b['scroll_max_pct'] > 0) {
            $pct = (int) $b['scroll_max_pct'];
            $nota = $pct >= 90 ? ' (leu até o fim)' : ($pct <= 25 ? ' (só o topo)' : '');
            $push('Rolagem da página', $pct . '%' . $nota);
        }
        if (!empty($b['time_active_ms'])) {
            $push('Tempo ativo na página', self::duration((int) $b['time_active_ms']));
        }
        if (!empty($b['rage_clicks'])) {
            $n = (int) $b['rage_clicks'];
            $push('Cliques de frustração', $n . ($n === 1 ? ' vez' : ' vezes') . ' — algo pode ter travado');
        }
        if (!empty($b['tab_switches'])) {
            $n = (int) $b['tab_switches'];
            $push('Saiu e voltou', $n . ($n === 1 ? ' vez' : ' vezes'));
        }
        if (!empty($b['copy_events']['count'])) {
            $n = (int) $b['copy_events']['count'];
            $push('Copiou conteúdo', $n . ($n === 1 ? ' vez' : ' vezes'));
        }
        if (!empty($b['exit_intent'])) {
            $push('Tentou sair antes de enviar', 'sim');
        }
        if (!empty($b['viewport']['class'])) {
            $push('Tela', (string) $b['viewport']['class']);
        }
        return $rows;
    }

    /** 92000 → "1min 32s"; 4200 → "4s". */
    public static function duration($ms) {
        $s = (int) round(max(0, (int) $ms) / 1000);
        if ($s < 60) return $s . 's';
        $m = intdiv($s, 60);
        $r = $s % 60;
        return $r ? $m . 'min ' . $r . 's' : $m . 'min';
    }

    /**
     * Divide o payload nos três blocos. Preserva a ordem do payload nas
     * respostas — é a ordem em que o formulário foi montado, que é a ordem
     * em que a pessoa respondeu.
     */
    public static function sections(array $payload) {
        $labels = self::labels_for_payload($payload);
        $respostas = array();
        $tecnico   = array();

        foreach ($payload as $key => $value) {
            $row = array(
                'key'   => (string) $key,
                'label' => isset($labels[$key]) ? $labels[$key] : self::humanize_key($key),
                'value' => self::scalar($value),
            );
            if (self::is_technical($key)) {
                // Telemetria vira o bloco "origem"; o array cru não ajuda.
                if ($key === '_adspirit_telemetry') continue;
                $row['label'] = self::technical_label((string) $key);
                // Motor do formulário também em nome de gente aqui dentro —
                // "qualifier" cru não diz nada, igual dizia na coluna Origem.
                if ($key === '_adspirit_form_kind' && $row['value'] !== '') {
                    $id = self::form_identity($row['value']);
                    $row['value'] = $id['engine'];
                }
                $tecnico[] = $row;
                continue;
            }
            $respostas[] = $row;
        }

        return array(
            'respostas'    => $respostas,
            'origem'       => self::origin_rows($payload),
            'comportamento'=> self::behavior_rows($payload),
            'tecnico'      => $tecnico,
        );
    }

    /** Uma coluna do painel: eyebrow + pares rótulo/valor empilhados. */
    private static function column($title, array $rows, $tone = '') {
        if (empty($rows)) return '';
        ob_start();
        ?>
        <div class="as-detail-col<?php echo $tone !== '' ? ' ' . esc_attr($tone) : ''; ?>">
            <h4 class="as-detail-eyebrow"><?php echo esc_html($title); ?></h4>
            <dl class="as-detail-list">
                <?php foreach ($rows as $row) : ?>
                    <div class="as-detail-item">
                        <dt<?php echo !empty($row['key']) ? ' title="' . esc_attr($row['key']) . '"' : ''; ?>><?php
                            echo esc_html($row['label']);
                        ?></dt>
                        <dd<?php echo ($row['value'] === '' ? ' class="empty"' : ''); ?>><?php
                            echo $row['value'] === '' ? '—' : esc_html($row['value']);
                        ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /**
     * Painel de detalhe de uma submissão, em largura total.
     *
     * Não cabe dentro da célula de Status (90px) — pares rótulo/valor
     * espremidos ali espremem a tabela toda. Aqui o painel abre embaixo da
     * linha, ocupando a largura inteira, com as colunas lado a lado.
     *
     * Ordem = hierarquia: Respostas primeiro e mais larga (é o que se quer
     * ler), Origem depois, e o diagnóstico por último, apagado. Separação
     * por espaço, não por caixa — uma superfície só pro painel inteiro.
     *
     * @param array $payload      payload da submissão
     * @param array $extra        blocos do chamador: [['title'=>, 'rows'=>[['label','value']], 'tone'=>'muted']]
     * @param bool  $with_json    inclui o JSON cru colapsado ao final
     */
    public static function render_panel(array $payload, array $extra = array(), $with_json = true) {
        $s = self::sections($payload);

        // Diagnóstico e técnico moram na mesma coluna: são a mesma pergunta
        // ("por que este lead está assim?"), e juntos liberam largura pro
        // que a pessoa realmente lê.
        $diag = '';
        foreach ($extra as $blk) {
            if (empty($blk['rows'])) continue;
            $diag .= self::column(
                isset($blk['title']) ? $blk['title'] : 'Diagnóstico',
                $blk['rows'],
                isset($blk['tone']) ? $blk['tone'] : 'muted'
            );
        }
        $diag .= self::column('Técnico', $s['tecnico'], 'muted');

        // Origem e Comportamento respondem a mesma pergunta — "como essa
        // pessoa chegou e o que ela fez" — então dividem a coluna do meio.
        $meio  = self::column('Origem', $s['origem']);
        $meio .= self::column('Comportamento', $s['comportamento']);

        ob_start();
        ?>
        <div class="as-detail">
            <div class="as-detail-grid">
                <?php
                echo self::column('Respostas', $s['respostas'], 'as-detail-col--wide');
                echo $meio !== '' ? '<div class="as-detail-col-group">' . $meio . '</div>' : '';
                echo $diag !== '' ? '<div class="as-detail-col-group">' . $diag . '</div>' : '';
                ?>
            </div>
            <?php if ($with_json && !empty($payload)) : ?>
                <details class="as-detail-json">
                    <summary>Ver JSON</summary>
                    <pre><?php echo esc_html((string) wp_json_encode(
                        $payload,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                    )); ?></pre>
                </details>
            <?php endif; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
