/**
 * AdSpirit Connector — Behavioral tracker (client-side).
 *
 * Vanilla JS, sem deps. Coleta sinais comportamentais por sessão e
 * envia em um único evento `behavior_summary` por flush.
 *
 * Decisões aprovadas:
 *   - type=custom + payload.kind='behavior_summary' (zero migration CRM)
 *   - Flush via navigator.sendBeacon (sobrevive pagehide)
 *   - Visitor_id lido do cookie adspirit_vid (mintado pelo pixel.js do CRM)
 *   - Snapshot duplicado em sessionStorage['adspirit_bhv_v1'] pro Telemetry merger
 *
 * Config injetada pelo PHP via wp_localize_script em `AdspiritBehavioralCfg`:
 *   { endpoint_url, pixel_token, flush_interval_ms, max_payload_bytes,
 *     track_rage_clicks, track_copy, track_typing, track_idle,
 *     event_kind, plugin_version }
 *
 * @package AdSpiritConnector
 */

(function () {
  'use strict';

  if (typeof window === 'undefined' || !window.AdspiritBehavioralCfg) return;
  // Guard contra dupla execução em reuso de bfcache
  if (window.__adspiritBehavioralRan) return;
  window.__adspiritBehavioralRan = true;

  var CFG = window.AdspiritBehavioralCfg;
  var STORAGE_KEY = 'adspirit_bhv_v1';
  var COOKIE_VISITOR = 'adspirit_vid';
  var IDLE_THRESHOLD_MS = 15000;
  var RAGE_WINDOW_MS = 1000;
  var RAGE_DISTANCE_PX = 50;
  var RAGE_MIN_CLICKS = 3;

  // ─────────────────────────────────────────────────────────
  // utils
  // ─────────────────────────────────────────────────────────
  function readCookie(name) {
    var parts = ('; ' + document.cookie).split('; ' + name + '=');
    if (parts.length === 2) return decodeURIComponent(parts.pop().split(';').shift());
    return '';
  }

  function viewportClass(w) {
    if (w < 640) return 'mobile';
    if (w < 1024) return 'tablet';
    return 'desktop';
  }

  function parseUtm() {
    var out = {};
    try {
      var sp = new URLSearchParams(window.location.search);
      ['utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term'].forEach(function (k) {
        var v = sp.get(k);
        if (v) out[k] = v;
      });
    } catch (_) {}
    return out;
  }

  function rIC(cb) {
    if (typeof window.requestIdleCallback === 'function') {
      window.requestIdleCallback(cb, { timeout: 500 });
    } else {
      setTimeout(cb, 0);
    }
  }

  function nowMs() {
    return (typeof performance !== 'undefined' && performance.now) ? performance.now() : Date.now();
  }

  // ─────────────────────────────────────────────────────────
  // state
  // ─────────────────────────────────────────────────────────
  var PAGE_LOAD_EPOCH_MS = Date.now();
  var PAGE_LOAD_PERF = nowMs();
  var sessionPages = (function () {
    try {
      var raw = sessionStorage.getItem('adspirit_history');
      if (!raw) {
        sessionStorage.setItem('adspirit_history', JSON.stringify([location.href]));
        return 1;
      }
      var arr = JSON.parse(raw);
      if (Array.isArray(arr)) {
        if (arr[arr.length - 1] !== location.href) {
          arr.push(location.href);
          sessionStorage.setItem('adspirit_history', JSON.stringify(arr.slice(-50)));
        }
        return arr.length;
      }
    } catch (_) {}
    return 1;
  })();

  var state = {
    scroll_max_pct: 0,
    time_on_page_ms: 0,
    time_active_ms: 0,
    time_idle_ms: 0,
    click_count: 0,
    rage_clicks: 0,
    copy_events: { count: 0, max_selection_length: 0 },
    paste_events: 0,
    tab_switches: 0,
    typing_keystrokes: 0,
    exit_intent: false,
    viewport: { w: window.innerWidth || 0, h: window.innerHeight || 0, class: viewportClass(window.innerWidth || 0) },
    pages_in_session: sessionPages,
    context: {
      url: location.href,
      referrer: document.referrer || '',
      utm: parseUtm()
    }
  };

  var dirty = false;
  function markDirty() { dirty = true; }

  // ─────────────────────────────────────────────────────────
  // time on page + active/idle (visibility-aware)
  // ─────────────────────────────────────────────────────────
  var visibleSinceMs = document.visibilityState === 'visible' ? nowMs() : null;
  var lastActivityMs = nowMs();

  function flushTimeAccumulators() {
    if (visibleSinceMs === null) return;
    var now = nowMs();
    var delta = now - visibleSinceMs;
    state.time_on_page_ms += delta;
    // active = porção em que houve atividade nos últimos 15s
    if (CFG.track_idle) {
      // Quantos ms desta janela tiveram atividade recente?
      // Aproximação: se a janela <= IDLE_THRESHOLD a partir da última atividade,
      // tudo conta como ativo; senão recorta na lastActivity + IDLE.
      var activeUntil = lastActivityMs + IDLE_THRESHOLD_MS;
      var activeEnd = Math.min(now, activeUntil);
      var activeStart = visibleSinceMs;
      var activeDelta = Math.max(0, activeEnd - activeStart);
      state.time_active_ms += activeDelta;
      state.time_idle_ms = Math.max(0, state.time_on_page_ms - state.time_active_ms);
    } else {
      state.time_active_ms = state.time_on_page_ms;
      state.time_idle_ms = 0;
    }
    visibleSinceMs = now;
    markDirty();
  }

  document.addEventListener('visibilitychange', function () {
    if (document.visibilityState === 'hidden') {
      flushTimeAccumulators();
      visibleSinceMs = null;
      state.tab_switches += 1;
      markDirty();
      flush('visibility');
    } else if (document.visibilityState === 'visible') {
      visibleSinceMs = nowMs();
      lastActivityMs = nowMs();
    }
  }, true);

  function recordActivity() {
    lastActivityMs = nowMs();
  }
  ['mousemove', 'keydown', 'scroll', 'touchstart', 'pointerdown'].forEach(function (evt) {
    window.addEventListener(evt, recordActivity, { passive: true, capture: true });
  });

  // ─────────────────────────────────────────────────────────
  // scroll depth (rAF throttled)
  // ─────────────────────────────────────────────────────────
  var scrollPending = false;
  function onScroll() {
    if (scrollPending) return;
    scrollPending = true;
    requestAnimationFrame(function () {
      scrollPending = false;
      var doc = document.documentElement;
      var sh = Math.max(doc.scrollHeight, document.body ? document.body.scrollHeight : 0);
      if (sh <= 0) return;
      var st = window.pageYOffset || doc.scrollTop || 0;
      var ch = window.innerHeight || doc.clientHeight || 0;
      var pct = Math.min(100, Math.round(((st + ch) / sh) * 100));
      if (pct > state.scroll_max_pct) {
        state.scroll_max_pct = pct;
        markDirty();
      }
    });
  }
  window.addEventListener('scroll', onScroll, { passive: true });

  // ─────────────────────────────────────────────────────────
  // clicks + rage clicks
  // ─────────────────────────────────────────────────────────
  var clickBuffer = [];
  document.addEventListener('click', function (e) {
    state.click_count += 1;
    if (CFG.track_rage_clicks) {
      var entry = { x: e.clientX || 0, y: e.clientY || 0, ts: nowMs() };
      clickBuffer.push(entry);
      // mantém só os últimos 5
      if (clickBuffer.length > 5) clickBuffer.shift();
      // detecta rage: 3+ cliques nos últimos 1000ms com distância <50px entre extremos
      var recent = clickBuffer.filter(function (c) { return entry.ts - c.ts <= RAGE_WINDOW_MS; });
      if (recent.length >= RAGE_MIN_CLICKS) {
        var first = recent[0];
        var maxDist = 0;
        for (var i = 1; i < recent.length; i++) {
          var dx = recent[i].x - first.x;
          var dy = recent[i].y - first.y;
          var d = Math.sqrt(dx * dx + dy * dy);
          if (d > maxDist) maxDist = d;
        }
        if (maxDist < RAGE_DISTANCE_PX) {
          state.rage_clicks += 1;
          clickBuffer = [];
        }
      }
    }
    markDirty();
  }, true);

  // ─────────────────────────────────────────────────────────
  // copy / paste / typing
  // ─────────────────────────────────────────────────────────
  if (CFG.track_copy) {
    document.addEventListener('copy', function () {
      try {
        var sel = window.getSelection ? String(window.getSelection() || '') : '';
        state.copy_events.count += 1;
        if (sel.length > state.copy_events.max_selection_length) {
          state.copy_events.max_selection_length = sel.length;
        }
        markDirty();
      } catch (_) {}
    }, true);

    document.addEventListener('paste', function (e) {
      var tag = (e.target && e.target.tagName) ? e.target.tagName.toUpperCase() : '';
      if (tag === 'INPUT' || tag === 'TEXTAREA') {
        state.paste_events += 1;
        markDirty();
      }
    }, true);
  }

  if (CFG.track_typing) {
    document.addEventListener('keydown', function (e) {
      var tag = (e.target && e.target.tagName) ? e.target.tagName.toUpperCase() : '';
      if (tag === 'INPUT' || tag === 'TEXTAREA') {
        state.typing_keystrokes += 1;
        // NÃO captura e.key nem e.target.value — privacy by design
        markDirty();
      }
    }, true);
  }

  // ─────────────────────────────────────────────────────────
  // exit intent (mouseleave clientY<=0)
  // ─────────────────────────────────────────────────────────
  document.addEventListener('mouseleave', function (e) {
    if (e.clientY <= 0 && !state.exit_intent) {
      state.exit_intent = true;
      markDirty();
    }
  }, true);

  // ─────────────────────────────────────────────────────────
  // viewport resize
  // ─────────────────────────────────────────────────────────
  window.addEventListener('resize', function () {
    state.viewport.w = window.innerWidth || 0;
    state.viewport.h = window.innerHeight || 0;
    state.viewport.class = viewportClass(state.viewport.w);
    markDirty();
  }, { passive: true });

  // ─────────────────────────────────────────────────────────
  // snapshot + serialization (with payload truncation)
  // ─────────────────────────────────────────────────────────
  function buildSnapshot() {
    flushTimeAccumulators();
    return {
      scroll_max_pct: state.scroll_max_pct,
      time_on_page_ms: Math.round(state.time_on_page_ms),
      time_active_ms: Math.round(state.time_active_ms),
      time_idle_ms: Math.round(state.time_idle_ms),
      click_count: state.click_count,
      rage_clicks: state.rage_clicks,
      copy_events: { count: state.copy_events.count, max_selection_length: state.copy_events.max_selection_length },
      paste_events: state.paste_events,
      tab_switches: state.tab_switches,
      typing_keystrokes: state.typing_keystrokes,
      exit_intent: state.exit_intent,
      viewport: { w: state.viewport.w, h: state.viewport.h, class: state.viewport.class },
      pages_in_session: state.pages_in_session,
      context: Object.assign({
        url: state.context.url,
        referrer: state.context.referrer
      }, state.context.utm || {})
    };
  }

  function persistSessionSnapshot(snapshot) {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify({
        v: 1,
        updated_at: Date.now(),
        snapshot: snapshot
      }));
    } catch (_) {}
  }

  function truncateForPayload(snapshot, maxBytes) {
    var json = JSON.stringify(snapshot);
    if (json.length <= maxBytes) return snapshot;
    var trimmed = JSON.parse(JSON.stringify(snapshot));
    // 1) trunca URL e referrer
    if (trimmed.context && trimmed.context.url && trimmed.context.url.length > 256) {
      trimmed.context.url = trimmed.context.url.slice(0, 256);
    }
    if (trimmed.context && trimmed.context.referrer && trimmed.context.referrer.length > 256) {
      trimmed.context.referrer = trimmed.context.referrer.slice(0, 256);
    }
    // 2) drop utm extras se ainda grande (utm_term, utm_content são os menos prioritários)
    json = JSON.stringify(trimmed);
    if (json.length > maxBytes && trimmed.context) {
      ['utm_term', 'utm_content'].forEach(function (k) {
        delete trimmed.context[k];
      });
    }
    return trimmed;
  }

  // ─────────────────────────────────────────────────────────
  // flush
  // ─────────────────────────────────────────────────────────
  var lastFlushSerialized = '';
  function flush(reason) {
    rIC(function () {
      try {
        var visitorId = readCookie(COOKIE_VISITOR);
        var snapshot = buildSnapshot();
        persistSessionSnapshot(snapshot);

        if (!visitorId) return; // pixel ainda não mintou — nada a enviar
        if (!CFG.endpoint_url || !CFG.pixel_token) return;

        var maxBytes = CFG.max_payload_bytes || 16384;
        var payload = truncateForPayload(snapshot, maxBytes);

        // ISO timestamp consistente: epoch do page load + delta perf
        var perfDelta = nowMs() - PAGE_LOAD_PERF;
        var tsIso = new Date(PAGE_LOAD_EPOCH_MS + perfDelta).toISOString();

        var event = {
          type: 'custom',
          payload: Object.assign({ kind: CFG.event_kind || 'behavior_summary' }, payload, {
            _meta: { reason: reason || 'interval', plugin_v: CFG.plugin_version || '' }
          }),
          ts: tsIso
        };

        var body = JSON.stringify({ visitor_id: visitorId, events: [event] });

        // Dedupe — não envia se nada mudou desde último flush
        if (body === lastFlushSerialized) return;
        lastFlushSerialized = body;
        dirty = false;

        var url = CFG.endpoint_url.replace(/\/+$/, '') + '/api/track?t=' + encodeURIComponent(CFG.pixel_token);

        if (navigator.sendBeacon) {
          var blob = new Blob([body], { type: 'application/json' });
          navigator.sendBeacon(url, blob);
        } else {
          // fallback fetch keepalive
          try {
            fetch(url, {
              method: 'POST',
              headers: { 'Content-Type': 'application/json' },
              body: body,
              keepalive: true,
              credentials: 'omit',
              mode: 'cors'
            }).catch(function () {});
          } catch (_) {}
        }
      } catch (_) {}
    });
  }

  // ─────────────────────────────────────────────────────────
  // triggers
  // ─────────────────────────────────────────────────────────
  var intervalMs = Math.max(5000, CFG.flush_interval_ms || 60000);
  setInterval(function () { if (dirty) flush('interval'); }, intervalMs);

  window.addEventListener('pagehide', function () { flush('pagehide'); }, { capture: true });
  window.addEventListener('beforeunload', function () { flush('beforeunload'); }, { capture: true });

  // Snapshot inicial em sessionStorage pra Telemetry merger ler logo
  rIC(function () { persistSessionSnapshot(buildSnapshot()); });

  // Expose mínimo pra debug (não enumerável)
  try {
    Object.defineProperty(window, '__adspiritBehavioral', {
      value: { snapshot: buildSnapshot, flush: flush, state: state },
      enumerable: false
    });
  } catch (_) {}
})();
