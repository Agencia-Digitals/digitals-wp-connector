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

    /**
     * O plugin se chama AdSpirit Connector em todo lugar — trocar o nome pelo
     * endereço confundiria mais do que ajudaria (mesmo plugin, mesmo update,
     * mesma tela de suporte). O que muda é que num endereço nosso aparece uma
     * aba a mais, "Studio", com as ferramentas de construção.
     */

    // ─────────────────────────────────────────────────────────
    // Quem pode operar o site pelo agente
    // ─────────────────────────────────────────────────────────

    /**
     * Domínios de e-mail que identificam gente da Digitals.
     *
     * Fica no código (com escotilha em wp-config), NÃO numa opção do banco:
     * opção no banco é editável por qualquer admin do site — inclusive do
     * cliente —, e aí a tranca abriria por dentro.
     */
    /**
     * Endereços individuais liberados, fora dos domínios da agência.
     *
     * Existe por um motivo concreto: o Pedro opera pelo Gmail dele. Manter
     * isso como lista explícita e curta é melhor do que afrouxar a regra dos
     * domínios — cada exceção fica visível e datada em vez de virar uma
     * brecha genérica.
     */
    private static function emails_liberados() {
        $lista = array('agenciadigitalsmkt@gmail.com');
        if (defined('ADSPIRIT_EMAILS_EQUIPE') && ADSPIRIT_EMAILS_EQUIPE) {
            $lista = array_merge($lista, array_filter(array_map('trim',
                explode(',', (string) ADSPIRIT_EMAILS_EQUIPE))));
        }
        return array_map('strtolower', $lista);
    }

    private static function dominios_da_digitals() {
        $lista = array('agenciadigitals.com.br', 'digitals.com.br');
        if (defined('ADSPIRIT_DOMINIOS_EQUIPE') && ADSPIRIT_DOMINIOS_EQUIPE) {
            $extra = array_filter(array_map('trim', explode(',', (string) ADSPIRIT_DOMINIOS_EQUIPE)));
            $lista = array_merge($lista, $extra);
        }
        return $lista;
    }

    /**
     * Esta pessoa é da Digitals?
     *
     * É a tranca das operações por agente. Ser administrador do site não
     * basta: no site do cliente o próprio cliente é administrador, e as
     * ferramentas de manutenção não são pra ele.
     *
     * Honestidade sobre o alcance: quem administra o próprio WordPress pode
     * trocar o e-mail de um usuário e passar por aqui. Isso não é furo, é o
     * limite do que dá pra garantir de dentro do site — quem é dono do site já
     * pode tudo nele de qualquer forma. O que esta tranca resolve é o que
     * acontece de verdade no dia a dia: impedir que alguém do lado do cliente
     * dispare sem querer uma ferramenta nossa, e deixar registrado quem
     * disparou o quê.
     */
    public static function e_pessoa_da_digitals($user_id = null) {
        $user = $user_id ? get_userdata($user_id) : wp_get_current_user();
        if (!$user || empty($user->user_email)) return false;

        $email = strtolower(trim($user->user_email));

        if (in_array($email, self::emails_liberados(), true)) return true;

        $arroba = strrpos($email, '@');
        if ($arroba === false) return false;
        $dominio = substr($email, $arroba + 1);

        foreach (self::dominios_da_digitals() as $d) {
            $d = strtolower(trim((string) $d));
            if ($d === '') continue;
            if ($dominio === $d) return true;
            if (substr($dominio, -strlen('.' . $d)) === '.' . $d) return true;
        }
        return false;
    }

    /**
     * A tranca completa das operações por agente: precisa ser da Digitals E
     * poder administrar o site. Uma coisa sem a outra não abre.
     */
    public static function pode_operar_pelo_agente() {
        return self::e_pessoa_da_digitals() && current_user_can('manage_options');
    }

    /** Uma frase pra tela dizer em que modo está. */
    public static function descricao() {
        return self::e_estudio()
            ? sprintf('Modo estúdio — %s é um endereço nosso, então as ferramentas de construção estão disponíveis.', self::host())
            : sprintf('Modo cliente — %s é o domínio do cliente. Só medição, captação e entrega; ferramenta de construção fica de fora.', self::host());
    }
}
