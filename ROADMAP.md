# AdSpirit Connector — Roadmap 3.0

Backlog derivado do estudo completo de 2026-08-17 (inventário dos 43 arquivos +
benchmark de Gravity/WPForms/Fluent + análise de 16 pares de mercado). Fontes
completas nos artifacts "Connector 3.0", "Paredes-Mestras" e "Pares do
Connector" (galeria do Pedro). **Regra de ouro: nada destrutivo — mudança
aditiva atrás de condição; ler PAREDES-MESTRAS antes de mexer; deploy CRM
antes de release do plugin.**

## Feito (2026-08-22) — conversão: smart default + arranque de progresso

Pedido do Pedro: verificar se usamos Smart Default, Endowed Progress e Ikea
Effect no motor de wizard e no form nativo. Diagnóstico e o que entrou:

- [x] **`autocomplete` nos campos de identidade** (form nativo + qualifier).
      Não emitíamos nenhum — o browser e o iOS não ofereciam o preenchimento
      que já têm guardado. Mapa espelhado em PHP (`autocomplete_token`) e JS
      (`AC_MAP`), cobrindo canônicos das paredes-mestras
      (`your-name`/`your-email`/`Telefone`) e os do qualifier
      (`first_name`/`last_name`/`phone`/`company`). `name` do input não muda.
- [x] **Barra de progresso com arranque** no form nativo, a partir de 4
      etapas. Abaixo disso mantém os segmentos, inalterado.
      Usa arranque + **linear**, não a curva `pow(0.6)` do wizard do CRM:
      ela foi calibrada pra ~50 passos e marcaria 48% no passo 1 de 4,
      contradizendo o "Etapa 1 de 4" renderizado logo abaixo.

**Régua registrada — smart default só em campo de IDENTIDADE.** Nome, e-mail,
telefone, empresa, cargo, cidade. Campo de JULGAMENTO (faturamento,
investimento, urgência, perfil) **nunca** recebe sugestão nem opção
pré-selecionada: ganha conversão e perde qualidade de lead, e o lead é o
produto.

Já existia e foi confirmado: arranque de 8% + `pow(0.6)` no
`wizard-shell.tsx` do CRM e no `qualifier-form.js` (ambos em produção).

Não feito, por decisão: pré-preencher visitante conhecido pelo cookie do
`Lead_Identity` (mexe no que preenche o lead; exige "não é você?" por causa
de computador compartilhado) e Ikea Effect no GrowthMap (Pedro despriorizou).

## Feito (2026-08-17, branch feat/connector-30-agora + CRM feat/connector-f0-nutricao)

- [x] Dispatcher canônico: todo POST pro CRM via `Lead_Store::dispatch_to_crm`
      + `mark_crm_attempt` (qualifier, form nativo, adapters, Woo)
- [x] Retry universal no cron (exceto `qualifier_partial`, por design)
- [x] F0: `_adspirit_form_id` + `_adspirit_form_kind` no payload; finalidade
      `comercial|nutricao` no builder; CRM bifurca (contato + label "Nutrição",
      sem lead; CAPI nunca dispara pra nutrição)
- [x] Bugs B1 (setup-wizard var), B2 (botão duplicado Turnstile), B3
      (class_exists CAPI/GA4 no caminho crítico)
- [x] TTL conservador (purga só `sent` > 90d, cap 200/run) + badge sem parciais
- [x] Rotas `/api/wp/qualifier-templates|roteiro` — já estavam na main e em
      prod (verificado); card "Modelos prontos" funcional
- [x] Widget de leads no dashboard do WP (padrão MonsterInsights)
- [x] Aba Submissões: reenvio em massa + filtro com todas as origens
- [x] Update dual-source (manifest do CRM + GitHub, vence a maior versão) —
      caminho pro repo privado após 2-3 versões estáveis
- [x] Auto-update nativo do WP auto-optado (aprovado Pedro 08-18) — frota
      se mantém sozinha a partir da 2.28; escape: filtro
      `adspirit_connector_auto_update`
- [x] Versão 2.28.0 preparada (header + constante)

## Próximo — não-destrutivos aprovados (Pedro, 2026-08-17)

UI/admin (prioridade declarada do Pedro: "nossa interface está muito ruim"):
- [x] Nav do painel reorganizada por TAREFA (regra 08-18): 5 grupos com
      ponto de saúde, rótulos leigos centralizados (tab_meta), legenda da
      aba ativa; builder/cf7-scope saíram do "Mais"; Leads enviados abre
      o grupo de leads
