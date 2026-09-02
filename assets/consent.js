/* AdSpirit — consentimento, lido no navegador.
 *
 * POR QUE ISTO EXISTE
 *
 * O servidor sabe o que o visitante decidiu, mas com cache de página essa
 * resposta não chega a ele: a página é montada uma vez e a mesma cópia é
 * servida pra todo mundo. Uma decisão tomada no PHP vira, na prática, a
 * decisão do primeiro visitante aplicada a todos — e foi assim que o aviso
 * de cookies passou a reaparecer pra quem já tinha aceitado (02/09).
 *
 * O cookie é local. Ler aqui é o único lugar em que a resposta é sobre ESTA
 * pessoa, com cache ou sem cache. Espelha `AdSpirit_Lgpd_Popup::has_consent()`
 * byte a byte no formato: "accept_all" | "essential" | "custom:a,b".
 *
 * `onGrant` também resolve o caso de quem aceita AGORA: o rastreador começa
 * na mesma visita, sem esperar a próxima página.
 */
(function () {
  'use strict';

  var COOKIE = (window.__adspiritLgpdCfg || {}).cookie || 'adspirit_consent';

  function bruto() {
    var nome = String(COOKIE).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + nome + '=([^;]*)'));
    if (!m) return '';
    try { return decodeURIComponent(m[1]); } catch (e) { return m[1]; }
  }

  function has(categoria) {
    var raw = bruto();
    if (!raw) return false;
    if (raw === 'accept_all') return true;
    if (raw === 'essential') return categoria === 'essential';
    if (raw.indexOf('custom:') === 0) {
      var cats = raw.slice(7).split(',');
      for (var i = 0; i < cats.length; i++) if (cats[i] === categoria) return true;
      return false;
    }
    return false;
  }

  var fila = [];

  function onGrant(categoria, fn) {
    if (has(categoria)) { try { fn(); } catch (e) {} return; }
    fila.push({ cat: categoria, fn: fn });
  }

  document.addEventListener('adspirit:consent', function () {
    var pendentes = fila;
    fila = [];
    for (var i = 0; i < pendentes.length; i++) {
      if (has(pendentes[i].cat)) { try { pendentes[i].fn(); } catch (e) {} }
      else fila.push(pendentes[i]);
    }
  });

  window.AdSpiritConsent = { has: has, onGrant: onGrant, raw: bruto };
})();
