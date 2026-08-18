# AdSpirit Connector — v2.29.0

Plugin WordPress que conecta o site ao CRM **AdSpirit** da [Agência Digitals](https://agenciadigitals.com.br).
Um único pacote cobre captura de leads, anti-spam, tracking/atribuição e conversões server-side, tudo configurado pelo wp-admin (menu **AdSpirit**).

## O que faz

- **Captura de leads multi-fonte** — Contact Form 7 (hook `wpcf7_before_send_mail`, priority 99: captura ANTES do envio do e-mail, então o lead não se perde se o SMTP falhar), Gravity Forms, WPForms, Elementor Forms, Fluent Forms, WooCommerce (order → deal), shortcode `[adspirit_form]` com builder visual no admin, e o qualifier.
- **Rede de segurança (Lead Store)** — todo lead é gravado na tabela local `{prefix}adspirit_submissions` ANTES de qualquer chamada externa. Dispatch pro CRM é blocking e o status reflete a resposta real (2xx=sent · 4xx/5xx=failed · timeout=pending). Cron re-POSTa pending/failed com backoff (15min/1h/6h/24h, máx 5 tentativas). Reenvio manual (unitário e em massa) na aba Submissões.
- **Qualificação multi-step** — `[adspirit_form_qualifier]`: form BANT em etapas (roteiro por marca vindo do CRM), modos popup/inline/embed/trigger, `form_step_view` por etapa pro dataLayer, redirect por perfil retornado pelo CRM.
- **Anti-spam em camadas com quarentena** — honeypot, time-trap, rate-limit por IP, User-Agent check, reverse text trap, blocklist regex; opcionalmente Cloudflare Turnstile invisível (fail-open em erro de rede). Bloqueio vira status `spam` com motivo na aba Submissões — revisável e resgatável pelo Reenviar (TTL 30d).
- **Tracking e atribuição** — pixel do CRM injetado no `<head>` (opt-in; opcionalmente servido de path first-party via proxy com cache, anti ad-blocker), telemetria server+client no payload, e atribuição **first + last touch** em cookies first-party (`adspirit_ft` nunca sobrescrito, `adspirit_lt` atualizado a cada visita com UTM; 90d), injetada como hidden fields no submit.
- **Conversões server-side** — Meta CAPI (Graph API; `event_id` = submission_id pra dedupe com o pixel browser) e GA4 Measurement Protocol v2 (`generate_lead`, `page_view`). Shortcode `[adspirit_thank_you]` dispara ambos na página de obrigado com redirect.
- **Eventos automáticos** — `tel_click`, `email_click`, `whatsapp_click` (+`generate_lead`) e `file_download` pro dataLayer, via arquivo JS versionado.
- **Testes A/B** — variantes de `[adspirit_form]` com cookie sticky 90d, divisão automática com pesos, vencedora manual ou automática, arquivamento preservando números.
- **Widget de leads no painel WP** — leads do mês, por origem, pendentes, com deep-link pro CRM (read-only, fail-soft, só admin conectado).
- **Extras** — cross-domain link decoration (`?dos_vid=`), webhook out genérico, Customer.io e Mailchimp passthrough, CSV export, Schema.org LD+JSON, dedup local 60s, Site Health, WP-CLI (`wp adspirit test|safe-mode|logs`), widget/popup de WhatsApp, log de submissões local.

## Instalação

1. Baixe o ZIP em [GitHub Releases](https://github.com/agenciadigitals/digitals-wp-connector/releases) ou em `public/downloads` do CRM (mesmo zip servido pelo manifest).
2. wp-admin → **Plugins → Adicionar novo → Enviar plugin** → ZIP → Instalar → Ativar.
3. Conecte por um dos dois caminhos:
   - **Click-to-Connect**: AdSpirit → Conexão → "Conectar ao AdSpirit" → loga no CRM, autoriza, credenciais (brand_slug, secret, pixel_token, endpoint) gravadas automaticamente.
   - **Magic Install**: operadora gera token no CRM (`/install-wp`) e entrega o link `wp-admin/?adspirit_token=XYZ`; o plugin redime o token (single-use) e já conecta com CF7 + pixel ativos.

**Updates**: updater próprio, dual-source — consulta o manifest hospedado no CRM (`{endpoint}/downloads/manifest.json`) **e** o GitHub Releases, e oferece a maior versão das duas. A partir da **2.28** o plugin se auto-opta no auto-update nativo do WP (a frota se mantém sozinha; escape pelo filtro `adspirit_connector_auto_update`). Não usa plugin-update-checker externo.

## Arquitetura em um parágrafo

O boot carrega primeiro a camada de segurança (Safe Bootstrap + Crash Tracker + Safe Hook): todo callback de hook roda em try/catch, fatais são capturados em `register_shutdown_function`, e ≥3 crashes do plugin em 5min ativam **Safe Mode** automático — features desligam, a UI de admin continua acessível, o site fica intocado. A captura é **entry-first**: validar → `record_pending()` no Lead Store → dispatch externo → `mark()` por integração; se o insert local falhar, o envio segue mesmo assim (integridade nunca bloqueia lead). Todo POST pro CRM — qualifier, form nativo, adapters, Woo — passa pelo **dispatcher canônico** (`Lead_Store::dispatch_to_crm` + `mark_crm_attempt`); o retry do cron re-POSTa só pro CRM, reusando o `submission_id` original — fanout/CAPI/GA4 rodam UMA vez, no submit original, nunca no retry.

## Avisos de operação (leia antes de prometer qualquer coisa a cliente)

**LGPD — consentimento IMPLÍCITO.** O banner é informativo, com um único botão ("Entendi"); scroll/clique/navegação registram `adspirit_consent=accept_all`. **Não há opção de recusa nem categorias granulares**, então na prática Pixel, CAPI, GA4 e telemetria sempre rodam — os únicos módulos cujo gate de consent tem efeito real são Behavioral e Clarity. O eixo LGPD completo (recusa real, gates efetivos, retenção configurável, export/erase) foi **despriorizado por decisão** (2026-08-17) e está no ROADMAP como futuro.

**Ritual de release** (deploy do CRM antes do release do plugin):

1. Bumpar a versão em **2 lugares**: header `Version:` e constante `ADSPIRIT_CONNECTOR_VERSION` em `digitals-connector.php`.
2. `bash build.sh` — gera `dist/adspirit-connector-v{X}.zip` (zip anterior preservado em `dist/old` pra rollback).
3. Criar o GitHub Release com o zip **e** sincronizar zip + `manifest.json` em `public/downloads` do repo do CRM. Os dois canais precisam andar juntos: o updater pega a maior versão dos dois, e quando o repo virar privado o manifest vira a única fonte viva.

## Paredes-mestras (contratos que NUNCA mudam)

Quebrar qualquer um destes quebra sites em produção ou o dedup do CRM:

- **Chaves canônicas do payload** (`your-name`, `your-email`, `Telefone`, `empresa`, …) — contrato com o webhook do CRM; defaults sincronizáveis via `/api/wp/field-mapping`.
- **Headers `x-cf7-*`** (`x-cf7-secret`, `x-cf7-submission-id`) — autenticação e identidade da submissão.
- **`submission_id` reusado no retry/reenvio** — é o que faz o CRM promover/atualizar o lead em vez de duplicar.
- **Slug da pasta no ZIP: `adspirit-connector`** — o WP deriva a identidade do plugin (e o caminho de update) do nome da pasta. Não renomear.

Contexto completo e cicatrizes de incidente: doc "Paredes-Mestras" (galeria de artifacts). Backlog: [`ROADMAP.md`](./ROADMAP.md).

## Licença

Proprietário — Agência Digitals.
