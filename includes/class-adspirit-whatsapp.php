<?php
/**
 * AdSpirit WhatsApp — shortcode pra botão flutuante + popup de WhatsApp.
 *
 * Substitui ~150 LOC de copy-paste manual que o time front-end usa hoje
 * (popup do Yumi adaptado por marca). Centraliza UX + tracking server-side.
 *
 * Uso:
 *   [adspirit_whatsapp number="5521998669266" message="Oi, gostaria..."]
 *
 * Atributos:
 *   number       — número internacional sem +, sem espaços (obrigatório)
 *   message      — texto pré-preenchido (default: vazio)
 *   cta_label    — label do botão flutuante (default: "Falar no WhatsApp")
 *   position     — bottom-right | bottom-left (default: bottom-right)
 *   variant      — full | mini (default: full — abre popup; mini = link direto)
 *
 * Telemetria: ao clicar "Iniciar conversa" dispara dataLayer event
 *   { event: 'adspirit_whatsapp_click', number, message } pra GTM/GA4.
 *
 * @package AdSpiritConnector
 */

if (!defined('ABSPATH')) exit;

class AdSpirit_WhatsApp {
    private static $instance = null;
    private $rendered_once = false;

    public static function instance() {
        if (self::$instance === null) self::$instance = new self();
        return self::$instance;
    }

    private function __construct() {
        add_shortcode('adspirit_whatsapp', array($this, 'render_shortcode'));
    }

