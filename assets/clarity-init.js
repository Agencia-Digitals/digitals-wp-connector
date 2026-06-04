/**
 * AdSpirit Connector — Microsoft Clarity init bridge.
 *
 * Espera o tag Clarity (window.clarity) carregar, então:
 *   1) Propaga custom tags com cookies AdSpirit (visitor_id, brand_slug,
 *      session_id, consent_level, ab_variant).
 *   2) Em wpcf7mailsent / adspirit:form-submitted, chama clarity('identify').
 *
 * Config injetada via wp_localize_script em window.AdSpiritClarityCfg:
 *   - identify_on_lead (bool)
 *   - share_visitor_id (bool)
 *   - hash_email       (bool)
 *
 * Zero deps. Não polui escopo global (IIFE).
 */
(function () {
  'use strict';

  var cfg = (typeof window !== 'undefined' && window.AdSpiritClarityCfg) || {};

  function readCookie(name) {
    if (typeof document === 'undefined' || !document.cookie) return '';
    var parts = document.cookie.split(';');
    for (var i = 0; i < parts.length; i++) {
      var s = parts[i].replace(/^\s+/, '');
      if (s.indexOf(name + '=') === 0) {
        try { return decodeURIComponent(s.substring(name.length + 1)); }
        catch (_) { return s.substring(name.length + 1); }
      }
    }
    return '';
  }

  /**
   * Procura o primeiro cookie no formato adspirit_ab_variant_* e devolve
   * "experiment:variant" (slug). Vazio se não houver.
   */
  function extractFirstAbVariantCookie() {
    if (typeof document === 'undefined' || !document.cookie) return '';
    var parts = document.cookie.split(';');
    for (var i = 0; i < parts.length; i++) {
      var s = parts[i].replace(/^\s+/, '');
      var eq = s.indexOf('=');
      if (eq <= 0) continue;
      var k = s.substring(0, eq);
      if (k.indexOf('adspirit_ab_variant_') === 0) {
        var exp = k.substring('adspirit_ab_variant_'.length);
        var v = '';
        try { v = decodeURIComponent(s.substring(eq + 1)); } catch (_) { v = s.substring(eq + 1); }
        return exp + ':' + v;
      }
    }
    return '';
  }

  function waitForClarity(cb) {
    var attempts = 0;
    var max = 25; // 5s a 200ms
    var iv = setInterval(function () {
      attempts++;
      if (typeof window.clarity === 'function') {
        clearInterval(iv);
        cb(window.clarity);
        return;
      }
      if (attempts >= max) {
        clearInterval(iv);
      }
    }, 200);
  }

  function setCustomTags(clarity) {
    try {
      if (cfg.share_visitor_id) {
        var vid = readCookie('adspirit_vid');
        if (vid) clarity('set', 'visitor_id', vid);
      }
      var brand = readCookie('adspirit_brand') || '';
      clarity('set', 'brand_slug', brand);

      var sid = readCookie('adspirit_sid') || '';
      clarity('set', 'session_id', sid);

      var consent = readCookie('adspirit_consent') || 'essential';
      clarity('set', 'consent_level', consent);

      var ab = extractFirstAbVariantCookie();
      if (ab) clarity('set', 'ab_variant', ab);
    } catch (e) {
      if (window.console && console.warn) console.warn('[AdSpirit Clarity] setCustomTags failed:', e);
    }
  }

  // ─────────────────────────────────────────────────────────
  // IDENTIFY on form submit
  // ─────────────────────────────────────────────────────────
  function sanitizeName(raw) {
    if (!raw) return '';
    var s = String(raw).replace(/[<>"']/g, '').trim();
    if (s.length > 60) s = s.substring(0, 60);
    return s;
  }

  function findEmailInForm(formEl) {
    if (!formEl) return '';
    var input = formEl.querySelector('input[type=email]');
    if (input && input.value) return String(input.value).trim().toLowerCase();
    // Fallback: campos comuns
    var fallback = formEl.querySelector('input[name=your-email], input[name=email], input[name=Email]');
    if (fallback && fallback.value) return String(fallback.value).trim().toLowerCase();
    return '';
  }

  function findNameInForm(formEl) {
    if (!formEl) return '';
    var selectors = ['input[name=your-name]', 'input[name=name]', 'input[name=nome]', 'input[name=fullname]', 'input[name=Nome]'];
    for (var i = 0; i < selectors.length; i++) {
      var el = formEl.querySelector(selectors[i]);
      if (el && el.value) return sanitizeName(el.value);
    }
    return '';
  }

  /**
   * SHA-256 hex slice(0,16) via SubtleCrypto. Retorna Promise<string>.
   * Se SubtleCrypto não estiver disponível (HTTP / browsers velhos), resolve
   * pra string vazia — caller decide o que fazer.
   */
  function sha256Short(input) {
    if (typeof window.crypto === 'undefined' || !window.crypto.subtle || !window.TextEncoder) {
      return Promise.resolve('');
    }
    try {
      var data = new TextEncoder().encode(String(input));
      return window.crypto.subtle.digest('SHA-256', data).then(function (buf) {
        var bytes = new Uint8Array(buf);
        var hex = '';
        for (var i = 0; i < bytes.length; i++) {
          var h = bytes[i].toString(16);
          if (h.length === 1) h = '0' + h;
          hex += h;
        }
        return hex.slice(0, 16);
      }).catch(function () { return ''; });
    } catch (_) {
      return Promise.resolve('');
    }
  }

  function doIdentify(clarity, email, name) {
    if (!email) return;
    var sid = readCookie('adspirit_sid') || '';
    var pageId = (typeof location !== 'undefined' && location.pathname) ? location.pathname : '/';

    var run = function (identifier) {
      if (!identifier) return;
      try {
        clarity('identify', identifier, sid, pageId, name || undefined);
      } catch (e) {
        if (window.console && console.warn) console.warn('[AdSpirit Clarity] identify failed:', e);
      }
    };

    if (cfg.hash_email) {
      sha256Short(email).then(function (hashed) {
        if (!hashed) {
          // Admin pediu hash_email=1 explicitamente — não degrada
          // silenciosamente pra cleartext. Aborta identify e avisa.
          if (window.console && console.warn) {
            console.warn('[AdSpirit Clarity] identify aborted: SubtleCrypto unavailable and hash_email=1 (PII not sent).');
          }
          return;
        }
        run(hashed);
      });
    } else {
      run(email);
    }
  }

  function onFormSubmitted(formEl) {
    if (!cfg.identify_on_lead) return;
    var email = findEmailInForm(formEl);
    if (!email) return;
    var name = findNameInForm(formEl);
    if (typeof window.clarity === 'function') {
      doIdentify(window.clarity, email, name);
    } else {
      waitForClarity(function (c) { doIdentify(c, email, name); });
    }
  }

  // CF7 nativo
  document.addEventListener('wpcf7mailsent', function (e) {
    var formEl = (e && e.target) ? e.target : null;
    onFormSubmitted(formEl);
  });

  // [adspirit_form] shortcode emite custom event
  window.addEventListener('adspirit:form-submitted', function (e) {
    var formEl = (e && e.detail && e.detail.formEl) ? e.detail.formEl
              : (e && e.target) ? e.target
              : null;
    onFormSubmitted(formEl);
  });

  // Boot: assim que Clarity carregar, propaga custom tags. Roda 1x.
  waitForClarity(setCustomTags);
})();
