/* AdSpirit WhatsApp popup — abre/fecha popup + dispara dataLayer event no CTA. */
(function () {
  'use strict';

  if (window.__adspiritWaInit) return;
  window.__adspiritWaInit = true;

  function pushDataLayer(eventName, payload) {
    try {
      window.dataLayer = window.dataLayer || [];
      window.dataLayer.push(Object.assign({ event: eventName }, payload || {}));
    } catch (e) {}
  }

  function init() {
    var roots = document.querySelectorAll('.adspirit-wa-root');
    Array.prototype.forEach.call(roots, function (root) {
      if (root.__adspiritWaBound) return;
      root.__adspiritWaBound = true;

      var fab = root.querySelector('.adspirit-wa-fab');
      var popup = root.querySelector('.adspirit-wa-popup');
      var closeBtn = root.querySelector('.adspirit-wa-popup-close');
      var cta = root.querySelector('[data-adspirit-wa-cta]');
      if (!fab || !popup) return;

      function open() {
        popup.hidden = false;
        fab.setAttribute('aria-expanded', 'true');
        pushDataLayer('adspirit_whatsapp_open', {
          number: root.getAttribute('data-wa-number'),
        });
      }
      function close() {
        popup.hidden = true;
        fab.setAttribute('aria-expanded', 'false');
      }

      fab.addEventListener('click', function () {
        if (popup.hidden) open();
        else close();
      });
      if (closeBtn) closeBtn.addEventListener('click', close);

      // ESC fecha
      document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && !popup.hidden) close();
      });

      // Click fora fecha
      document.addEventListener('click', function (e) {
        if (popup.hidden) return;
        if (root.contains(e.target)) return;
        close();
      });

      if (cta) {
        cta.addEventListener('click', function () {
          pushDataLayer('adspirit_whatsapp_click', {
            number: root.getAttribute('data-wa-number'),
            message: root.getAttribute('data-wa-message') || '',
          });
        });
      }
    });

    // Mini variant: só telemetria, sem popup
    var minis = document.querySelectorAll('a.adspirit-wa-fab[data-adspirit-wa-mini]');
    Array.prototype.forEach.call(minis, function (link) {
      if (link.__adspiritWaBound) return;
      link.__adspiritWaBound = true;
      link.addEventListener('click', function () {
        pushDataLayer('adspirit_whatsapp_click', {
          variant: 'mini',
          href: link.getAttribute('href'),
        });
      });
    });
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
