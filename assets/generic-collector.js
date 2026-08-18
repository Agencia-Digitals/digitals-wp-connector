/* AdSpirit generic collector — rede de segurança pra form de builder
 * desconhecido (padrão HubSpot "non-HubSpot forms"). Os hooks nativos
 * (CF7, AdSpirit, Gravity, WPForms) continuam PRIMÁRIOS: forms deles são
 * ignorados aqui. Beta, opt-in por site (default desligado).
 *
 * Captura no submit (fase de captura, não bloqueia nada): coleta campos
 * nomeados visíveis, exige cara de lead (e-mail válido OU telefone) e
 * manda via sendBeacon — zero impacto na navegação. Nunca lança erro.
 */
(function () {
  'use strict';
  if (window.__adspiritGenericCollector) return;
  window.__adspiritGenericCollector = true;

  var CFG = window.__adspiritGenericCfg || {};
  var ENDPOINT = CFG.endpoint || '';
  if (!ENDPOINT) return;

  // Forms já cobertos por integrações dedicadas — o coletor NUNCA toca.
  var KNOWN = 'form.wpcf7-form, form.adspirit-form, .gform_wrapper form, form.wpforms-form, form.adspirit-qf-form';
  // Forms que não são lead por natureza.
  var IGNORE = 'form[role="search"], form.search-form, form#loginform, form#commentform, form.woocommerce-cart-form, form.woocommerce-checkout';

  var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  var PHONE_RE = /^[\d\s()+.-]{8,20}$/;

  function harvest(form) {
    var fields = {};
    var count = 0;
    var els = form.querySelectorAll('input[name], select[name], textarea[name]');
    for (var i = 0; i < els.length; i++) {
      var el = els[i];
      var type = (el.type || '').toLowerCase();
      if (type === 'password' || type === 'hidden' || type === 'file' || type === 'submit' || type === 'button') continue;
      if ((type === 'checkbox' || type === 'radio') && !el.checked) continue;
      var v = String(el.value || '').trim();
      if (v === '' || v.length > 500) continue;
      var name = String(el.name).slice(0, 100);
      if (fields[name]) continue;
      fields[name] = { value: v.slice(0, 500), type: type };
      if (++count >= 30) break;
    }
    return fields;
  }

  function looksLikeLead(fields) {
    for (var k in fields) {
      var f = fields[k];
      var kl = k.toLowerCase();
      if (f.type === 'email' || EMAIL_RE.test(f.value)) return true;
      if ((f.type === 'tel' || /(phone|tel|whats|celular|fone)/.test(kl)) && PHONE_RE.test(f.value)) return true;
    }
    return false;
  }

  document.addEventListener('submit', function (e) {
    try {
      var form = e.target;
      if (!form || form.tagName !== 'FORM') return;
      if (form.matches(KNOWN) || form.matches(IGNORE)) return;
      if (form.dataset.adspiritGenericSent === '1') return;

      var fields = harvest(form);
      if (!looksLikeLead(fields)) return;
      form.dataset.adspiritGenericSent = '1';

      var flat = {};
      for (var k in fields) flat[k] = fields[k].value;
      var payload = {
        action: 'adspirit_generic_capture',
        page: String(location.href).split('#')[0].slice(0, 300),
        form_hint: (form.id || form.className || '').toString().slice(0, 120),
        fields: flat,
      };
      var body = new Blob([JSON.stringify(payload)], { type: 'text/plain;charset=UTF-8' });
      if (navigator.sendBeacon) {
        navigator.sendBeacon(ENDPOINT + '?action=adspirit_generic_capture', body);
      } else {
        var xhr = new XMLHttpRequest();
        xhr.open('POST', ENDPOINT + '?action=adspirit_generic_capture', true);
        xhr.setRequestHeader('Content-Type', 'text/plain;charset=UTF-8');
        xhr.send(JSON.stringify(payload));
      }
    } catch (err) { /* rede de segurança nunca quebra o submit do site */ }
  }, true);
})();
