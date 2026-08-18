/* AdSpirit lead status — personalização pra visitante conhecido.
 * Carregado SÓ quando o cookie de identidade existe (submit anterior).
 * Aplica classes no <html> e expõe window.__adspiritLead — sem PII:
 * só flags (known/customer) e perfil. Cache frio → 1 chamada AJAX
 * (throttle 1h por localStorage) que aquece o cache do servidor.
 *
 * Supressão via CSS (exemplos):
 *   .adspirit-lead-customer .minha-cta-captacao { display: none; }
 *   .adspirit-lead-profile-a .oferta-premium { display: block; }
 */
(function () {
  'use strict';
  var CFG = window.__adspiritLeadCfg || {};

  function apply(st) {
    if (!st || !st.known) return;
    try {
      var el = document.documentElement;
      el.classList.add('adspirit-lead-known');
      if (st.customer) el.classList.add('adspirit-lead-customer');
      if (st.profile) el.classList.add('adspirit-lead-profile-' + String(st.profile).toLowerCase());
      window.__adspiritLead = {
        known: true,
        customer: !!st.customer,
        profile: st.profile || '',
      };
      document.dispatchEvent(new CustomEvent('adspirit:lead', { detail: window.__adspiritLead }));
    } catch (e) { /* personalização nunca quebra o site */ }
  }

  if (CFG.cached) {
    apply(CFG.cached);
    return;
  }
  if (!CFG.endpoint) return;

  // Throttle: 1 tentativa por hora por browser (o server também cacheia).
  try {
    var last = parseInt(localStorage.getItem('adspirit_ls_at') || '0', 10);
    if (last && Date.now() - last < 3600000) return;
    localStorage.setItem('adspirit_ls_at', String(Date.now()));
  } catch (e) { /* sem localStorage, segue */ }

  var xhr = new XMLHttpRequest();
  xhr.open('POST', CFG.endpoint, true);
  xhr.setRequestHeader('Content-Type', 'application/x-www-form-urlencoded');
  xhr.onload = function () {
    try {
      var res = JSON.parse(xhr.responseText || '{}');
      if (res && res.success && res.data) apply(res.data);
    } catch (e) { /* silencioso */ }
  };
  xhr.send('action=adspirit_lead_status');
})();
