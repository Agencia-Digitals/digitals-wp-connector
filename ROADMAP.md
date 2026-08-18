# AdSpirit Connector — Roadmap 3.0

Backlog derivado do estudo completo de 2026-08-17 (inventário dos 43 arquivos +
benchmark de Gravity/WPForms/Fluent + análise de 16 pares de mercado). Fontes
completas nos artifacts "Connector 3.0", "Paredes-Mestras" e "Pares do
Connector" (galeria do Pedro). **Regra de ouro: nada destrutivo — mudança
aditiva atrás de condição; ler PAREDES-MESTRAS antes de mexer; deploy CRM
antes de release do plugin.**

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
- [ ] Aba Submissões: status por integração na linha + paginação (bulk e
      filtros já feitos)
- [ ] Aba de logs com request+response por tentativa (diagnóstico sem SSH)
- [ ] Quarentena de spam revisável (status `spam` no Lead Store em vez de
      descarte silencioso — padrão WPForms)

Tracking/analytics:
- [ ] Eventos automáticos nomeados (padrão PixelYourSite): `TelClick`,
      `EmailClick`, `Download`, scroll/tempo — com parâmetros ricos
- [ ] Eventos GA4 com nomes recomendados de lead gen (`generate_lead` já ok;
      adicionar `form_step_view {step_number}` no qualifier = drop-off por
      etapa, o analytics que falta no A/B)
- [ ] Pixel servido de path first-party (anti ad-blocker, padrão Stape) +
      migrar JS inline (telemetria/LGPD/cross-domain) pra arquivos versionados
- [ ] Verificar dedup×cache do event_id CAPI (lição PYS) — Lead ok
      (server-side por submissão); atenção: thank-you em page cache = evento
      não dispara (excluir /obrigado do cache dos sites)

Captação:
- [ ] WhatsApp com contexto dinâmico (padrão Joinchat): mensagem com
      `{TITLE}/{URL}` + UTM, `generate_lead` no clique, multi-agente/horário
- [ ] Quiz framing do qualifier: título de diagnóstico + resultado por perfil
      (mecânica Thrive/Interact — gate de contato no pico de investimento)
- [ ] A/B maduro (padrão WPFunnels): pesos com trava, vencedor automático,
      arquivar variantes (nunca apagar)

## Estratégico — produto (junto com o CRM)

- [ ] Webhook CRM→WP com ações nomeadas (padrão WP Fusion): estágio/score/tag
      do lead como dado do visitante → personalização/gating no site
- [ ] Rule-engine de exibição compartilhado (padrão OptinMonster): URL, UTM,
      referrer, scroll, tempo, device, novo/recorrente, exit-intent —
      servindo form, popup e WhatsApp; A/B julgado por RECEITA no CRM
- [ ] Form como entidade multi-tenant com fonte no CRM
      `{id, nome, finalidade, estilo multistep|chat, destino}` + renderer
      chat (validado pelo Fluent Conversational); FAB WhatsApp com 2 perfis
      (com SDR IA → wa.me; sem → chat web roteirizado)
- [ ] Condicional no roteiro do qualifier (modelo Gravity: rules + all/any,
      aplicável a step/botão) — pré-requisito dos templates por vertical
- [ ] Conversion recovery server-side (padrão Pixel Manager ACR): CRM reemite
      CAPI/GA4 de leads/vendas sem evento de browser
- [ ] Backfill retroativo da jornada anônima na identificação (padrão HubSpot)
- [ ] Coletor DOM genérico como rede de segurança pra form builder
      desconhecido (padrão HubSpot non-HubSpot forms; hooks continuam primários)
- [ ] Handshake de capacidades plugin↔CRM + rollback de versão pela UI
- [ ] Roteiro default da Digitals fora do bundle JS (migração faseada — é
      fallback de produção, nunca delete)

## Futuro — despriorizado por decisão (2026-08-17)

- [ ] Eixo LGPD completo (consent real com recusa, gates em Pixel/CAPI/GA4/
      telemetria, retenção configurável, export/erase do WP, README honesto)

## Higiene contínua

- [ ] Unificar as 2 engines anti-spam e as 5 derivações de IP
- [ ] CSS do admin (600 linhas em string PHP) → arquivo
- [ ] README reescrito (v2.0.0 + afirmações falsas sobre consent/hooks)
- [ ] Checksum do ZIP de release + manter releases antigas (rollback)
- [ ] Bugs B4–B11 do inventário (baixa severidade)
