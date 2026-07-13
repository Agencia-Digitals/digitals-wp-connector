/* AdSpirit Qualifier Form — runtime do form multi-step.
 * Lê config injetada em window.AdSpiritQualifierCfg.
 * 11 etapas + intro + success com countdown.
 * Persiste em sessionStorage entre reloads.
 */
(function () {
  'use strict';

  if (window.__adspiritQfInit) return;
  window.__adspiritQfInit = true;

  var CFG = window.AdSpiritQualifierCfg || {};
  var STORAGE_KEY = 'adspirit_qf_v1';

  // --- STEPS DEFINITION ---
  var STEPS = [
    {
      isIntro: true,
      eyebrow: 'Avaliação para novos clientes',
      title: 'Preencha os seus dados',
      sub: 'A Digitals trabalha com um número limitado de novos clientes a cada ciclo. Vamos avaliar o fit estratégico entre o seu negócio e o nosso modelo de crescimento. Avaliação individual, leva poucos minutos.',
    },
    {
      eyebrow: 'identificação',
      title: 'Seu nome',
      fields: [
        { key: 'first_name', type: 'text', placeholder: 'Nome', required: true },
        { key: 'last_name', type: 'text', placeholder: 'Sobrenome', required: true },
      ],
    },
    {
      eyebrow: 'contato',
      title: 'Email e WhatsApp',
      capturePartial: true, // ao passar daqui, dispara lead PARCIAL pro CRM
      fields: [
        { key: 'email', type: 'email', placeholder: 'Email corporativo', required: true },
        { key: 'phone', type: 'tel', placeholder: 'WhatsApp com DDD', required: true },
      ],
    },
    {
      eyebrow: 'presença online',
      title: 'Seu Instagram',
      sub: 'Ou outra rede social onde a empresa está presente (LinkedIn, TikTok…).',
      fields: [
        { key: 'instagram', type: 'text', placeholder: '@ ou link do perfil', required: true },
      ],
    },
    {
      eyebrow: 'presença online',
      title: 'Seu site',
      sub: 'Campo opcional — nem toda empresa tem site.',
      optional: true,
      fields: [
        { key: 'site', type: 'text', placeholder: 'Site da empresa (opcional)', required: false },
      ],
    },
    {
      eyebrow: 'empresa',
      title: 'Nome da empresa',
      fields: [{ key: 'company', type: 'text', placeholder: 'Razão social ou nome fantasia', required: true }],
    },
    {
      eyebrow: 'cargo',
      title: 'Seu cargo ou ocupação',
      fieldKey: 'role',
      choices: [
        { label: 'Sócio, fundador ou CEO', meta: 'decisor direto', kbd: 'A' },
        { label: 'Diretor ou C-level', meta: 'CFO, CMO, COO, CTO', kbd: 'B' },
        { label: 'Gerente ou Head', kbd: 'C' },
        { label: 'Coordenador, analista ou especialista', kbd: 'D' },
        { label: 'Outro', kbd: 'E' },
      ],
    },
    {
      eyebrow: 'porte',
      title: 'Tamanho da empresa',
      fieldKey: 'size',
      choices: [
        { label: 'Empreendedor individual', kbd: 'A' },
        { label: 'Até 5 pessoas', kbd: 'B' },
        { label: '6 a 20 pessoas', kbd: 'C' },
        { label: '21 a 50 pessoas', kbd: 'D' },
        { label: '51 a 200 pessoas', kbd: 'E' },
        { label: 'Mais de 200 pessoas', kbd: 'F' },
      ],
    },
    {
      eyebrow: 'mercado',
      title: 'Setor de atuação',
      fieldKey: 'market',
      choices: [
        { label: 'B2B high-ticket', meta: 'SaaS, consultoria, serviços especializados', kbd: 'A' },
        { label: 'Saúde, beleza e bem-estar', kbd: 'B' },
        { label: 'Engenharia, construção, indústria', kbd: 'C' },
        { label: 'Mercado financeiro', kbd: 'D' },
        { label: 'Educação', kbd: 'E' },
        { label: 'E-commerce', kbd: 'F' },
        { label: 'Outro', kbd: 'G' },
      ],
    },
    {
      eyebrow: 'experiência',
      title: 'Tem time interno de marketing ou já trabalhou com agência?',
      fieldKey: 'experience',
      choices: [
        { label: 'Sim', kbd: 'A' },
        { label: 'Não', kbd: 'B' },
      ],
    },
    {
      eyebrow: 'faturamento',
      title: 'Faturamento mensal',
      fieldKey: 'revenue',
      choices: [
        { label: 'Até R$ 50 mil', kbd: 'A' },
        { label: 'R$ 50 mil – R$ 200 mil', kbd: 'B' },
        { label: 'R$ 200 mil – R$ 1 milhão', kbd: 'C' },
        { label: 'R$ 1 milhão – R$ 5 milhões', kbd: 'D' },
        { label: 'Acima de R$ 5 milhões', kbd: 'E' },
      ],
    },
    {
      eyebrow: 'investimento',
      title: 'Quanto você costuma investir em Tráfego Pago?',
      fieldKey: 'investment',
      choices: [
        { label: 'Nunca investi em marketing', kbd: 'A' },
        { label: 'R$ 1 mil – R$ 3 mil', kbd: 'B' },
        { label: 'R$ 3 mil – R$ 5 mil', kbd: 'C' },
        { label: 'R$ 5 mil – R$ 10 mil', kbd: 'D' },
        { label: 'Acima de R$ 10 mil mensal', kbd: 'E' },
        { label: 'Acima de R$ 20 mil mensal', kbd: 'F' },
        { label: 'Não sei dizer, não sou responsável por essa área', kbd: 'G' },
      ],
    },
    {
      eyebrow: 'urgência',
      title: 'Quando pretende começar o trabalho com a agência?',
      fieldKey: 'timing',
      choices: [
        { label: 'O quanto antes', kbd: 'A' },
        { label: 'Nos próximos 30 dias', kbd: 'B' },
        { label: 'Daqui a 90 dias', kbd: 'C' },
        { label: 'Só estou avaliando as possibilidades', kbd: 'D' },
      ],
    },
    {
      eyebrow: 'contexto',
      title: 'Motivo do contato',
      sub: 'Campo opcional.',
      optional: true,
      fields: [{ key: 'pain', type: 'textarea', placeholder: 'O que motivou a busca e o que espera resolver.', required: false }],
    },
    { isSuccess: true },
  ];

  // --- STATE ---
  var state = {
    currentStep: 0,
    responses: {},
  };

  function loadState() {
    try {
      var raw = sessionStorage.getItem(STORAGE_KEY);
      if (raw) {
        var parsed = JSON.parse(raw);
        if (parsed && typeof parsed.responses === 'object') {
          state.responses = parsed.responses;
        }
      }
    } catch (e) {}
  }
  function saveState() {
    try {
      sessionStorage.setItem(STORAGE_KEY, JSON.stringify({ responses: state.responses }));
    } catch (e) {}
  }
  function clearState() {
    try {
      sessionStorage.removeItem(STORAGE_KEY);
      sessionStorage.removeItem('adspirit_qf_sid'); // nova submissão = novo id
    } catch (e) {}
    state.responses = {};
    state.currentStep = 0;
    partialSent = false; // permite novo parcial num form seguinte na sessão
  }

  // --- DOM HELPERS ---
  function escapeHtml(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }

  function renderInput(f, value) {
    var v = value == null ? '' : value;
    if (f.type === 'textarea') {
      return '<textarea class="adspirit-qf-input" data-key="' + escapeHtml(f.key) + '" placeholder="' + escapeHtml(f.placeholder) + '">' + escapeHtml(v) + '</textarea>';
    }
    return '<input class="adspirit-qf-input" type="' + escapeHtml(f.type) + '" data-key="' + escapeHtml(f.key) + '" placeholder="' + escapeHtml(f.placeholder) + '" value="' + escapeHtml(v) + '"' + (f === STEPS[state.currentStep].fields[0] ? ' autofocus' : '') + '>';
  }

  function renderChoices(step) {
    var selected = state.responses[step.fieldKey] || '';
    return '<div class="adspirit-qf-choices">' + step.choices.map(function (c) {
      var sel = (c.label === selected) ? ' selected' : '';
      return '<div class="adspirit-qf-choice' + sel + '" data-label="' + escapeHtml(c.label) + '">' +
        '<div class="adspirit-qf-choice-content">' +
          '<div class="adspirit-qf-choice-label">' + escapeHtml(c.label) + '</div>' +
          (c.meta ? '<div class="adspirit-qf-choice-meta">' + escapeHtml(c.meta) + '</div>' : '') +
        '</div>' +
        '<span class="adspirit-qf-choice-kbd">' + escapeHtml(c.kbd) + '</span>' +
      '</div>';
    }).join('') + '</div>';
  }

  // --- RENDER ---
  // O shell (overlay + close + main + stage) é montado uma vez. Cada
  // navegação só troca o .adspirit-qf-step, com transição vertical estilo
  // Typeform: o step que sai desliza/some na direção do movimento e o que
  // entra vem do lado oposto — os dois animam ao mesmo tempo (cross-slide),
  // empilhados na mesma célula do grid .adspirit-qf-stage.
  var animating = false;

  function ensureShell(root) {
    if (root.querySelector('.adspirit-qf-stage')) return;
    // Modo "embed": form contido na seção (sem overlay full-screen). Os mesmos
    // steps/inputs/transições rodam dentro de um card dark .adspirit-qf-embed.
    if (root.getAttribute('data-mode') === 'embed') {
      root.innerHTML = '<div class="adspirit-qf-embed"><div class="adspirit-qf-stage"></div></div>';
      return;
    }
    // Modos popup/inline: overlay full-screen + botão fechar.
    root.innerHTML = '' +
      '<div class="adspirit-qf-overlay"></div>' +
      '<button class="adspirit-qf-close" aria-label="Fechar">' +
        '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>' +
      '</button>' +
      '<div class="adspirit-qf-main"><div class="adspirit-qf-stage"></div></div>';
    var closeBtn = root.querySelector('.adspirit-qf-close');
    var overlay = root.querySelector('.adspirit-qf-overlay');
    if (closeBtn) closeBtn.addEventListener('click', closePopup);
    if (overlay) overlay.addEventListener('click', closePopup);
  }

  function buildStepEl(step) {
    var el = document.createElement('div');
    el.className = 'adspirit-qf-step';
    if (step.isSuccess) el.innerHTML = renderSuccess();
    else if (step.isIntro) el.innerHTML = renderIntro(step);
    else el.innerHTML = renderStep(step);
    return el;
  }

  function afterMount(step, stepEl) {
    if (step.isSuccess) {
      var url = (window.AdSpiritQualifierLastResponse && window.AdSpiritQualifierLastResponse.redirect_url) || '';
      if (url) {
        startCountdown(5, url);
      } else {
        // Sem redirect_url (config qualifier inerte ou rule sem
        // destination). Esconde o bloco de countdown — sucesso fica
        // só com a mensagem.
        var block = stepEl.querySelector('.adspirit-qf-redirect-block');
        if (block) block.style.display = 'none';
      }
    }
    bindStepInner(stepEl);
  }
  // Foco separado do mount: só roda quando o step entra (não enquanto está
  // invisível/deslocado), com preventScroll pra não causar layout shift.
  function focusFirst(stepEl) {
    var inp = stepEl.querySelector('[autofocus]') || stepEl.querySelector('input.adspirit-qf-input, textarea.adspirit-qf-input');
    if (!inp) return;
    setTimeout(function () {
      try { inp.focus({ preventScroll: true }); } catch (e) { try { inp.focus(); } catch (e2) {} }
    }, 40);
  }

  function render(direction) {
    var step = STEPS[state.currentStep];
    var root = document.querySelector('.adspirit-qualifier-root');
    if (!root) return;
    ensureShell(root);
    var stage = root.querySelector('.adspirit-qf-stage');
    var main = root.querySelector('.adspirit-qf-main');
    if (!stage) return;
    if (main) main.scrollTop = 0;

    var prev = stage.querySelector('.adspirit-qf-step');
    var nextEl = buildStepEl(step);
    var back = direction === 'back';
    var reduce = false;
    try { reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches; } catch (e) {}

    // Primeiro mount (sem step anterior) ou reduced-motion: troca direta.
    if (!prev || reduce) {
      if (prev && prev.parentNode) stage.removeChild(prev);
      stage.appendChild(nextEl);
      if (!reduce) {
        nextEl.classList.add('qf-enter-from-right');
        void nextEl.offsetWidth;
        nextEl.classList.remove('qf-enter-from-right');
      }
      afterMount(step, nextEl);
      focusFirst(nextEl);
      return;
    }

    // SEQUENCIAL (sem embolar): o atual sai (slide+fade) e SÓ ENTÃO o novo
    // entra (slide+fade), ocupando o lugar. O novo é montado já invisível na
    // posição de entrada — a mudança de altura entre steps acontece "atrás"
    // da animação, então o layout shift não aparece.
    // Avançar (iOS-like) → novo entra pela direita, atual sai pela esquerda.
    animating = true;
    var enterClass = back ? 'qf-enter-from-left' : 'qf-enter-from-right';
    var leaveClass = back ? 'qf-leave-to-right' : 'qf-leave-to-left';

    // O novo NÃO é montado agora — montá-lo junto com o atual mudaria a altura
    // do stage e empurraria o atual durante a saída. Primeiro só sai o atual.
    prev.classList.add(leaveClass);

    var swapped = false;
    function onLeave(e) {
      if (e.target === prev && e.propertyName === 'opacity') swap();
    }
    function onEnter(e) {
      if (e.target === nextEl && e.propertyName === 'transform') {
        animating = false;
        nextEl.removeEventListener('transitionend', onEnter);
      }
    }
    function swap() {
      if (swapped) return;
      swapped = true;
      prev.removeEventListener('transitionend', onLeave);
      // Remove o atual e monta o novo no MESMO frame (síncrono): o browser não
      // chega a pintar o vão, então a troca de altura não vira shift visível.
      if (prev && prev.parentNode) prev.parentNode.removeChild(prev);
      nextEl.classList.add(enterClass);     // novo: invisível, deslocado pro lado
      stage.appendChild(nextEl);
      afterMount(step, nextEl);
      void nextEl.offsetWidth;
      nextEl.classList.remove(enterClass);  // novo entra (slide + fade in)
      focusFirst(nextEl);
      nextEl.addEventListener('transitionend', onEnter);
      setTimeout(function () { animating = false; }, 620); // fallback fim-da-entrada
    }
    prev.addEventListener('transitionend', onLeave);
    setTimeout(swap, 300); // fallback: inicia a entrada mesmo sem transitionend
  }

  function renderIntro(step) {
    return '' +
      '<p class="adspirit-qf-eyebrow">' + escapeHtml(step.eyebrow) + '</p>' +
      '<h1 class="adspirit-qf-title">' + escapeHtml(step.title) + '</h1>' +
      '<p class="adspirit-qf-sub">' + escapeHtml(step.sub) + '</p>' +
      '<div class="adspirit-qf-nav">' +
        '<span class="adspirit-qf-kbd-hint">Pressione <span class="adspirit-qf-kbd">Enter</span> pra começar</span>' +
        '<div class="adspirit-qf-nav-actions">' +
          '<button class="adspirit-qf-btn" data-action="next">' +
            '<span>Iniciar avaliação</span>' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>' +
          '</button>' +
        '</div>' +
      '</div>';
  }

  function renderStep(step) {
    var body = '';
    if (step.fields) {
      body = '<div class="adspirit-qf-inputs-group">' + step.fields.map(function (f) {
        return renderInput(f, state.responses[f.key]);
      }).join('') + '</div>';
    } else if (step.choices) {
      body = renderChoices(step);
    }
    var isFirst = state.currentStep <= 1;
    var isLast = state.currentStep === STEPS.length - 2;
    return '' +
      '<p class="adspirit-qf-eyebrow">' + escapeHtml(step.eyebrow) + '</p>' +
      '<h1 class="adspirit-qf-title">' + escapeHtml(step.title) + '</h1>' +
      (step.sub ? '<p class="adspirit-qf-sub">' + escapeHtml(step.sub) + '</p>' : '') +
      body +
      '<p class="adspirit-qf-error" id="adspirit-qf-error"></p>' +
      '<div class="adspirit-qf-nav">' +
        '<span class="adspirit-qf-kbd-hint">Pressione <span class="adspirit-qf-kbd">Enter</span> pra avançar</span>' +
        '<div class="adspirit-qf-nav-actions">' +
          '<button class="adspirit-qf-btn adspirit-qf-btn-back" data-action="back"' + (isFirst ? ' disabled' : '') + '>' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>' +
            '<span>Voltar</span>' +
          '</button>' +
          '<button class="adspirit-qf-btn" data-action="next">' +
            '<span>' + (isLast ? 'Enviar para análise' : 'Continuar') + '</span>' +
            '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>' +
          '</button>' +
        '</div>' +
      '</div>';
  }

  function renderSuccess() {
    return '' +
      '<div class="adspirit-qf-success-icon">' +
        '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.4" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>' +
      '</div>' +
      '<h1 class="adspirit-qf-title">Cadastro recebido.</h1>' +
      '<p class="adspirit-qf-sub">Sua solicitação foi registrada. Você será redirecionado em alguns instantes.</p>' +
      '<div class="adspirit-qf-redirect-block">' +
        '<div class="adspirit-qf-countdown">' +
          '<svg viewBox="0 0 48 48"><circle id="adspirit-qf-ring" cx="24" cy="24" r="22"/></svg>' +
          '<span id="adspirit-qf-num">5</span>' +
        '</div>' +
        '<div><div class="adspirit-qf-redirect-label">Redirecionamento em</div></div>' +
      '</div>';
  }

  // --- EVENTS ---
  // Liga eventos do step atual. close/overlay já são ligados uma vez no shell
  // (ensureShell), então aqui é só inputs, choices e nav — escopados ao stepEl.
  function bindStepInner(stepEl) {
    if (!stepEl) return;

    // Inputs (persist on type)
    stepEl.querySelectorAll('input.adspirit-qf-input, textarea.adspirit-qf-input').forEach(function (el) {
      el.addEventListener('input', function () {
        var key = el.getAttribute('data-key');
        if (key) {
          state.responses[key] = el.value;
          saveState();
        }
      });
    });

    // Choices
    stepEl.querySelectorAll('.adspirit-qf-choice').forEach(function (el) {
      el.addEventListener('click', function () {
        var step = STEPS[state.currentStep];
        if (!step.fieldKey) return;
        var label = el.getAttribute('data-label');
        stepEl.querySelectorAll('.adspirit-qf-choice').forEach(function (c) { c.classList.remove('selected'); });
        el.classList.add('selected');
        state.responses[step.fieldKey] = label;
        saveState();
        setTimeout(next, 240); // auto-advance
      });
    });

    // Nav buttons
    var nextBtn = stepEl.querySelector('[data-action="next"]');
    var backBtn = stepEl.querySelector('[data-action="back"]');
    if (nextBtn) nextBtn.addEventListener('click', next);
    if (backBtn) backBtn.addEventListener('click', back);
  }

  // --- VALIDATION ---
  function validateCurrent(root) {
    var step = STEPS[state.currentStep];
    if (step.isIntro || step.isSuccess || step.optional) return { ok: true };

    if (step.fields) {
      for (var i = 0; i < step.fields.length; i++) {
        var f = step.fields[i];
        if (!f.required) continue;
        var v = (state.responses[f.key] || '').trim();
        if (!v) {
          var el = root.querySelector('[data-key="' + f.key + '"]');
          return { ok: false, focus: el, msg: 'Preencha esse campo pra continuar' };
        }
        if (f.type === 'email' && !/^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(v)) {
          var el2 = root.querySelector('[data-key="' + f.key + '"]');
          return { ok: false, focus: el2, msg: 'Esse e-mail parece inválido' };
        }
        if (f.type === 'tel' && v.replace(/\D/g, '').length < 10) {
          var el3 = root.querySelector('[data-key="' + f.key + '"]');
          return { ok: false, focus: el3, msg: 'O telefone precisa ter DDD + número' };
        }
      }
    }
    // Regra "pelo menos um": ex. Instagram OU site (encorajamos os dois,
    // mas só exigimos que um esteja preenchido).
    if (step.requireOneOf && step.requireOneOf.length) {
      var anyFilled = step.requireOneOf.some(function (k) {
        return (state.responses[k] || '').trim() !== '';
      });
      if (!anyFilled) {
        var firstEl = root.querySelector('[data-key="' + step.requireOneOf[0] + '"]');
        return { ok: false, focus: firstEl, msg: step.requireOneOfMsg || 'Preencha pelo menos um desses campos' };
      }
    }
    if (step.choices && !state.responses[step.fieldKey]) {
      return { ok: false, msg: 'Selecione uma opção pra continuar' };
    }
    return { ok: true };
  }

  function showError(msg, el) {
    var root = document.querySelector('.adspirit-qualifier-root:not([hidden])');
    var errEl = root ? root.querySelector('#adspirit-qf-error') : null;
    if (errEl) {
      errEl.textContent = msg;
      errEl.classList.add('visible');
      clearTimeout(errEl._t);
      errEl._t = setTimeout(function () { errEl.classList.remove('visible'); }, 3500);
    }
    if (el && el.focus) el.focus();
  }

  // --- NAV ---
  function next() {
    if (submitting || animating) return;
    var root = document.querySelector('.adspirit-qualifier-root');
    var v = validateCurrent(root);
    if (!v.ok) { showError(v.msg, v.focus); return; }

    // Captura parcial: ao passar da etapa de contato (email+WhatsApp já
    // validados), dispara um lead PARCIAL pro CRM — fire-and-forget, não
    // bloqueia a navegação nem trata a resposta.
    var curStep = STEPS[state.currentStep];
    if (curStep && curStep.capturePartial) submitPartialToServer();

    // Se está no penúltimo step (último com pergunta), submete
    var isLastQuestion = state.currentStep === STEPS.length - 2;
    if (isLastQuestion) {
      submitToServer();
      return;
    }
    if (state.currentStep < STEPS.length - 1) {
      state.currentStep++;
      render('next');
    }
  }
  function back() {
    if (submitting || animating) return;
    if (state.currentStep > 0) {
      state.currentStep--;
      render('back');
    }
  }

  // --- SUBMIT ---
  // ID estável da submissão (mesmo entre parcial e final). Persiste em
  // sessionStorage pra sobreviver a reload. O parcial leva sufixo "-p" no
  // servidor; o CRM liga os dois pelo contato e promove o lead in-place.
  function qfSubmissionId() {
    try {
      var k = 'adspirit_qf_sid';
      var v = sessionStorage.getItem(k);
      if (!v) {
        v = 'q-' + Date.now() + '-' + Math.random().toString(36).slice(2, 10);
        sessionStorage.setItem(k, v);
      }
      return v;
    } catch (e) {
      return 'q-' + Date.now();
    }
  }

  // v2.9: timestamp do load + honeypot (vazio quando humano) — anti-spam
  // unificado lê do payload top-level _adspirit_ts e _adspirit_hp.
  var __adspiritQfStartTs = Date.now();
  function appendAntibotMeta(fd) {
    fd.append('_adspirit_ts', String(__adspiritQfStartTs));
    fd.append('_adspirit_hp', ''); // bot que parseia HTML preenche; humano não vê
    // v2.10: Turnstile token (se Turnstile ativo e widget renderizado)
    if (window.__adspiritTurnstileToken) {
      fd.append('_adspirit_turnstile', window.__adspiritTurnstileToken);
    }
  }

  // v2.10: monta widget Turnstile invisible quando configurado.
  // Cloudflare renderiza auto, callback salva token em window pra submit.
  // Token expira em ~5min; se expirar, callback executa de novo automaticamente.
  function mountTurnstile() {
    if (!CFG.turnstile || !CFG.turnstile.enabled || !CFG.turnstile.site_key) return;
    if (document.querySelector('.adspirit-qf-turnstile-mount')) return; // já montou
    var div = document.createElement('div');
    div.className = 'adspirit-qf-turnstile-mount';
    div.style.cssText = 'position:fixed; bottom:0; right:0; z-index:-1; visibility:hidden;';
    div.innerHTML = '<div class="cf-turnstile" data-sitekey="' + CFG.turnstile.site_key + '" data-callback="__adspiritTurnstileSuccess" data-size="invisible" data-action="qualifier"></div>';
    document.body.appendChild(div);
  }
  window.__adspiritTurnstileSuccess = function (token) {
    window.__adspiritTurnstileToken = token;
  };

  // Lead parcial: fire-and-forget após a etapa de contato. Manda o que já
  // foi preenchido + _adspirit_partial=1. Roda no máximo uma vez.
  var partialSent = false;
  function submitPartialToServer() {
    if (partialSent) return;
    partialSent = true;
    try {
      var fd = new FormData();
      fd.append('action', 'adspirit_qualifier_submit');
      fd.append('nonce', CFG.nonce || '');
      fd.append('submission_id', qfSubmissionId());
      fd.append('_adspirit_partial', '1');
      appendAntibotMeta(fd);
      Object.keys(state.responses).forEach(function (k) {
        fd.append('fields[' + k + ']', state.responses[k] || '');
      });
      fetch(CFG.ajax_url, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        keepalive: true,
      }).catch(function () {});
    } catch (e) { /* nunca quebra o fluxo do form */ }
  }

  function submitToServer() {
    if (submitting) return;
    submitting = true;
    var nextBtn = document.querySelector('[data-action="next"]');
    if (nextBtn) {
      nextBtn.setAttribute('disabled', 'disabled');
      nextBtn.querySelector('span').textContent = 'Enviando…';
    }
    var formData = new FormData();
    formData.append('action', 'adspirit_qualifier_submit');
    formData.append('nonce', CFG.nonce || '');
    formData.append('submission_id', qfSubmissionId());
    appendAntibotMeta(formData);
    Object.keys(state.responses).forEach(function (k) {
      formData.append('fields[' + k + ']', state.responses[k] || '');
    });

    var controller = new AbortController();
    window.__adspiritQfAbortController = controller;
    var timeoutId = setTimeout(function () { controller.abort(); }, 15000);

    fetch(CFG.ajax_url, { method: 'POST', body: formData, credentials: 'same-origin', signal: controller.signal })
      .then(function (r) {
        if (!r.ok) throw new Error('HTTP ' + r.status);
        var ct = r.headers.get('content-type') || '';
        if (ct.indexOf('application/json') === -1) throw new Error('not_json');
        return r.json();
      })
      .then(function (json) {
        clearTimeout(timeoutId);
        submitting = false;
        if (window.__adspiritQfAbortController === controller) window.__adspiritQfAbortController = null;
        if (!json || !json.success) {
          showError((json && json.data && json.data.error) ? ('Erro: ' + json.data.error) : 'Erro ao enviar. Tente novamente.', null);
          if (nextBtn) {
            nextBtn.removeAttribute('disabled');
            nextBtn.querySelector('span').textContent = 'Enviar para análise';
          }
          return;
        }
        // Sucesso — guarda response + avança
        window.AdSpiritQualifierLastResponse = json.data || {};
        clearState();
        state.currentStep = STEPS.length - 1;
        render('next');
      })
      .catch(function () {
        clearTimeout(timeoutId);
        submitting = false;
        if (window.__adspiritQfAbortController === controller) window.__adspiritQfAbortController = null;
        showError('Falha de conexão. Tente novamente.', null);
        if (nextBtn) {
          nextBtn.removeAttribute('disabled');
          nextBtn.querySelector('span').textContent = 'Enviar para análise';
        }
      });
  }

  // --- COUNTDOWN ---
  var countdownTimer = null;
  var submitting = false;
  function startCountdown(seconds, redirectUrl) {
    if (countdownTimer) clearInterval(countdownTimer);
    var remaining = seconds;
    var root = document.querySelector('.adspirit-qualifier-root:not([hidden])');
    var num = root ? root.querySelector('#adspirit-qf-num') : null;
    var ring = root ? root.querySelector('#adspirit-qf-ring') : null;
    if (!num || !ring) return;
    var circ = 2 * Math.PI * 22;
    ring.style.strokeDasharray = circ;
    ring.style.strokeDashoffset = 0;
    function tick() {
      num.textContent = remaining;
      ring.style.strokeDashoffset = circ * (1 - remaining / seconds);
      if (remaining <= 0) {
        clearInterval(countdownTimer);
        if (redirectUrl) window.location.href = redirectUrl;
      }
      remaining--;
    }
    tick();
    countdownTimer = setInterval(tick, 1000);
  }

  // --- POPUP OPEN/CLOSE ---
  function openPopup() {
    var root = document.querySelector('.adspirit-qualifier-root[data-mode="popup"]');
    if (!root) return;
    root.removeAttribute('hidden');
    document.body.style.overflow = 'hidden';
    loadState();
    state.currentStep = 0;
    render();
    mountTurnstile(); // v2.10: monta widget invisível pra capturar token
  }
  function closePopup() {
    var root = document.querySelector('.adspirit-qualifier-root[data-mode="popup"]');
    if (!root) return;
    root.setAttribute('hidden', 'hidden');
    document.body.style.overflow = '';
    if (countdownTimer) clearInterval(countdownTimer);
    if (window.__adspiritQfAbortController) {
      try { window.__adspiritQfAbortController.abort(); } catch (e) {}
      window.__adspiritQfAbortController = null;
    }
  }

  // --- KEYBOARD ---
  document.addEventListener('keydown', function (e) {
    var visible = document.querySelector('.adspirit-qualifier-root:not([hidden])');
    if (!visible) return;
    if (e.key === 'Escape') closePopup();
    if (e.key === 'Enter' && e.target.tagName !== 'TEXTAREA' && e.target.tagName !== 'BUTTON' && !e.isComposing) { e.preventDefault(); next(); }
  });

  // --- INIT ---
  function init() {
    try {
      if (!CFG.ajax_url || !CFG.nonce) {
        console.warn('[AdSpirit Qualifier] CFG ausente (ajax_url/nonce). Abortando init.');
        return;
      }
      // v2.10.3: só ativa runtime SE houver root do qualifier OU trigger
      // explícito na página. Se nenhum dos dois, NÃO adiciona event listener
      // global no document — evita qualquer interferência com forms de outros
      // plugins (CF7, etc) em sites onde o script foi enfileirado mas o
      // qualifier não é usado naquela página.
      var hasRoot = !!document.querySelector('.adspirit-qualifier-root');
      var hasTrigger = !!document.querySelector('.adspirit-qualifier-trigger, [data-adspirit-qualifier], a[href$="#adspirit-avaliacao"]');
      if (!hasRoot && !hasTrigger) {
        return; // página não usa o qualifier — silent no-op
      }
      // Trigger via delegação no document — cobre o botão do plugin E botões
      // próprios criados no page-builder (mesmo adicionados depois do load).
      document.addEventListener('click', function (e) {
        try {
          var el = e.target;
          if (el && el.nodeType === 3) el = el.parentElement; // text node → elemento
          var trigger = el && el.closest
            ? el.closest('.adspirit-qualifier-trigger, [data-adspirit-qualifier], a[href$="#adspirit-avaliacao"]')
            : null;
          if (!trigger) return;
          e.preventDefault();
          openPopup();
        } catch (e2) {
          console.warn('[AdSpirit Qualifier] click handler erro:', e2);
        }
      });
      // Inline (full-screen) e embed (contido) renderizam direto no load.
      var auto = document.querySelector('.adspirit-qualifier-root[data-mode="inline"], .adspirit-qualifier-root[data-mode="embed"]');
      if (auto) {
        loadState();
        render();
        mountTurnstile(); // v2.10
      }
    } catch (err) {
      // v2.10.3: try/catch top-level pra erro no qualifier NUNCA derrubar
      // outros scripts da página (CF7 submit, analytics, etc).
      console.error('[AdSpirit Qualifier] init erro:', err);
    }
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