    public function render_shortcode($atts) {
        $atts = shortcode_atts(array(
            'number'    => '',
            'message'   => '',
            'cta_label' => 'Falar no WhatsApp',
            'position'  => 'bottom-right',
            'variant'   => 'full',
        ), $atts, 'adspirit_whatsapp');

        $number = preg_replace('/[^0-9]/', '', (string) $atts['number']);
        if ($number === '') {
            return '<!-- [adspirit_whatsapp] number ausente -->';
        }

        // Enqueue (1x por página)
        if (!$this->rendered_once) {
            $version = defined('ADSPIRIT_CONNECTOR_VERSION') ? ADSPIRIT_CONNECTOR_VERSION : '2.5.0';
            wp_enqueue_style(
                'adspirit-whatsapp-popup',
                ADSPIRIT_CONNECTOR_URL . 'assets/whatsapp-popup.css',
                array(),
                $version
            );
            wp_enqueue_script(
                'adspirit-whatsapp-popup',
                ADSPIRIT_CONNECTOR_URL . 'assets/whatsapp-popup.js',
                array(),
                $version,
                true
            );
            $this->rendered_once = true;
        }

        // Connector 3.0 — contexto dinâmico na mensagem (padrão Joinchat):
        // {TITLE} e {URL} viram a página atual — o atendente já sabe de onde
        // o lead veio ("Oi! Vi a página {TITLE} e quero saber mais"). Sem
        // placeholder, comportamento idêntico ao de sempre (aditivo).
        $message = (string) $atts['message'];
        if (strpos($message, '{TITLE}') !== false || strpos($message, '{URL}') !== false) {
            $page_title = trim(wp_strip_all_tags((string) wp_title('', false)));
            if ($page_title === '') $page_title = get_bloginfo('name');
            if (is_singular()) {
                $t = get_the_title();
                if (is_string($t) && trim($t) !== '') $page_title = trim(wp_strip_all_tags($t));
            }
            global $wp;
            $page_url = home_url(isset($wp->request) ? '/' . ltrim((string) $wp->request, '/') : '/');
            $message = str_replace(array('{TITLE}', '{URL}'), array($page_title, $page_url), $message);
        }
        $message_encoded = rawurlencode($message);
        $wa_url = "https://api.whatsapp.com/send/?phone={$number}&text={$message_encoded}&type=phone_number&app_absent=0";

        $position_class = $atts['position'] === 'bottom-left' ? 'pos-bl' : 'pos-br';
        $variant = $atts['variant'] === 'mini' ? 'mini' : 'full';

        // Variant mini: link direto pro WhatsApp (sem popup)
        if ($variant === 'mini') {
            return sprintf(
                '<a href="%s" target="_blank" rel="noopener" class="adspirit-wa-fab %s" data-adspirit-wa-mini="1" aria-label="%s">%s%s</a>',
                esc_url($wa_url),
                esc_attr($position_class),
                esc_attr($atts['cta_label']),
                $this->wa_icon(),
                ''
            );
        }

        // Variant full: botão flutuante + popup com mensagem
        ob_start();
        ?>
        <div class="adspirit-wa-root <?php echo esc_attr($position_class); ?>"
             data-wa-number="<?php echo esc_attr($number); ?>"
             data-wa-message="<?php echo esc_attr($message); ?>">
            <button type="button" class="adspirit-wa-fab" aria-label="<?php echo esc_attr($atts['cta_label']); ?>" aria-expanded="false">
                <?php echo $this->wa_icon(); ?>
            </button>
            <div class="adspirit-wa-popup" role="dialog" aria-label="WhatsApp" hidden>
                <div class="adspirit-wa-popup-header">
                    <div class="adspirit-wa-popup-avatar"><?php echo $this->wa_icon(); ?></div>
                    <div class="adspirit-wa-popup-meta">
                        <div class="adspirit-wa-popup-title">Atendimento</div>
                        <div class="adspirit-wa-popup-status">Normalmente respondemos em poucos minutos</div>
                    </div>
                    <button type="button" class="adspirit-wa-popup-close" aria-label="Fechar">×</button>
                </div>
                <div class="adspirit-wa-popup-body">
                    <div class="adspirit-wa-bubble">
                        Oi! Tudo bem? Estamos aqui pra responder qualquer dúvida. Clique abaixo pra começar.
                    </div>
                </div>
                <div class="adspirit-wa-popup-footer">
                    <a href="<?php echo esc_url($wa_url); ?>" target="_blank" rel="noopener" class="adspirit-wa-cta" data-adspirit-wa-cta="1">
                        <?php echo $this->wa_icon_small(); ?>
                        Iniciar conversa
                    </a>
                </div>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    private function wa_icon() {
        return '<svg viewBox="0 0 24 24" width="24" height="24" fill="currentColor" aria-hidden="true"><path d="M17.5 14.4l-2.3-1.1c-.3-.2-.7-.1-.9.2l-.8 1c-1.3-.6-2.3-1.6-2.9-2.9l1-.8c.3-.2.4-.6.2-.9L10.6 6.6c-.2-.3-.6-.5-1-.3-1.7.6-2.8 2.4-2.3 4.2.7 2.6 2.7 4.6 5.3 5.3 1.8.5 3.6-.6 4.2-2.3.2-.4 0-.8-.3-1z"/><path d="M12 1C5.9 1 1 5.9 1 12c0 1.9.5 3.7 1.4 5.3L1 23l5.9-1.5c1.5.8 3.3 1.3 5.1 1.3 6.1 0 11-4.9 11-11S18.1 1 12 1zm0 20c-1.7 0-3.3-.4-4.7-1.3l-.3-.2-3.5.9.9-3.4-.2-.4c-1-1.4-1.5-3.1-1.5-4.8 0-5 4-9 9-9s9 4 9 9-4 9-9 9z"/></svg>';
    }

    private function wa_icon_small() {
        return '<svg viewBox="0 0 24 24" width="16" height="16" fill="currentColor" aria-hidden="true"><path d="M12 1C5.9 1 1 5.9 1 12c0 1.9.5 3.7 1.4 5.3L1 23l5.9-1.5c1.5.8 3.3 1.3 5.1 1.3 6.1 0 11-4.9 11-11S18.1 1 12 1z"/></svg>';
    }
}
