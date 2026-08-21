<?php
/**
 * AdSpirit Connector — Onde este site está rodando.
 *
 * O mesmo código vai pro nosso subdomínio e pro domínio do cliente. O que
 * separa os dois não é o pacote instalado — é o endereço.
 *
 * No estúdio (um endereço nosso) o site está em construção, ninguém de fora
 * depende dele, e o time precisa de ferramenta de verdade: conversão de
 * builder, operação por agente, utilitários de desenvolvimento.
 *
 * No domínio do cliente o site está no ar valendo. Ali o plugin faz o
 * essencial — medir, captar lead, entregar e-mail — e nada que possa quebrar
 * o que está funcionando.
 *
 * Por que domínio e não uma opção no banco: opção alguém marca sem querer, e
 * um site clonado do estúdio pra produção herdaria a marcação. O endereço
 * muda junto com a mudança de contexto, sozinho.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Ambiente {

    const OPTION_DOMINIOS = 'adspirit_dominios_estudio';

    /** Endereços que consideramos "nossos" por padrão. */
    private static function dominios_do_estudio() {
        $padrao = array(
            'agenciadigitals.com.br',
            'digitals.com.br',
            'localhost',
            '.local',
            '.test',
        );
        $extras = get_option(self::OPTION_DOMINIOS, array());
        if (is_string($extras)) {
            $extras = array_filter(array_map('trim', preg_split('/[\s,]+/', $extras)));
        }
        if (!is_array($extras)) $extras = array();
        return apply_filters('adspirit_dominios_estudio', array_merge($padrao, $extras));
    }

    /** Host do site, sem porta e sem www. */
    public static function host() {
        $host = wp_parse_url(home_url(), PHP_URL_HOST);
        $host = is_string($host) ? strtolower($host) : '';
        return preg_replace('/^www\./', '', $host);
    }

    /**
     * Este site é nosso? Só aqui as ferramentas de construção acordam.
     *
     * Casa por sufixo, então `zatzperforma.agenciadigitals.com.br` conta, e
     * `agenciadigitals.com.br.exemplo.com` não — o ponto antes do sufixo é o
     * que impede o disfarce.
     */
    public static function e_estudio() {
        $host = self::host();
        if ($host === '') return false;

        foreach (self::dominios_do_estudio() as $dominio) {
            $d = strtolower(ltrim(trim((string) $dominio), '.'));
            if ($d === '') continue;
            if ($host === $d) return true;
            if (substr($host, -strlen('.' . $d)) === '.' . $d) return true;
        }

        // Escotilha pra caso raro: site nosso em domínio que ainda não está na
        // lista. Vive no wp-config, não no banco — quem edita wp-config já tem
        // o servidor de qualquer forma.
        return defined('ADSPIRIT_FORCAR_ESTUDIO') && ADSPIRIT_FORCAR_ESTUDIO;
    }

    /** Nome que o plugin usa na interface, conforme onde está. */
    public static function nome_do_plugin() {
        return self::e_estudio() ? 'Digitals Studio' : 'AdSpirit Connector';
    }

    /** Uma frase pra tela dizer em que modo está. */
    public static function descricao() {
        return self::e_estudio()
            ? sprintf('Modo estúdio — %s é um endereço nosso, então as ferramentas de construção estão disponíveis.', self::host())
            : sprintf('Modo cliente — %s é o domínio do cliente. Só medição, captação e entrega; ferramenta de construção fica de fora.', self::host());
    }
}
