/* AdSpirit cross-domain — decoração de links de saída com ?dos_vid=<vid>.
 * v2.30 "inline → arquivos": porte byte-equivalente do script do wp_footer.
 * Mesmo cookie adspirit_vid (90d), mesmo param dos_vid, mesmo guard
 * data-dos-decorated. Config: window.__adspiritXDomainCfg = { domains: [] }.
 */
(function () {
  'use strict';
  try {
    var CFG = window.__adspiritXDomainCfg || {};
    var DOMAINS = CFG.domains || [];
    if (!DOMAINS.length) return;

    function getCookie(name) {
      var m = document.cookie.match('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\\/+^])/g, '\\$1') + '=([^;]*)');
      return m ? decodeURIComponent(m[1]) : null;
    }
    function setCookie(name, value, days) {
      var d = new Date(); d.setTime(d.getTime() + days * 86400000);
      document.cookie = name + '=' + encodeURIComponent(value) + ';expires=' + d.toUTCString() + ';path=/;samesite=lax';
    }
    function uuid() {
      return ([1e7] + -1e3 + -4e3 + -8e3 + -1e11).replace(/[018]/g, function (c) {
        return (c ^ (crypto.getRandomValues(new Uint8Array(1))[0] & 15 >> c / 4)).toString(16);
      });
    }
    var vid = getCookie('adspirit_vid');
    if (!vid) {
      vid = uuid();
      setCookie('adspirit_vid', vid, 90);
    }

    function decorate(a) {
      if (!a.href || a.dataset.dosDecorated === '1') return;
      try {
        var u = new URL(a.href);
        if (DOMAINS.indexOf(u.hostname.toLowerCase()) !== -1) {
          u.searchParams.set('dos_vid', vid);
          a.href = u.toString();
          a.dataset.dosDecorated = '1';
        }
      } catch (e) { /* URL inválida — ignora */ }
    }

    function scan() {
      document.querySelectorAll('a[href]').forEach(decorate);
    }
    if (document.readyState === 'loading') {
      document.addEventListener('DOMContentLoaded', scan);
    } else {
      scan();
    }

    if (typeof MutationObserver !== 'undefined' && document.body) {
      new MutationObserver(function (mutations) {
        mutations.forEach(function (m) {
          m.addedNodes.forEach(function (node) {
            if (!node.querySelectorAll) return;
            if (node.tagName === 'A') decorate(node);
            node.querySelectorAll('a[href]').forEach(decorate);
          });
        });
      }).observe(document.body, { childList: true, subtree: true });
    }
  } catch (e) {
    /* silenciado */
  }
})();
