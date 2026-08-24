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
                $tecnico[] = $row;
                continue;
            }
            $respostas[] = $row;
        }

        return array(
            'respostas' => $respostas,
            'origem'    => self::origin_rows($payload),
            'tecnico'   => $tecnico,
        );
    }

    /**
     * HTML da leitura humana. Fica dentro do <details> "dados enviados" da
     * aba Submissões, antes do JSON.
     */
    public static function render(array $payload) {
        $s = self::sections($payload);
        if (empty($s['respostas']) && empty($s['origem']) && empty($s['tecnico'])) return '';

        ob_start();
        ?>
        <div class="as-payload">
            <?php
            $blocks = array(
                array('Respostas', $s['respostas']),
                array('Origem',    $s['origem']),
                array('Técnico',   $s['tecnico']),
            );
            foreach ($blocks as $b) :
                list($title, $rows) = $b;
                if (empty($rows)) continue;
            ?>
                <div class="as-payload-block">
                    <div class="as-payload-title"><?php echo esc_html($title); ?></div>
                    <dl class="as-payload-list">
                        <?php foreach ($rows as $row) : ?>
                            <dt title="<?php echo esc_attr($row['key'] ?? ''); ?>"><?php echo esc_html($row['label']); ?></dt>
                            <dd<?php echo $row['value'] === '' ? ' class="empty"' : ''; ?>><?php
                                echo $row['value'] === '' ? '—' : esc_html($row['value']);
                            ?></dd>
                        <?php endforeach; ?>
                    </dl>
                </div>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }
}