- [x] Aba Submissões completa (bulk, filtros, histórico, paginação,
      integrações secundárias na linha)
- [x] Aba de logs com request+response por tentativa: histórico por POST
      (data + HTTP + corpo da resposta resumido) + expander "dados enviados"
      com o payload pretty-printed na própria linha (2.30)
- [x] Quarentena de spam revisável (status `spam` + motivo na aba
      Submissões; resgate pelo Reenviar; TTL 30d; anti-flood 10/min)

Tracking/analytics:
- [x] Eventos automáticos nomeados (tel_click, email_click, whatsapp_click
      +generate_lead, file_download → dataLayer, arquivo versionado)
- [x] `form_step_view {step_number}` no qualifier (drop-off por etapa) +
      `generate_lead` com perfil no sucesso do submit
- [x] Pixel servido de path first-party (proxy 2.29) + JS inline migrado
      (telemetria/LGPD/cross-domain → arquivos versionados, observers 2→1,
      CSS do admin → assets/admin.css) (2.30)
- [x] Dedup×cache do event_id CAPI verificado (2.30): Lead seguro por
      construção (event_id = submission_id). Thank-you cacheado colapsava o
      event_id no HTML (lição PYS) → DONOTCACHEPAGE no render (WP Rocket/
      W3TC/Super Cache/LiteSpeed); cache de borda ainda exige excluir
      /obrigado. build_event_id agora usa _dosvi antes do hash IP+UA

Captação:
- [x] WhatsApp: mensagem com `{TITLE}`/`{URL}` (contexto da página) +
      `generate_lead` no clique em qualquer wa.me (via auto-events)
- [ ] WhatsApp: multi-agente com horário (roteamento pelo dono automático
      do CRM) — fase 2 do widget
- [ ] Quiz framing do qualifier: título de diagnóstico + resultado por perfil
      (mecânica Thrive/Interact — gate de contato no pico de investimento)
- [x] A/B maduro: variant="auto" (divisão pelo plugin com pesos+trava),
      vencedora manual e automática (critério simples declarado),
      arquivamento preservando números; card minimalista (form único,
      ações nos tiles, ajuda recolhida)

## Estratégico — produto (junto com o CRM)

- [~] Webhook CRM→WP (padrão WP Fusion) — FATIA 1 FEITA (2.30, modelo
      PULL): GET /api/wp/lead-status no CRM (perfil, is_customer, estágio
      aberto; sem PII de volta) + plugin lembra o visitante pós-submit
      (cookie HttpOnly com e-mail CIFRADO), classes no <html>
      (.adspirit-lead-customer → supressão via CSS), shortcode
      [adspirit_if_lead], window.__adspiritLead. Zero bloqueio de render
      (cache-first + AJAX pós-load). Falta: push de eventos nomeados
      (estágio mudou → notifica sites) e tags
- [ ] Rule-engine de exibição compartilhado (padrão OptinMonster): URL, UTM,
      referrer, scroll, tempo, device, novo/recorrente, exit-intent —
      servindo form, popup e WhatsApp; A/B julgado por RECEITA no CRM.
      Nota 2.30: motor de regras {field, op, value} já existe em 3 lugares
      (showIf, roteamento de finalidade, lead-status) — extrair núcleo comum
      quando este item entrar
- [x] Roteamento condicional no envio (2.30, lição Bit Integrations):
      regras {field, op, value, then} no builder mudam a finalidade
      (comercial|nutricao) POR SUBMISSÃO — contexto = respostas + UTM da
      atribuição (last-touch vence) + device/landing. Primeiro match vence;
      form sem regras = byte-idêntico
- [ ] Form como entidade multi-tenant com fonte no CRM
      `{id, nome, finalidade, estilo multistep|chat, destino}` + renderer
      chat (validado pelo Fluent Conversational); FAB WhatsApp com 2 perfis
      (com SDR IA → wa.me; sem → chat web roteirizado)
- [x] Condicional no roteiro do qualifier (2.30): showIf {match all|any,
      rules [{field, op is|not|contains, value}]} por etapa — navegação por
      índice visível, resposta de etapa pulada não viaja, retomada segura,
      fail-open. Roteiro sem showIf = comportamento idêntico
- [ ] Conversion recovery server-side (padrão Pixel Manager ACR): CRM reemite
      CAPI/GA4 de leads/vendas sem evento de browser
