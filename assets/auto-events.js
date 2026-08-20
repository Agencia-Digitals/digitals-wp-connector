/* AdSpirit auto-events — eventos automáticos nomeados (padrão PixelYourSite).
 * tel_click / email_click / whatsapp_click (+generate_lead) / file_download
 * empurrados pro dataLayer com parâmetros ricos. O GTM/GA4 do site decide o
 * que fazer com eles; sem GTM, o push é inofensivo. Submit AJAX e cliques em
 * tel:/mailto: são invisíveis pro Enhanced Measurement — por isso existimos.
 * Nunca quebra a página: tudo em try/catch, listener passivo em capture.
 */
(function () {
  'use strict';
  if (window.__adspiritAutoEvents) return;
  window.__adspiritAutoEvents = true;

  var DOWNLOAD_RE = /\.(pdf|zip|rar|7z|docx?|xlsx?|pptx?|csv|txt|mp3|mp4|mov|avi|epub)([?#].*)?$/i;

  function push(payload) {
    try {
      window.dataLayer = window.dataLayer || [];
      window.dataLayer.push(payload);
    } catch (e) { /* nunca quebra a página */ }
  }

  function linkText(a) {
    var t = (a.textContent || '').replace(/\s+/g, ' ').trim();
    return t.length > 80 ? t.slice(0, 77) + '…' : t;
  }

  document.addEventListener('click', function (ev) {
    try {
      var a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
      if (!a || a.__adspiritTracked) return;
      var href = String(a.getAttribute('href') || '');
      if (!href) return;

      var base = {
        link_url: href,
        link_text: linkText(a),
        page_location: String(location.href),
      };

      if (/^tel:/i.test(href)) {
        a.__adspiritTracked = true;
        push(Object.assign({ event: 'tel_click' }, base));
        return;
      }
      if (/^mailto:/i.test(href)) {
        a.__adspiritTracked = true;
        push(Object.assign({ event: 'email_click' }, base));
        return;
      }
      if (/(^https?:)?\/\/(wa\.me|api\.whatsapp\.com|web\.whatsapp\.com)\//i.test(href)) {
        a.__adspiritTracked = true;
        push(Object.assign({ event: 'whatsapp_click' }, base));
        // Lição Joinchat: clique no WhatsApp É a conversão de lead pra quem
        // vive de wa.me — sem esse push o tráfego pago fica cego.
        push({ event: 'generate_lead', method: 'whatsapp_click', link_url: href });
        return;
      }
      if (DOWNLOAD_RE.test(href)) {
        a.__adspiritTracked = true;
        var m = href.match(DOWNLOAD_RE);
        var fname = href.split('?')[0].split('#')[0].split('/').pop() || '';
        push(Object.assign({
          event: 'file_download',
          file_name: fname,
          file_extension: m ? String(m[1]).toLowerCase() : '',
        }, base));
      }
    } catch (e) { /* nunca quebra a página */ }
  }, { capture: true, passive: true });
})();
