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

        $envio_on   = ($s['cf7_enabled'] ?? '1') === '1';
        $generic_on = ($s['generic_forms_enabled'] ?? '1') === '1';
        $pixel_on   = ($s['pixel_enabled'] ?? '0') === '1';
        $fp_on      = ($s['pixel_firstparty'] ?? '0') === '1';

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
        );
        if (!$ligado) {
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
            $r['resumo'] = sprintf(
                '%d lead%s entregue%s em %d dias, mas %d ainda não chegou lá.',
                $entregues, $entregues === 1 ? '' : 's', $entregues === 1 ? '' : 's',
                self::JANELA_DIAS, $presos
            );
            return $r;
        }
        if ($entregues === 0) {
            $r['estado'] = self::ESPERA;
            $r['resumo'] = 'Ligado e conectado. Nenhum formulário foi enviado nos últimos '
                . self::JANELA_DIAS . ' dias — o primeiro envio confirma que funciona.';
            return $r;
        }
        $r['estado'] = self::OK;
        $r['resumo'] = sprintf('%d lead%s entregue%s nos últimos %d dias, sem nenhum preso.',
            $entregues, $entregues === 1 ? '' : 's', $entregues === 1 ? '' : 's', self::JANELA_DIAS);
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
            $r['resumo'] = sprintf('Salvou %d lead%s em %d dias que nenhum outro caminho pegaria.',
                $pegos, $pegos === 1 ? '' : 's', self::JANELA_DIAS);
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
            'titulo' => 'Saber de onde vem cada visitante',
            'o_que_faz' => 'Sem isto o lead chega sem origem: não dá pra dizer se veio de anúncio, '
                . 'de busca ou de indicação — nem calcular quanto custou.',
            'essencial' => true, 'sub' => false, 'ligado' => (bool) $ligado,
        );
        if (!$ligado) {
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
            $r['resumo'] = sprintf('%d%% dos leads recentes (%d de %d) chegaram com a origem.',
                $pct, $com_origem, $total);
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
        $disponivel = (bool) apply_filters('adspirit_pixel_firstparty_ok', false);
        if (!$disponivel) {
            $r['indisponivel'] = true;
            $r['estado'] = $ligado ? self::ATENCAO : self::OFF;
            $r['resumo'] = $ligado
                ? 'Marcado, mas em pausa técnica: o rastreador continua vindo do endereço do '
                  . 'AdSpirit. A entrega local perdia a identificação do site e parava de medir, '
                  . 'então ficou suspensa até ser corrigida dos dois lados.'
                : 'Em pausa técnica. A entrega local perdia a identificação do site e parava de '
                  . 'medir, então está suspensa até ser corrigida dos dois lados.';
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
