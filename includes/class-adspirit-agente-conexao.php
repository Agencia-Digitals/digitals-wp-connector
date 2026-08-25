<?php
/**
 * AdSpirit Connector — conectar o assistente a este site.
 *
 * O AdSpirit_Agente já expõe as operações (diagnóstico, verificar pixel,
 * sincronizar config) pela API do WordPress. O que faltava era a ponte: pra
 * usar, alguém tinha que abrir o perfil do usuário, criar uma senha de
 * aplicativo à mão, copiar, montar um JSON de configuração e colar num
 * arquivo escondido do computador.
 *
 * É trabalho de dev pra uma tarefa de operação — e o tipo de coisa que
 * ninguém repete pro segundo site. Aqui vira um botão: o plugin cria a
 * credencial pela API nativa do WordPress e devolve a configuração pronta.
 *
 * PRINCÍPIOS DESTA TELA:
 *
 *   1. A senha aparece UMA vez. É assim que o WordPress funciona, e é bom
 *      que seja: não fica guardada em lugar nenhum deste plugin.
 *   2. Revogar é tão fácil quanto criar, e está na mesma tela. Credencial
 *      que não dá pra tirar depois ninguém deveria criar.
 *   3. Só pessoa da Digitals com permissão de administrar. É a mesma trava
 *      das operações do agente.
 *   4. A tela diz o que a credencial PERMITE, antes de criar. Quem clica
 *      precisa saber que está abrindo acesso administrativo à API.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_Agente_Conexao {

    /** Nome da credencial no WordPress — usado pra achar e revogar. */
    const APP_NAME = 'AdSpirit — Assistente';
    /** Guarda só o transiente da senha recém-criada, pra mostrar uma vez. */
    const TRANSIENT_NOVA = 'adspirit_agente_senha_nova';

    private static $instance = null;

    public static function instance() {
        if (null === self::$instance) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_post_adspirit_agente_criar',
            AdSpirit_Safe_Hook::action(array($this, 'criar'), 'agente_criar'));
        add_action('admin_post_adspirit_agente_revogar',
            AdSpirit_Safe_Hook::action(array($this, 'revogar'), 'agente_revogar'));
        add_action('adspirit_connector_render_tab_logs',
            AdSpirit_Safe_Hook::action(array($this, 'render'), 'agente_conexao_render'), 4);
    }

    /** O WordPress deste site suporta senha de aplicativo? */
    private static function suportado() {
        return class_exists('WP_Application_Passwords')
            && method_exists('WP_Application_Passwords', 'create_new_application_password');
    }

    private static function pode() {
        return class_exists('AdSpirit_Ambiente')
            ? AdSpirit_Ambiente::pode_operar_pelo_agente()
            : current_user_can('manage_options');
    }

    /** Endereço que o assistente usa pra falar com este site. */
    public static function endpoint() {
        return rest_url('mcp/mcp-adapter-default-server');
    }

    /** As credenciais deste plugin que existem hoje, com data. */
    private static function existentes($user_id) {
        if (!self::suportado()) return array();
        $todas = WP_Application_Passwords::get_user_application_passwords($user_id);
        $nossas = array();
        foreach ((array) $todas as $p) {
            if (isset($p['name']) && $p['name'] === self::APP_NAME) $nossas[] = $p;
        }
        return $nossas;
    }

    public function criar() {
        if (!self::pode()) wp_die('Sem permissão.');
        check_admin_referer('adspirit_agente_criar');
        $back = add_query_arg(
            array('page' => AdSpirit_Menu::PAGE_SLUG, 'tab' => 'logs'),
            admin_url('admin.php')
        );
        if (!self::suportado()) {
            wp_safe_redirect(add_query_arg('agente', 'sem_suporte', $back));
            exit;
        }
        $uid = get_current_user_id();

        // Uma credencial por vez: duas ativas só criam confusão sobre qual
        // está em uso, e revogar a errada é pior que não ter.
        foreach (self::existentes($uid) as $p) {
            WP_Application_Passwords::delete_application_password($uid, $p['uuid']);
        }

        $nova = WP_Application_Passwords::create_new_application_password(
            $uid,
            array('name' => self::APP_NAME)
        );
        if (is_wp_error($nova)) {
            wp_safe_redirect(add_query_arg('agente', 'erro', $back));
            exit;
        }
        // 10 minutos: tempo de copiar, não de guardar. Depois disso some,
        // e criar outra é um clique.
        set_transient(self::TRANSIENT_NOVA, array(
            'senha' => (string) $nova[0],
            'usuario' => wp_get_current_user()->user_login,
        ), 10 * MINUTE_IN_SECONDS);

        wp_safe_redirect(add_query_arg('agente', 'criada', $back));
        exit;
    }

    public function revogar() {
        if (!self::pode()) wp_die('Sem permissão.');
        check_admin_referer('adspirit_agente_revogar');
        $uid = get_current_user_id();
        foreach (self::existentes($uid) as $p) {
            WP_Application_Passwords::delete_application_password($uid, $p['uuid']);
        }
        delete_transient(self::TRANSIENT_NOVA);
        wp_safe_redirect(add_query_arg(
            array('page' => AdSpirit_Menu::PAGE_SLUG, 'tab' => 'logs', 'agente' => 'revogada'),
            admin_url('admin.php')
        ));
        exit;
    }

    public function render() {
        if (!self::pode()) return;
        $uid = get_current_user_id();
        $nossas = self::existentes($uid);
        $nova = get_transient(self::TRANSIENT_NOVA);
        $aviso = isset($_GET['agente']) ? sanitize_key((string) $_GET['agente']) : '';

        AdSpirit_Menu::card_open(
            'Conectar o assistente a este site',
            'Permite que a equipe da Digitals faça diagnóstico e ajuste daqui, pelo Claude, sem pedir acesso ao painel.'
        );

        if ($aviso === 'sem_suporte') {
            echo '<div class="as-notice warn"><p>Este WordPress não tem senhas de aplicativo disponíveis. '
               . 'Elas exigem HTTPS e WordPress 5.6 ou mais novo.</p></div>';
        } elseif ($aviso === 'erro') {
            echo '<div class="as-notice danger"><p>O WordPress recusou criar a credencial. '
               . 'Veja se as senhas de aplicativo estão habilitadas neste site.</p></div>';
        } elseif ($aviso === 'revogada') {
            echo '<div class="as-notice info"><p>Credencial revogada. O assistente perdeu o acesso a este site.</p></div>';
        }

        if (is_array($nova) && !empty($nova['senha'])) :
            $url = self::endpoint();
            $usuario = (string) $nova['usuario'];
            $senha = (string) $nova['senha'];

            // Um passo por cliente. Cada um pede a informação num formato
            // diferente, e mandar a pessoa "adaptar o JSON" é exatamente
            // onde ela desiste. Aqui cada aba já vem pronta pro seu.
            $slug = sanitize_title(parse_url(home_url(), PHP_URL_HOST));
            $bloco = array(
                'command' => 'npx',
                'args' => array('-y', '@automattic/mcp-wordpress-remote'),
                'env' => array(
                    'WP_API_URL' => $url,
                    'WP_API_USERNAME' => $usuario,
                    'WP_API_PASSWORD' => $senha,
                    'OAUTH_ENABLED' => 'false',
                ),
            );
            $pretty = function ($v) {
                return wp_json_encode($v, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            };

            // Um passo por cliente, e NENHUM pede terminal: cada aba entrega
            // um bloco que se cola onde a pessoa já está. Mandar alguém
            // "rodar um comando" é onde metade desiste — e quem não desiste
            // erra o diretório.
            $clientes = array(
                'claude-code' => array(
                    'nome' => 'Claude Code',
                    'como' => 'Cole isto na conversa e peça: “conecta esse MCP”. O Claude escreve a configuração sozinho.',
                    'bloco' => $pretty(array('mcpServers' => array('adspirit-' . $slug => $bloco))),
                    'depois' => 'Ele grava no .mcp.json do projeto. Recarregue a sessão pra conexão subir.',
                ),
                'chatgpt' => array(
                    'nome' => 'ChatGPT',
                    'indisponivel' => true,
                    'como' => 'Ainda não dá pra conectar o ChatGPT a este site.',
                    'bloco' => "Endereço do servidor: {$url}\n\n"
                        . "O ChatGPT aceita conector remoto só com OAuth ou sem autenticação nenhuma.\n"
                        . "Este site autentica por senha de aplicativo, que o ChatGPT não suporta —\n"
                        . "colar o endereço acima vai dar erro de autenticação.\n\n"
                        . "Falta habilitar OAuth no WordPress. Enquanto isso, use o Claude ou o Cursor.",
                    'depois' => 'Guarde o endereço: no dia em que o OAuth entrar, é só ele que muda de lado.',
                ),
                'claude-desktop' => array(
                    'nome' => 'Claude Desktop',
                    'como' => 'Em Configurações → Desenvolvedor → Editar configuração, cole dentro de "mcpServers":',
                    'bloco' => $pretty(array('adspirit-' . $slug => $bloco)),
                    'depois' => 'Salve o arquivo, feche e abra o Claude Desktop.',
                ),
                'cursor' => array(
                    'nome' => 'Cursor',
                    'como' => 'Em Settings → MCP → Add new server, escolha o modo JSON e cole:',
                    'bloco' => $pretty(array('mcpServers' => array('adspirit' => $bloco))),
                    'depois' => 'O servidor aparece na lista assim que você salvar.',
                ),
                'manual' => array(
                    'nome' => 'Outro assistente',
                    'como' => 'Qualquer cliente que fale MCP por linha de comando precisa destes valores:',
                    'bloco' => "Endereço: {$url}\nUsuário:  {$usuario}\nSenha:    {$senha}",
                    'depois' => 'A autenticação é HTTP Basic com esse usuário e essa senha.',
                ),
            );
            ?>
            <div class="as-notice info">
                <div class="as-notice-kicker">Credencial criada</div>
                <p><strong>Copie agora.</strong> O WordPress mostra a senha uma única vez —
                   este plugin não guarda nem consegue recuperar depois. Se perder, é só criar outra.</p>
            </div>

            <p class="as-section-help">Escolha onde você usa o assistente. A configuração já vem pronta pro seu:</p>

            <div class="as-wizard">
                <div class="as-wizard-abas" role="tablist">
                    <?php $primeiro = true; foreach ($clientes as $k => $c) : ?>
                        <button type="button" class="as-wizard-aba<?php echo $primeiro ? ' ativa' : ''; ?><?php
                                    echo !empty($c['indisponivel']) ? ' indisponivel' : ''; ?>"
                                data-alvo="wz-<?php echo esc_attr($k); ?>" role="tab"
                                aria-selected="<?php echo $primeiro ? 'true' : 'false'; ?>"><?php
                            echo esc_html($c['nome']);
                            if (!empty($c['indisponivel'])) echo '<span class="marca">em breve</span>';
                        ?></button>
                    <?php $primeiro = false; endforeach; ?>
                </div>
                <?php $primeiro = true; foreach ($clientes as $k => $c) : ?>
                    <div class="as-wizard-painel<?php echo $primeiro ? ' ativo' : ''; ?>" id="wz-<?php echo esc_attr($k); ?>" role="tabpanel">
                        <p class="as-wizard-como"><?php echo esc_html($c['como']); ?></p>
                        <textarea class="as-agente-cfg" rows="<?php echo $k === 'claude-code' || $k === 'manual' ? 4 : 12; ?>"
                                  readonly onclick="this.select()"><?php echo esc_textarea($c['bloco']); ?></textarea>
                        <p class="as-wizard-depois"><?php echo esc_html($c['depois']); ?></p>
                    </div>
                <?php $primeiro = false; endforeach; ?>
            </div>
            <script>
            (function(){
                var w = document.querySelector('.as-wizard');
                if (!w) return;
                w.addEventListener('click', function(e){
                    var b = e.target.closest('.as-wizard-aba');
                    if (!b) return;
                    w.querySelectorAll('.as-wizard-aba').forEach(function(x){
                        var on = x === b;
                        x.classList.toggle('ativa', on);
                        x.setAttribute('aria-selected', on ? 'true' : 'false');
                    });
                    w.querySelectorAll('.as-wizard-painel').forEach(function(p){
                        p.classList.toggle('ativo', p.id === b.getAttribute('data-alvo'));
                    });
                });
            })();
            </script>
            <p class="as-section-help">Some desta tela em 10 minutos.</p>
        <?php endif; ?>

        <?php if (!empty($nossas)) : ?>
            <table class="widefat striped" style="margin:14px 0;">
                <thead><tr><th>Credencial</th><th>Criada</th><th>Último uso</th></tr></thead>
                <tbody>
                <?php foreach ($nossas as $p) : ?>
                    <tr>
                        <td><?php echo esc_html($p['name']); ?></td>
                        <td><?php echo !empty($p['created']) ? esc_html(date_i18n('d/m/Y H:i', (int) $p['created'])) : '—'; ?></td>
                        <td><?php echo !empty($p['last_used']) ? esc_html(date_i18n('d/m/Y H:i', (int) $p['last_used'])) : 'nunca'; ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php else : ?>
            <p class="as-section-help">Nenhuma credencial ativa. O assistente não tem acesso a este site.</p>
        <?php endif; ?>

        <div class="as-agente-risco">
            <strong>O que a credencial permite.</strong> Acesso administrativo à API deste WordPress,
            em nome de <?php echo esc_html(wp_get_current_user()->user_login); ?> — leitura e escrita,
            não só as operações do AdSpirit. Crie quando for usar e revogue quando terminar; o botão
            de revogar está aqui do lado e tira o acesso na hora.
        </div>

        <p class="as-actions">
            <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                <input type="hidden" name="action" value="adspirit_agente_criar">
                <?php wp_nonce_field('adspirit_agente_criar'); ?>
                <button type="submit" class="button button-primary"><?php
                    echo empty($nossas) ? 'Criar credencial' : 'Criar outra (revoga a atual)';
                ?></button>
            </form>
            <?php if (!empty($nossas)) : ?>
                <form method="post" action="<?php echo esc_url(admin_url('admin-post.php')); ?>" style="display:inline;">
                    <input type="hidden" name="action" value="adspirit_agente_revogar">
                    <?php wp_nonce_field('adspirit_agente_revogar'); ?>
                    <button type="submit" class="button">Revogar acesso</button>
                </form>
            <?php endif; ?>
        </p>

        <?php AdSpirit_Menu::card_close();
    }
}