- [ ] Backfill retroativo da jornada anônima na identificação (padrão HubSpot)
- [x] Coletor DOM genérico (2.30): beta opt-in (default OFF) na aba Conexão;
      exige e-mail/telefone, ignora forms com integração dedicada e
      busca/login/checkout; anti-spam + quarentena; entrega pelo dispatcher
      canônico (source 'generic' → retry de graça)
- [~] Handshake de capacidades plugin↔CRM — FATIA 1 FEITA (2.30): GET
      /api/wp/central-status (CRM, na main) + checklist reconcilia ("Coberto
      pelo AdSpirit" pra CAPI Meta/Google central, pixel manual detectado por
      prova de vida, linked_domains na aba cross-domain). PRINCÍPIO (Pedro
      08-18): conexão mágica — o plugin detecta o que foi feito à mão e
      complementa, nunca pede config duplicada. Faltam: anúncio de
      endpoints/features suportados + rollback de versão pela UI
- [ ] Roteiro default da Digitals fora do bundle JS (migração faseada — é
      fallback de produção, nunca delete)

## Futuro — despriorizado por decisão (2026-08-17)

- [ ] Eixo LGPD completo (consent real com recusa, gates em Pixel/CAPI/GA4/
      telemetria, retenção configurável, export/erase do WP, README honesto)

## Higiene contínua

- [x] Derivações de IP unificadas (2.30): helper canônico
      AdSpirit_Telemetry::client_ip() (CF > XFF > REMOTE_ADDR); rate limits
      do form nativo e lead score ganharam XFF (bucket não colapsa no proxy)
- [ ] Engines anti-spam: mantidas separadas DE PROPÓSITO por ora — fontes
      diferentes ($_POST+cookie vs payload+_adspirit_ts) e buckets dedicados.
      Unificação real = extrair checks puros compartilhados; fazer numa
      janela com QA de captura, nunca junto de release em revisão
- [x] CSS do admin → assets/admin.css (2.30, byte-equivalente)
- [x] README reescrito honesto (2.30)
- [x] Checksum sha256 no manifest + verificação no upgrader_pre_download
      (mismatch aborta, fail-open sem o campo); build.sh imprime o hash e o
      ritual de cópia versionada pra rollback (2.30). Botão de rollback pela
      UI segue no estratégico (handshake)
- [x] Bugs B4-B6, B8, B9, B11 corrigidos (2.30). B7 (código morto
      site/instagram no default) mantido — serve a roteiro custom; B10
      (slug '&tab=' nos submenus) muda URLs de admin → decisão de revisão
- [x] Segredos via constantes no wp-config.php (2.30): ADSPIRIT_CRM_SECRET,
      _PIXEL_TOKEN, _CAPI_ACCESS_TOKEN, _GA4_API_SECRET,
      _TURNSTILE_SECRET_KEY — constante vence, UI trava o campo, save
      preserva, nunca persiste no banco
- [x] Checklist do setup: opcional não configurado é 'off' silencioso, não
      warn âmbar (2.30) — âmbar reservado pra ação real

## Reestruturação de TODAS as telas (doutrina de 12 princípios, Pedro 08-18)

Padrões compartilhados prontos no DS: `.as-help` (ajuda recolhida), `.as-field`,
`.as-toggle`/`.as-sub`, `.as-actions` (uma primária), focus-visible global,
tokens de baixo contraste com piso. Toda passada usa SÓ esses padrões.

- [x] DS compartilhado ajustado (contraste, margens, primitivas, foco)
- [x] Navegação (grupos por tarefa + saúde + rótulos leigos)
- [x] Conexão (referência da doutrina: toggles com legenda, sub-opção aninhada)
- [x] Testes A/B (estrutura ok; conformidade fina no QA visual)
- [x] Leads enviados (bulk+filtros+histórico; conformidade fina no QA)
- [x] Visão geral (checklist recolhe quando pronto; erro só quando há;
      ambiente/proteções em details; teste vira rodapé de ações)
- [x] Primeiros passos (DS-only, OK silencioso, seção pronta recolhe)
- [x] Form de avaliação (toggle no padrão, JSON avançado recolhido — galeria
      de modelos vira o caminho feliz)
- [x] Criar formulário (builder) — herda DS/primitivas; passada fina no QA
- [x] Mapear campos · Contact Form 7
- [x] Anti-spam · Verificação Cloudflare
- [x] Conversões Meta · Google · Comportamento · Clarity · Rastreio entre sites
- [x] Aviso de cookies (LGPD)
- [x] Webhooks de saída · Customer.io · Mailchimp
- [x] Diagnóstico (logs)
