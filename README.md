# AdSpirit Connector — v2.0.0

Plugin WordPress oficial da [Agência Digitals](https://agenciadigitals.com.br) que conecta o site ao CRM **AdSpirit**. Substitui ~10 plugins (CF7 webhook, WP Armour, Insert Headers and Footers, pixel injection, Schema.org, dedup, CSV export, etc) em um único pacote configurado pelo painel.

## Click-to-Connect — setup em 30 segundos

```
1. Instala o ZIP no WP (Plugins → Adicionar novo → Enviar)
2. WP → AdSpirit → Conexão CRM → "Conectar ao AdSpirit"
3. Loga no CRM, autoriza
4. Volta conectado — brand_slug, secret, pixel_token, endpoint gravados auto
```

Zero copy/paste de tokens. Mesma simplicidade de "conectar GitHub" no Vercel.

## 30+ features incluídas

```
┌─────────────────────────────────────────────────────────────────┐
│ AdSpirit Connector v2.0.0                                       │
│ ├── Visão geral         ← checklist + métricas + test event     │
│ ├── Conexão CRM         ← 1 botão "Conectar" + status conectado │
│ ├── Forms / Mapping     ← [adspirit_form] + 5 plugins suportados│
│ ├── Anti-spam           ← 6 camadas embutidas (substitui Armour)│
│ ├── Meta CAPI           ← Lead + PageView server-side           │
│ ├── Google Analytics 4  ← MP v2 server-side                     │
│ ├── Cross-domain        ← link decoration cross-TLD             │
│ ├── Webhook out         ← fanout Zapier/Make/n8n                │
│ ├── Consentimento       ← LGPD popup editável + granular        │
│ └── Logs                ← CF7 + anti-spam + crashes + CSV export│
└─────────────────────────────────────────────────────────────────┘
```

### Detalhe por categoria

**Conexão (Click-to-Connect)** — OAuth-like flow. Cliente loga no CRM (SSO Digitals), autoriza, plugin recebe credenciais e grava nas options. CSRF state + nonce single-use TTL 5min.

**Captura de leads (5 fontes)**
- Contact Form 7 (hook `wpcf7_mail_sent`)
- Gravity Forms (`gform_after_submission`)
- WPForms (`wpforms_process_complete`)
- Elementor Forms (`elementor_pro/forms/new_record`)
- Fluent Forms (`fluentform_submission_inserted`)
- + Shortcode `[adspirit_form]` — form multi-step **genérico** (card branco/teal, progress bar). Legado/drop-in simples.

**Form qualifier BANT (design AdSpirit dark glass)** — use **`[adspirit_form_qualifier]`** pro form de avaliação com o visual da agência (preto + glassmorphism, transição horizontal, 11 etapas BANT, telemetria). NÃO confunda com `[adspirit_form]` (branco). Três modos:
- `[adspirit_form_qualifier]` — botão CTA → form em **tela cheia** (overlay). Padrão.
- `[adspirit_form_qualifier mode="inline"]` — tela cheia, abre direto no load (sem botão).
- `[adspirit_form_qualifier mode="embed"]` — **contido na seção** (card dark, sem overlay). Pra encaixar numa landing com hero + seção.
- `[adspirit_form_qualifier mode="trigger"]` — só o form (sem botão). Use **seu próprio botão** (estilizado no builder) pra abrir, marcando-o com qualquer um: classe `adspirit-qualifier-trigger`, atributo `data-adspirit-qualifier`, ou link `href="#adspirit-avaliacao"`. Coloque o `mode="trigger"` uma vez na página.

**Telemetria (30+ campos)** — server + client side. Browser parse (Chrome/Safari/Firefox + Windows/macOS/iOS/Android), IP, locale, timezone, behavior (tempo na página + no form + fields visitados), WP context (post_id, post_type), cookies de atribuição (_fbp, _fbc, _ga, _gid). Linka com pixel via cookie `adspirit_vid` — CRM herda toda a jornada multi-touch.

**Anti-spam (6 camadas — substitui WP Armour)**
1. Honeypot injetado em todos os forms
2. Time-trap (rejeita submits < N segundos)
3. Rate-limit por IP
4. User-Agent check (bloqueia curl/python-requests/wget)
5. Reverse text trap (entropia alta + sem stopwords PT-BR)
6. Blocklist regex emails + palavras

**Meta CAPI server-side** — Pixel ID + Access Token + test event code. Eventos Lead (CF7 submit) + PageView (page load throttled). Hash SHA-256, cookies _fbc/_fbp, event_id idempotente pra dedupe com pixel client-side.

**GA4 server-side** — Measurement Protocol v2. Eventos generate_lead + page_view. Client ID parseado do cookie _ga.

**Cross-domain decoration** — JS injetado no wp_footer decora `<a>` cross-TLD com `?dos_vid=<id>`. MutationObserver pega links dinâmicos.

**LGPD popup** — banner minimalista design AdSpirit. 3 opções (aceitar tudo / essenciais / personalizar com 3 categorias granulares: tracking, analytics, marketing). Cookie `adspirit_consent` 365d. Editor com preview ao vivo. Pixel/CAPI/GA4 respeitam o consent.

**Quick wins**
- Auto-update via GitHub Releases (sem token, repo público)
- Test event button → POSTa lead mock + arquiva automático
- Email de saúde diário (alerta se 0 leads em 24h em dia útil)

**Integrações extras**
- WooCommerce: order completed → deal won, processing → lead, refunded → deal lost
- Webhook out genérico (Zapier/Make/n8n)
- CSV export de logs
- Schema.org LD+JSON automático (@Organization + ContactPoint)
- Local dedup 60s por email (anti double-submit)
- Site Health: contribui check no painel WP
- WP-CLI: `wp adspirit test|safe-mode|logs`

## Defense-in-depth — nunca derruba o site

10 camadas de proteção:

1. Plugin header declara `Requires PHP 7.4` + `Requires WP 6.0` → WP bloqueia ativação se incompatível
2. Activation hook valida runtime + auto-desativa se falhar
3. Safe Mode global (manual ou automático após 3 crashes/5min)
4. `register_shutdown_function` captura fatal errors do plugin
5. Todo `add_action`/`add_filter` wrapped em try/catch (Safe_Hook)
6. Defensive `class_exists` antes de dependências externas
7. Admin pages com `ob_start` + try/catch
8. HTTP fire-and-forget (`blocking=false` + `timeout=8`)
9. Hooks frontend só renderizam se config válida
10. Visitor do site nunca vê erro do plugin

## Instalação

1. Baixe `adspirit-connector-v2.0.0.zip` em [releases](https://github.com/agenciadigitals/digitals-wp-connector/releases) ou direto no painel do CRM
2. wp-admin → **Plugins → Adicionar novo → Enviar plugin** → seleciona ZIP → **Instalar** → **Ativar**
3. Menu lateral esquerdo: **AdSpirit**
4. Aba "Conexão CRM" → clica **"Conectar ao AdSpirit"** → autoriza → pronto

## Updates

GitHub Releases via plugin-update-checker built-in. WP avisa "Update available" como qualquer plugin oficial — 1 clique pra atualizar. Settings preservadas.

## Arquitetura interna

```
digitals-wp-connector/
├── digitals-connector.php             ← bootstrap (safe loading + DI)
├── includes/
│   ├── class-adspirit-safe-bootstrap.php  ← version check + Safe Mode
│   ├── class-adspirit-crash-tracker.php   ← shutdown handler + auto safe mode
│   ├── class-adspirit-safe-hook.php       ← try/catch wrappers
│   ├── class-adspirit-settings.php        ← data layer
│   ├── class-adspirit-menu.php            ← top-level menu + tabs router
│   ├── class-adspirit-connect.php         ← Click-to-Connect (OAuth-like)
│   ├── class-adspirit-status.php          ← Visão geral + Conexão CRM tabs
│   ├── class-adspirit-health-checker.php  ← agrega métricas
│   ├── class-adspirit-logs.php            ← logs UI + crashes
│   ├── class-adspirit-telemetry.php       ← coletor server + client
│   ├── class-adspirit-cf7-handler.php     ← wpcf7_mail_sent hook
│   ├── class-adspirit-anti-spam.php       ← 6 camadas + UI
│   ├── class-adspirit-field-mapping.php   ← discover + mapping per-form
│   ├── class-adspirit-pixel-injector.php  ← <script> no <head>
│   ├── class-adspirit-capi-meta.php       ← Graph API events
│   ├── class-adspirit-ga4.php             ← Measurement Protocol v2
│   ├── class-adspirit-cross-domain.php    ← link decoration JS
│   ├── class-adspirit-lgpd-popup.php      ← consent banner + editor
│   ├── class-adspirit-quickwins.php       ← auto-update + test event + saúde
│   ├── class-adspirit-form.php            ← [adspirit_form] shortcode
│   ├── class-adspirit-form-adapters.php   ← Gravity/WPForms/Elementor/Fluent
│   └── class-adspirit-integrations.php    ← Woo + webhook out + Schema + Site Health + WP-CLI
├── README.md
└── build.sh
```

## Licença

Proprietário — Agência Digitals.
