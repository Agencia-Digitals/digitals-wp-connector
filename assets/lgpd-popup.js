/* AdSpirit LGPD — comportamento do aviso de cookies.
 * v2.30 "inline → arquivos": porte byte-equivalente do <script> do wp_footer.
 * Mesmo cookie (via cfg), mesmo timer de 10s, mesmo consentimento implícito
 * (scroll >140px ou clique fora), mesmo global adspiritLgpdOk() do onclick.
 * Config: window.__adspiritLgpdCfg = { cookie: 'adspirit_consent' }.
 */
(function () {
  'use strict';
  var COOKIE = (window.__adspiritLgpdCfg || {}).cookie || 'adspirit_consent';
  var BANNER = document.getElementById('adspirit-lgpd');
  if (!BANNER) return;

  // Quem decide se o aviso aparece é o NAVEGADOR, não o HTML. Com cache de
  // página, o servidor responde a mesma cópia pra todo mundo: se a decisão
  // morasse lá, a escolha do primeiro visitante viraria a de todos — e o
  // aviso reapareceria a cada página pra quem já aceitou. O cookie é local,
  // então ler aqui é o único lugar em que a resposta é sobre ESTA pessoa.
  function jaDecidiu() {
    var nome = String(COOKIE).replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
    return new RegExp('(?:^|;\\s*)' + nome + '=').test(document.cookie);
  }
  if (jaDecidiu()) {
    // Some com a marcação: ela veio no HTML cacheado, mas pra esta pessoa
    // não tem função. Nasce `visibility:hidden`, então nada piscou.
    if (BANNER.parentNode) BANNER.parentNode.removeChild(BANNER);
    return;
  }

  var done = false;
  function consent(reload) {
    if (done) return; done = true;
    var d = new Date(); d.setTime(d.getTime() + 365 * 86400000);
    document.cookie = COOKIE + '=accept_all;expires=' + d.toUTCString() + ';path=/;samesite=lax';
    BANNER.style.opacity = '0';
    setTimeout(function () { if (BANNER && BANNER.parentNode) BANNER.parentNode.removeChild(BANNER); }, 320);
    window.removeEventListener('scroll', onScroll);
    document.removeEventListener('click', onClick, true);
    // SEM reload: recarregar apagava o formulário em preenchimento.
    // O coletor de telemetria agora está sempre presente (legítimo
    // interesse), então tracking não depende de reload pós-consent. §126
  }
  // Consentimento implícito: ao continuar navegando (scroll, clique
  // em qualquer lugar fora do banner, ou navegação) o visitante aceita.
  function onScroll() { if ((window.pageYOffset || document.documentElement.scrollTop) > 140) consent(false); }
  function onClick(e) { if (BANNER.contains(e.target)) return; consent(false); }
  // Botão "Entendi": registra e fecha o banner SEM reload (preserva o
  // formulário). consent(reload) mantém a assinatura; passamos false.
  window.adspiritLgpdOk = function () { consent(false); };
  // Entra só 10s depois do load (entrada estilo login). Os listeners de
  // consentimento implícito só armam quando o banner aparece — antes
  // disso não há aviso, então não há consentimento a registrar.
  setTimeout(function () {
    if (done) return;
    BANNER.classList.add('adspirit-lgpd-show');
    window.addEventListener('scroll', onScroll, { passive: true });
    document.addEventListener('click', onClick, true);
  }, 10000);
})();
