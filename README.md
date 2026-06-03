# AdSpirit Connector

Plugin WordPress oficial da [Agência Digitals](https://agenciadigitals.com.br) que conecta o site ao CRM **AdSpirit**. Tudo num lugar: envio de leads em real-time, anti-spam embutido, field mapping per-form, Meta Conversion API server-side, GA4 server-side e cross-domain decoration. Configurado via wp-admin, sem editar código.

## O que o plugin faz

```
┌─────────────────────────────────────────────────────────────────┐
│ AdSpirit Connector                                              │
│ ├── Visão geral         ← checklist de onboarding + métricas    │
│ ├── Conexão CRM         ← endpoint, brand slug, secret, pixel   │
│ ├── Forms / Field map   ← mapeia campos CF7 → canonical CRM     │
│ ├── Anti-spam           ← honeypot + time-trap + RL + blocklist │
│ ├── Meta CAPI           ← Lead + PageView server-side           │
│ ├── Google Analytics 4  ← generate_lead + page_view (MP v2)     │
│ ├── Cross-domain        ← decorar links com ?dos_vid pra n TLDs │
│ └── Logs                ← 100 últimas entradas (CF7 + anti-spam)│
└─────────────────────────────────────────────────────────────────┘
```

### Features

| Tab | O que faz |
|---|---|
| **Visão geral** | Checklist visual do que falta (CF7 instalado? Slug configurado? Secret? Mapping? Primeira submissão?). Próxima ação destacada. Métricas 24h/7d/30d: enviados, falhas, bloqueios, taxa de sucesso. Forms CF7 detectados com link rápido pra mapping. Botão "Testar conexão" no topo. |
| **Conexão CRM** | Endpoint URL, brand slug, secret CF7 (mascarado, gerado uma vez no painel do CRM), pixel token opcional. Toggles pra ativar CF7 webhook e pixel injection. |
| **Forms / Field map** | Auto-discover de todos os forms CF7. Pra cada form, mapeamento dropdown de cada campo canonical (Nome, Email, Telefone, Empresa, Cargo, Nº funcionários, Nicho, Site, Investimento, Urgência) → campo do form. Botão "Aplicar sugestões" usa heurística de nome (your-name, name, nome → tudo vira Nome). |
| **Anti-spam** | 4 camadas, cada uma com toggle:<br>• **Honeypot** — campo invisível injetado em todo form<br>• **Time-trap** — rejeita submits em menos de N segundos<br>• **Rate-limit por IP** — máximo X submits/min<br>• **Blocklist** — regex de email + palavras-chave em qualquer campo<br>Stats e log de bloqueios visível. Compatível com WP Armour e outros. |
| **Meta CAPI** | Pixel ID + Access Token + test_event_code. Dispara <code>Lead</code> em cada CF7 submit e <code>PageView</code> a cada page load (throttle 60s). Event ID compartilhado com pixel client-side = dedupe automático. Hash SHA-256 de email/phone, captura _fbc/_fbp cookies, client_ip_address, user_agent. |
| **GA4** | Measurement ID + API Secret. Dispara <code>generate_lead</code> e <code>page_view</code> (throttle 30s/URL). Client ID lido do cookie _ga (parse) ou fallback IP+UA. Measurement Protocol v2. |
| **Cross-domain** | Lista de hostnames "afiliados". JS injetado decora `<a>` que apontam pros domínios com `?dos_vid=<id>` (cookie `adspirit_vid`). MutationObserver pega links injetados dinamicamente (popups, AJAX). |
| **Logs** | 100 últimas entradas: CF7 dispatches (sent/error/skipped) e bloqueios anti-spam. Botão limpar log. |

## Instalação

1. Baixe `adspirit-connector-v1.1.0.zip` (gerado via `bash build.sh`).
2. wp-admin → **Plugins → Adicionar novo → Enviar plugin** → seleciona ZIP → **Instalar agora** → **Ativar**.
3. Menu lateral esquerdo: **AdSpirit** (ícone de antena).
4. Comece pela aba **Visão geral** — o checklist vai te guiar.

## Setup mínimo (3 minutos)

1. **Aba "Conexão CRM"**: cole endpoint, brand slug, secret. (Esses vêm de `/settings/integrations/tracking → Plugin WordPress` no CRM, todos copiáveis com 1 clique.)
2. **Aba "Visão geral"**: clica em **Testar conexão**. Deve retornar `{"ok": true}`.
3. **Aba "Forms / Field mapping"**: seleciona o form principal, clica **Aplicar sugestões**, salva.
4. Submete um form CF7 de teste no site. Em até 5s aparece em `/leads` no CRM.

Opcionais (em qualquer ordem):
- Aba **Anti-spam**: deixa todas as camadas ativas (defaults seguros).
- Aba **Meta CAPI**: cola Pixel ID + Access Token.
- Aba **GA4**: cola Measurement ID + API Secret.
- Aba **Cross-domain**: lista os domínios afiliados.

## Arquitetura interna

```
digitals-wp-connector/
├── digitals-connector.php                    ← main + bootstrap
├── includes/
│   ├── class-adspirit-settings.php           ← data layer (options I/O)
│   ├── class-adspirit-menu.php               ← top-level menu + tabs router
│   ├── class-adspirit-status.php             ← Overview tab + ajax test
│   ├── class-adspirit-health-checker.php     ← agrega métricas
│   ├── class-adspirit-cf7-handler.php        ← wpcf7_mail_sent hook
│   ├── class-adspirit-anti-spam.php          ← 4 camadas + tab UI
│   ├── class-adspirit-field-mapping.php      ← discover + mapping per-form
│   ├── class-adspirit-pixel-injector.php     ← wp_head script
│   ├── class-adspirit-capi-meta.php          ← Graph API events
│   ├── class-adspirit-ga4.php                ← Measurement Protocol v2
│   ├── class-adspirit-cross-domain.php       ← link decoration JS
│   └── class-adspirit-logs.php               ← Logs tab
├── README.md
└── build.sh
```

### Tabs registradas via filtro

```php
apply_filters('adspirit_connector_tabs', $tabs);
```

Cada feature registra sua tab + render + save via:

```php
add_action('adspirit_connector_render_tab_<slug>', $callback);
add_action('adspirit_connector_save_<slug>', $callback);
```

Modular — adicionar feature nova = adicionar 1 classe, sem mexer no menu.

### CF7 pipeline

```
Visitante submete CF7
    ↓ wpcf7_validate (priority 5)
AdSpirit Anti-Spam: 4 camadas
    ✗ Bloqueia → log + CF7 mostra erro genérico
    ✓ Passa
    ↓ wpcf7_mail_sent (priority 99, depois de n8n@10)
AdSpirit Cf7 Handler:
  1. Aplica field mapping (form_id → canonical names)
  2. Augmenta com cf7_time + cf7_url
  3. Gera submission_id idempotente (form-time-hash)
  4. POST → CRM (fire-and-forget, blocking=false)
  5. Dispara Meta CAPI Lead (paralelo, fire-and-forget)
  6. Dispara GA4 generate_lead (paralelo, fire-and-forget)
  7. Log circular
```

Cada dispatch externo é independente — se Meta CAPI cair, CRM e GA4 ainda recebem.

## Updates

v1.1.0 distribui ZIP manual. v1.2 vai adicionar [plugin-update-checker](https://github.com/YahnisElsts/plugin-update-checker) apontando pras releases deste repo no GitHub. WP vai notificar "Update available" como qualquer plugin oficial.

Por enquanto, pra atualizar:
1. Baixa o ZIP novo.
2. wp-admin → Plugins → Desativa o atual.
3. Plugins → Adicionar novo → Enviar plugin → seleciona ZIP novo → confirma substituir → Ativa.
4. Settings preservadas (deactivate não toca em options).

## Desenvolvimento

Sem build step. Edita PHP direto, recarrega no WP.

### Build ZIP

```bash
bash build.sh
```

Gera `dist/adspirit-connector-v<version>.zip` com a estrutura correta (pasta raiz `adspirit-connector/`).

### Testar localmente

[Local](https://localwp.com/) ou Docker (`wordpress:latest` + `mariadb`). Subir um CRM dev em `localhost:3000` e apontar o plugin pra ele.

## Segurança

- Capability check em todos os entrypoints (`manage_options`).
- Nonces em forms + ajax.
- Sanitização de input + escape de output (esc_html, esc_attr, esc_url).
- HTTPS enforced no endpoint URL (`esc_url_raw` rejeita esquemas inválidos).
- Secret no DB sem encrypt — WordPress core não oferece encrypt nativo. Acesso restrito a admins. Pra segurança extra: use [Security Headers](https://wordpress.org/plugins/secupress/) e restrinja acesso ao wp-admin.

## Roadmap

- **v1.2**: plugin-update-checker, logs com paginação, export CSV.
- **v1.3**: Gravity Forms support.
- **v1.4**: WooCommerce orders → CRM deals.
- **v2.0**: self-onboarding via CRM wizard, OAuth-style install token, marketplace WP.

## Licença

Proprietário — propriedade da Agência Digitals. Distribuição interna ou licenciada via AdSpirit standalone.
