<?php
/**
 * AdSpirit Connector — o que o plugin faz neste site, e se está funcionando.
 *
 * A tela de conexão listava quatro chaves numa linha chamada "Extras
 * opcionais" — nome que contradizia o conteúdo, já que duas delas são o
 * plugin em si. Pior: dizia se a chave estava LIGADA, nunca se estava
 * FUNCIONANDO. Ligado sem lead chegando parece igual a ligado com tudo
 * certo.
 *
 * Aqui cada recurso responde três coisas:
 *   1. está ligado?
 *   2. há prova recente de que funciona?
 *   3. se não há, o que fazer a respeito?
 *
 * REGRA: quando não dá pra provar, o estado é "sem sinal ainda" — nunca um
 * verde que não podemos garantir. Um "tudo certo" falso é pior que um
 * "não sei", porque ninguém vai investigar.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Recursos {

    // Estados possíveis, do melhor pro pior.
    const OK      = 'ok';      // ligado e com prova recente
    const ESPERA  = 'espera';  // ligado, ainda sem prova
    const ATENCAO = 'atencao'; // ligado, mas algo está falhando
    const OFF     = 'off';     // desligado por escolha

    /** Janela de "recente" pra considerar que há prova de funcionamento. */
    const JANELA_DIAS = 30;

    /**
     * De onde veio cada resposta. Dizer a procedência é o que separa um
     * painel de diagnóstico de um painel decorativo: quem lê precisa saber
     * se o número é medição deste site, resposta do CRM ou varredura de
     * página — porque cada um envelhece de um jeito.
     */
    const FONTE_TABELA  = 'tabela de leads deste site';
    const FONTE_CONFIG  = 'configuração do plugin';
    const FONTE_ARQUIVO = 'arquivo servido pelo AdSpirit';

    /**
     * Conta linhas da tabela de submissões por condição, na janela.
     * Devolve null quando a tabela não existe — o chamador distingue
     * "zero leads" de "não dá pra saber".
     */
    private static function contar($where_extra = '', $args = array()) {
        if (!class_exists('AdSpirit_Lead_Store') || !AdSpirit_Lead_Store::available()) return null;
        global $wpdb;
        $table = AdSpirit_Lead_Store::table_name();
        $desde = gmdate('Y-m-d H:i:s', time() - self::JANELA_DIAS * DAY_IN_SECONDS);
        $sql = "SELECT COUNT(*) FROM {$table} WHERE created_at >= %s" . $where_extra;
        $params = array_merge(array($desde), $args);
        return (int) $wpdb->get_var($wpdb->prepare($sql, $params));
    }

    /**
     * Estado dos quatro recursos, na ordem em que importam.
     *
     * @return array<int, array{
     *   key:string, titulo:string, essencial:bool, ligado:bool,
     *   estado:string, resumo:string, o_que_faz:string, sub:bool
     * }>
     */
    public static function todos() {
        $s = class_exists('AdSpirit_Settings') ? AdSpirit_Settings::get_core() : array();
        $conectado = !empty($s['brand_slug']) && !empty($s['secret']) && !empty($s['endpoint_url']);

        // Leitura TOLERANTE. O valor pode ter sido gravado como '1', 1,
        // true ou 'on' dependendo de por onde passou (handler, conexão
        // automática, import de config antiga). Comparar com === '1'
        // transformava qualquer variação em "desligado" — e uma chave
        // ligada exibida como desligada é o pior tipo de erro num painel
        // de diagnóstico: manda investigar o que não está quebrado.
        $lig = function ($valor, $padrao = '1') {
            $v = $valor === null ? $padrao : $valor;
            if (is_bool($v)) return $v;
            return in_array(strtolower((string) $v), array('1', 'on', 'true', 'yes', 'sim'), true);
        };

        $envio_on   = $lig($s['cf7_enabled'] ?? null);
        $generic_on = $lig($s['generic_forms_enabled'] ?? null);
        $pixel_on   = $lig($s['pixel_enabled'] ?? null);
        $fp_on      = $lig($s['pixel_firstparty'] ?? null);

        return array(
            self::envio($envio_on, $conectado),
            self::outros_plugins($generic_on, $envio_on),
            self::medicao($pixel_on, $conectado),
            self::primeira_parte($fp_on, $pixel_on),
        );
    }

    /** 1. Entregar os leads. É o plugin. */
    private static function envio($ligado, $conectado) {
        $r = array(
            'key' => 'cf7_enabled',
            'titulo' => 'Entregar os leads no AdSpirit',
            'o_que_faz' => 'Cada formulário enviado no site vira lead no AdSpirit na hora.',
            'essencial' => true, 'sub' => false, 'ligado' => (bool) $ligado,
            'fonte' => self::FONTE_TABELA,
            'conexao' => $conectado ? 'Site conectado ao AdSpirit' : 'Site ainda não conectado',
            'conexao_ok' => (bool) $conectado,
        );
        if (!$ligado) {
            // ESTADO IMPOSSÍVEL: a chave diz desligada, mas há lead entregue
            // recentemente — e lead só é entregue com ela ligada. Quando os
            // dois se contradizem, o fato ganha da configuração, porque o
            // fato aconteceu. Dizer "desligado" aqui mandaria alguém
            // investigar o que não está quebrado.
            $entregues_off = self::contar(" AND status = %s", array('sent'));
            if ($entregues_off !== null && $entregues_off > 0) {
                $r['estado'] = self::ATENCAO;
                $r['ligado'] = false;
                $r['metrica'] = array('valor' => $entregues_off,
                    'rotulo' => 'lead' . ($entregues_off === 1 ? '' : 's') . ' entregue' . ($entregues_off === 1 ? '' : 's') . ' em ' . self::JANELA_DIAS . ' dias');
                $r['resumo'] = 'A chave está desmarcada, mas houve entrega recente — então ela estava '
                    . 'ligada até pouco tempo atrás. Marque de novo abaixo pra não parar de entregar.';
                return $r;
            }
            $r['estado'] = self::OFF;
            $r['resumo'] = 'Desligado. O site guarda os envios aqui, mas nada chega no AdSpirit.';
            return $r;
        }
        if (!$conectado) {
            $r['estado'] = self::ATENCAO;
            $r['resumo'] = 'Ligado, mas o site ainda não está conectado — preencha os dados abaixo.';
            return $r;
        }
        $entregues = self::contar(" AND status = %s", array('sent'));
        $presos    = self::contar(" AND status IN ('pending','failed')");
        if ($entregues === null) {
            $r['estado'] = self::ESPERA;
            $r['resumo'] = 'Ligado. Ainda não consigo confirmar entregas neste site.';
            return $r;
        }
        if ($presos > 0) {
            $r['estado'] = self::ATENCAO;
            $r['metrica'] = array('valor' => $presos, 'rotulo' => 'lead' . ($presos === 1 ? '' : 's') . ' sem chegar no AdSpirit');
            $r['resumo'] = sprintf('%d entregue%s em %d dias. Os presos podem ser reenviados.',
                $entregues, $entregues === 1 ? '' : 's', self::JANELA_DIAS);
            $r['acao'] = array('rotulo' => 'Ver e reenviar', 'tab' => 'submissions');
            return $r;
        }
        if ($entregues === 0) {
            $r['estado'] = self::ESPERA;
            $r['resumo'] = 'Ligado e conectado. Nenhum formulário foi enviado nos últimos '
                . self::JANELA_DIAS . ' dias — o primeiro envio confirma que funciona.';
            return $r;
        }
        $r['estado'] = self::OK;
        $r['metrica'] = array('valor' => $entregues,
            'rotulo' => 'lead' . ($entregues === 1 ? '' : 's') . ' entregue' . ($entregues === 1 ? '' : 's') . ' em ' . self::JANELA_DIAS . ' dias');
        $r['resumo'] = 'Nenhum preso na fila.';
        return $r;
    }

    /** 2. Rede de segurança pra formulário que não conhecemos. */
    private static function outros_plugins($ligado, $envio_on) {
        $r = array(
            'key' => 'generic_forms_enabled',
            'titulo' => 'Pegar também formulários que não conhecemos',
            'o_que_faz' => 'Se alguém criar um formulário com outro plugin, ou usar o que veio no tema, '
                . 'o envio é capturado mesmo assim — desde que tenha e-mail ou telefone. '
                . 'Existe pra você não descobrir tarde demais que um formulário estava jogando lead fora.',
            'essencial' => false, 'sub' => true, 'ligado' => (bool) $ligado,
            'fonte' => self::FONTE_TABELA,
        );
        if (!$ligado) {
            $r['estado'] = self::OFF;
            $r['resumo'] = 'Desligado. Só os formulários que o plugin já reconhece entregam lead.';
            return $r;
        }
        if (!$envio_on) {
            $r['estado'] = self::ATENCAO;
            $r['resumo'] = 'Ligado, mas a entrega acima está desligada — nada sobe de qualquer forma.';
            return $r;
        }
        $pegos = self::contar(" AND source = %s", array('generic'));
        if ($pegos === null) {
            $r['estado'] = self::ESPERA;
            $r['resumo'] = 'Ligado. Ainda não consigo confirmar capturas neste site.';
        } elseif ($pegos > 0) {
            $r['estado'] = self::OK;
            $r['metrica'] = array('valor' => $pegos, 'rotulo' => 'lead' . ($pegos === 1 ? '' : 's') . ' que só ele pegou');
            $r['resumo'] = 'Em ' . self::JANELA_DIAS . ' dias. Nenhum outro caminho teria capturado.';
        } else {
            $r['estado'] = self::ESPERA;
            $r['resumo'] = 'Ligado, de vigia. Nada precisou dele nos últimos ' . self::JANELA_DIAS
                . ' dias — o que é um bom sinal.';
        }
        return $r;
    }

    /** 3. Saber de onde veio cada visitante. */
    private static function medicao($ligado, $conectado) {
        $r = array(
            'key' => 'pixel_enabled',
            'titulo' => 'Ligar cada lead à campanha que trouxe ele',
            'o_que_faz' => 'Coloca no site um marcador que anota, para cada visitante, de onde ele '
                . 'veio (anúncio, busca, indicação), por qual página entrou e quantas visitou. '
                . 'Quando essa pessoa preenche um formulário, essa história vai junto com o lead — '
                . 'é o que faz o AdSpirit dizer "veio do Google Ads, campanha X" em vez de só '
                . '"veio do site", e o que permite calcular quanto custou cada lead.',
            'essencial' => true, 'sub' => false, 'ligado' => (bool) $ligado,
            'fonte' => self::FONTE_TABELA,
            'conexao' => $conectado ? 'Site conectado ao AdSpirit' : 'Site ainda não conectado',
            'conexao_ok' => (bool) $conectado,
        );
        if (!$ligado) {
            $com_origem_off = self::contar(" AND payload LIKE %s", array('%_adspirit_telemetry%'));
            if ($com_origem_off !== null && $com_origem_off > 0) {
                $r['estado'] = self::ATENCAO;
                $r['ligado'] = false;
                $r['resumo'] = 'A chave está desmarcada, mas ' . $com_origem_off . ' lead(s) recentes '
                    . 'chegaram com origem — então ela estava ligada. Marque de novo abaixo.';
                return $r;
            }
            $r['estado'] = self::OFF;
            $r['resumo'] = 'Desligado. Os leads chegam sem origem e não entram na conta das campanhas.';
            return $r;
        }
        if (!$conectado) {
            $r['estado'] = self::ATENCAO;
            $r['resumo'] = 'Ligado, mas o site ainda não está conectado.';
            return $r;
        }
        // Prova: leads recentes que chegaram COM telemetria. É o próprio
        // rastreador provando que rodou, sem precisar bater no site de fora.
        $com_origem = self::contar(" AND payload LIKE %s", array('%_adspirit_telemetry%'));
        $total      = self::contar('');
        if ($com_origem === null || $total === null) {
            $r['estado'] = self::ESPERA;
            $r['resumo'] = 'Ligado. Ainda não consigo confirmar a medição neste site.';
        } elseif ($total === 0) {
            $r['estado'] = self::ESPERA;
            $r['resumo'] = 'Ligado. Sem envios recentes pra confirmar — o primeiro lead mostra a origem.';
        } elseif ($com_origem === 0) {
            $r['estado'] = self::ATENCAO;
            $r['resumo'] = sprintf('Ligado, mas nenhum dos %d leads recentes trouxe origem. '
                . 'O rastreador pode não estar carregando nas páginas dos formulários.', $total);
        } else {
            $pct = (int) round(($com_origem / max(1, $total)) * 100);
            $r['estado'] = $pct >= 70 ? self::OK : self::ATENCAO;
            $r['metrica'] = array('valor' => $pct . '%', 'rotulo' => 'dos leads chegaram com a origem');
            $r['resumo'] = sprintf('%d de %d nos últimos %d dias.%s', $com_origem, $total, self::JANELA_DIAS,
                $pct < 70 ? ' O rastreador pode não estar carregando em todas as páginas.' : '');
        }
        return $r;
    }

    /** 4. Servir o rastreador pelo domínio do site. */
    private static function primeira_parte($ligado, $pixel_on) {
        $r = array(
            'key' => 'pixel_firstparty',
            'titulo' => 'Servir o rastreador pelo endereço deste site',
            'o_que_faz' => 'Bloqueadores de anúncio barram scripts vindos de outros domínios. '
                . 'Servido pelo endereço deste site, menos visitante fica invisível. '
                . 'O código é o mesmo — só muda o endereço de onde ele vem.',
            'essencial' => false, 'sub' => true, 'ligado' => (bool) $ligado,
        );
        if (!$pixel_on) {
            // Chave ligada que não faz nada é pior que desligada: parece
            // resolvido e não está. Por isso vira atenção, não "desligado".
            $r['estado'] = $ligado ? self::ATENCAO : self::OFF;
            $r['resumo'] = $ligado
                ? 'Ligado, mas sem efeito nenhum enquanto a medição acima estiver desligada.'
                : 'Sem efeito enquanto a medição acima estiver desligada.';
            return $r;
        }
        // O injetor tem um kill-switch: desde 2026-08-22 o modo first-party
        // está suspenso porque o script servido localmente perdia o token e
        // o destino, e passava a não medir nada. Enquanto isso valer, o
        // toggle não muda nada — e a tela precisa DIZER isso, em vez de
        // mostrar um verde que não corresponde ao que acontece na página.
        $disponivel = class_exists('AdSpirit_Pixel_Proxy')
            && (bool) apply_filters('adspirit_pixel_firstparty_ok', AdSpirit_Pixel_Proxy::suporta_config());
        if (!$disponivel) {
            $r['indisponivel'] = true;
            $r['estado'] = $ligado ? self::ATENCAO : self::OFF;
            $r['resumo'] = $ligado
                ? 'Marcado, mas ainda sem efeito: o AdSpirit desta conta precisa ser atualizado '
                  . 'antes. Enquanto isso o rastreador continua vindo do endereço do AdSpirit e '
                  . 'medindo normalmente — liga sozinho assim que o outro lado subir.'
                : 'Indisponível até o AdSpirit desta conta ser atualizado. O rastreador segue '
                  . 'vindo do endereço do AdSpirit e medindo normalmente.';
            return $r;
        }
        if (!$ligado) {
            $r['estado'] = self::OFF;
            $r['resumo'] = 'Desligado. O rastreador vem do endereço do AdSpirit e '
                . 'parte dos visitantes é perdida por bloqueadores.';
            return $r;
        }
        $r['estado'] = self::OK;
        $r['resumo'] = 'Ligado. O rastreador é servido por este domínio.';
        return $r;
    }

    /** Rótulo curto do estado, pro selo na tela. */
    public static function rotulo($estado) {
        switch ($estado) {
            case self::OK:      return 'Funcionando';
            case self::ESPERA:  return 'Sem sinal ainda';
            case self::ATENCAO: return 'Precisa de atenção';
            default:            return 'Desligado';
        }
    }
}
