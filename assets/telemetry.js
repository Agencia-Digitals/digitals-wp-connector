/* AdSpirit telemetry — atribuição first/last-touch + coletor de contexto.
 * v2.30 "inline → arquivos": porte BYTE-EQUIVALENTE dos dois scripts inline
 * do wp_footer (mesmos cookies adspirit_ft/lt de 90d, mesmos hidden
 * _adspirit_t_*, mesmos seletores, mesmos caps, mesmo global
 * window.__adspirit_t que o qualifier lê no submit) — agora cacheável,
 * versionado e com UM MutationObserver no lugar de dois.
 * Config: window.__adspiritTelemetryCfg = { wl: [whitelist de params] }.
 * CONSENT: intencionalmente sem gate (legítimo interesse; ver telemetry.php).
 */
(function () {
  'use strict';
  if (window.__adspiritTelemetryFile) return;
  window.__adspiritTelemetryFile = true;

  var CFG = window.__adspiritTelemetryCfg || {};
  var WL = CFG.wl || [];
  var SELECTOR = 'form.wpcf7-form, form.adspirit-form, .gform_wrapper form, form.wpforms-form';

  function readCookie(name) {
    var m = document.cookie.match('(?:^|; )' + name.replace(/([.$?*|{}()[\]\\\/+^])/g, '\\$1') + '=([^;]*)');
    return m ? decodeURIComponent(m[1]) : '';
  }

  /* ───────────── Atribuição (ft/lt) ───────────── */
  var attachAttr = function () {};
  try {
    (function () {
      function clean(v) {
        return String(v || '').replace(/[<>"']/g, '').trim().slice(0, 200);
      }
      function writeCookie(name, value) {
        document.cookie = name + '=' + encodeURIComponent(value)
          + ';max-age=' + (90 * 86400) + ';path=/;samesite=lax';
      }
      var params = {}, has = false;
      try {
        var sp = new URLSearchParams(window.location.search);
        WL.forEach(function (k) {
          var v = sp.get(k);
          if (v) { params[k] = clean(v); has = true; }
        });
      } catch (e) {}

      function touch() {
        var t = {};
        for (var k in params) t[k] = params[k];
        t.referrer = clean(document.referrer);
        t.landing_url = clean(window.location.href.split('#')[0]);
        t.ts = new Date().toISOString();
        return JSON.stringify(t);
      }
      // first-touch: gravado uma vez, nunca sobrescrito
      if (!readCookie('adspirit_ft')) writeCookie('adspirit_ft', touch());
      // last-touch: só atualiza quando a visita traz parâmetro novo
      if (has) writeCookie('adspirit_lt', touch());

      attachAttr = function () {
        document.querySelectorAll(SELECTOR).forEach(function (form) {
          if (form.dataset.adspiritAttrAttached) return;
          form.dataset.adspiritAttrAttached = '1';
          [['_adspirit_t_ft', 'adspirit_ft'], ['_adspirit_t_lt', 'adspirit_lt']].forEach(function (pair) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = pair[0];
            input.value = readCookie(pair[1]) || '';
            form.appendChild(input);
          });
        });
      };
    })();
  } catch (e) { /* silenciado */ }

  /* ───────────── Coletor de contexto ───────────── */
  var attachHidden = function () {};
  try {
    (function () {
      var t = window.__adspirit_t = window.__adspirit_t || {};
      t.start_ts = t.start_ts || Date.now();
      t.locale = navigator.language || '';
      t.timezone = (Intl && Intl.DateTimeFormat && Intl.DateTimeFormat().resolvedOptions().timeZone) || '';
      t.color_scheme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light';
      t.screen = (window.screen ? (window.screen.width + 'x' + window.screen.height) : '');
      t.viewport = (window.innerWidth + 'x' + window.innerHeight);
      t.connection_type = (navigator.connection && navigator.connection.effectiveType) || '';

      // O pixel do CRM grava _dosvi/_dossi (NÃO adspirit_vid). Fallback ao
      // nome legado por segurança (instalações antigas). NÃO mudar os nomes.
      t.visitor_id = readCookie('_dosvi') || readCookie('adspirit_vid') || '';
      t.session_id = readCookie('_dossi') || readCookie('adspirit_sid') || '';

      function readPixelAttr() {
        try {
          if (window.dos && typeof window.dos.getAttribution === 'function') {
            return window.dos.getAttribution() || {};
          }
          return JSON.parse(localStorage.getItem('_dos_attr') || '{}') || {};
        } catch (e) { return {}; }
      }
      t.fbp = readCookie('_fbp');
      t.fbc = readCookie('_fbc');
      t.ga = readCookie('_ga');
      t.gid = readCookie('_gid');
      t.gcl_au = readCookie('_gcl_au');

      // Tempo no form (focus no primeiro campo até submit)
      t.form_focus_ts = null;
      t.fields_visited = 0;
      var visited = {};
      document.addEventListener('focusin', function (e) {
        var el = e.target;
        if (el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.tagName === 'SELECT')) {
          if (!t.form_focus_ts) t.form_focus_ts = Date.now();
          if (el.name && !visited[el.name]) {
            visited[el.name] = true;
            t.fields_visited++;
          }
        }
      });

      // Páginas nesta sessão (sessionStorage mantido por outro módulo)
      try {
        var history = JSON.parse(sessionStorage.getItem('adspirit_history') || '[]');
        t.pages_in_session = history.length;
      } catch (e) {}

      function readBehavior() {
        try {
          var raw = sessionStorage.getItem('adspirit_bhv_v1') || '';
          if (!raw) return '';
          // cap 16KB (alinhado com BEHAVIOR_PAYLOAD_MAX_BYTES server-side)
          if (raw.length > 16384) return '';
          return raw;
        } catch (e) { return ''; }
      }

      attachHidden = function () {
        document.querySelectorAll(SELECTOR).forEach(function (form) {
          if (form.dataset.adspiritAttached) return;
          form.dataset.adspiritAttached = '1';
          var fields = ['visitor_id', 'session_id', 'fbp', 'fbc', 'ga', 'gid', 'gcl_au',
                        'locale', 'timezone', 'color_scheme', 'screen', 'viewport', 'connection_type',
                        'landing_page', 'conversion_page', 'referrer', 'first_seen_at', 'last_seen_at',
                        'utm_first', 'utm_last',
                        'fbclid', 'gclid', 'gbraid', 'wbraid', 'li_fat_id', 'ttclid'];
          fields.forEach(function (name) {
            var input = document.createElement('input');
            input.type = 'hidden';
            input.name = '_adspirit_t_' + name;
            input.value = '';
            form.appendChild(input);
          });
          form.addEventListener('submit', function () {
            // Snapshot da atribuição do pixel NO MOMENTO do submit.
            var attr = readPixelAttr();
            var ci = attr.click_ids || {};
            t.landing_page = attr.landing_page || '';
            t.conversion_page = location.href;
            t.referrer = (attr.first_referrer != null ? attr.first_referrer : (document.referrer || ''));
            t.first_seen_at = attr.first_seen_at || '';
            t.last_seen_at = attr.last_seen_at || '';
            t.utm_first = attr.utm_first ? JSON.stringify(attr.utm_first) : '';
            t.utm_last = attr.utm_last ? JSON.stringify(attr.utm_last) : '';
            t.fbclid = ci.fbclid || ''; t.gclid = ci.gclid || ''; t.gbraid = ci.gbraid || '';
            t.wbraid = ci.wbraid || ''; t.li_fat_id = ci.li_fat_id || ''; t.ttclid = ci.ttclid || '';
            if (!t.visitor_id) t.visitor_id = readCookie('_dosvi') || readCookie('adspirit_vid') || '';
            if (!t.session_id) t.session_id = readCookie('_dossi') || readCookie('adspirit_sid') || '';
            // 2026-08-28 — A MESMA corrida do visitor_id, um nível acima e
            // nunca corrigida: o snapshot destes cookies é tirado no init do
            // collector, mas as tags do Meta/Google gravam `_fbp`, `_gcl_au` e
            // `_ga` MILISSEGUNDOS depois. Quem converte na primeira visita
            // saía sem nenhum sinal de match.
            //
            // Medido em produção (28/08): `fbp` chegava em 8 de 263 conversões
            // da Digitals — 3%. Era a causa do CAPI do Google recusar com
            // "user_identifiers vazio + sem gclid — Google exige >=1 sinal", e
            // do match do Meta cair pra e-mail/telefone puros.
            if (!t.fbp) t.fbp = readCookie('_fbp');
            if (!t.fbc) t.fbc = readCookie('_fbc');
            if (!t.ga) t.ga = readCookie('_ga');
            if (!t.gid) t.gid = readCookie('_gid');
            if (!t.gcl_au) t.gcl_au = readCookie('_gcl_au');
            // Sem cookie `_fbc` (clique que caiu numa página sem o pixel, ou
            // bloqueio de terceiros), a Meta manda DERIVAR do fbclid da URL.
            // Formato oficial: fb.1.<timestamp>.<fbclid>.
            if (!t.fbc && t.fbclid) t.fbc = 'fb.1.' + Date.now() + '.' + t.fbclid;
            fields.forEach(function (name) {
              var el = form.querySelector('input[name="_adspirit_t_' + name + '"]');
              if (el) el.value = String(t[name] || '');
            });
            var i = document.createElement('input');
            i.type = 'hidden'; i.name = '_adspirit_t_time_on_page_ms'; i.value = String(Date.now() - t.start_ts);
            form.appendChild(i);
            var j = document.createElement('input');
            j.type = 'hidden'; j.name = '_adspirit_t_time_in_form_ms';
            j.value = String(t.form_focus_ts ? (Date.now() - t.form_focus_ts) : 0);
            form.appendChild(j);
            var k = document.createElement('input');
            k.type = 'hidden'; k.name = '_adspirit_t_fields_visited'; k.value = String(t.fields_visited);
            form.appendChild(k);
            var l = document.createElement('input');
            l.type = 'hidden'; l.name = '_adspirit_t_pages_in_session'; l.value = String(t.pages_in_session || 1);
            form.appendChild(l);
            var bhv = document.createElement('input');
            bhv.type = 'hidden'; bhv.name = '_adspirit_t_behavior';
            bhv.value = readBehavior();
            form.appendChild(bhv);
          });
        });
      };
    })();
  } catch (e) { /* silenciado */ }

  /* ───────────── Um attach, um observer ───────────── */
  function attachAll() {
    try { attachAttr(); } catch (e) {}
    try { attachHidden(); } catch (e) {}
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', attachAll);
  } else {
    attachAll();
  }
  if (typeof MutationObserver !== 'undefined' && document.body) {
    new MutationObserver(attachAll).observe(document.body, { childList: true, subtree: true });
  }
})();
