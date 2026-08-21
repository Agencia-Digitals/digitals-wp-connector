#!/usr/bin/env bash
# Empacota o connector por PERFIL, segundo perfis.json.
#
#   bash build-perfil.sh cliente   -> pacote enxuto para o domínio do cliente
#   bash build-perfil.sh estudio   -> pacote completo para os nossos subdomínios
#
# Diferença que importa: no pacote "cliente" os módulos de fora do perfil NÃO
# são incluídos — não ficam desligados por configuração, simplesmente não
# existem no site. Menos superfície, menos a explicar numa auditoria.
set -euo pipefail
cd "$(dirname "$0")"

PERFIL="${1:-cliente}"
[ -f perfis.json ] || { echo "ERRO: perfis.json não encontrado"; exit 1; }

VERSION=$(grep -E "^\s*\*\s*Version:" digitals-connector.php | sed -E 's/.*Version:[[:space:]]*//' | tr -d '[:space:]')
SLUG="adspirit-connector"
DIST="dist/perfil-$PERFIL"
ALVO="$DIST/$SLUG"

MODULOS=$(python3 - "$PERFIL" <<'PY'
import json, sys
perfil = sys.argv[1]
cfg = json.load(open('perfis.json'))
if perfil not in ('cliente', 'estudio'):
    sys.exit('perfil inválido: use cliente ou estudio')
mods = list(cfg['sempre']) + list(cfg['cliente']['modulos'])
if perfil == 'estudio':
    mods += cfg['estudio']['modulos_extras']
print(' '.join(sorted(set(mods))))
PY
)

rm -rf "$DIST"
mkdir -p "$ALVO/includes" "$ALVO/assets"
cp digitals-connector.php README.md "$ALVO/" 2>/dev/null || cp digitals-connector.php "$ALVO/"
[ -d assets ] && cp -R assets/. "$ALVO/assets/" 2>/dev/null || true

FALTANDO=""
for m in $MODULOS; do
  origem="includes/class-adspirit-$m.php"
  if [ -f "$origem" ]; then cp "$origem" "$ALVO/includes/"; else FALTANDO="$FALTANDO $m"; fi
done

# marca o perfil dentro do pacote, pro plugin saber onde está rodando
printf "<?php\n// gerado por build-perfil.sh — não editar\nif (!defined('ADSPIRIT_PERFIL')) define('ADSPIRIT_PERFIL', '%s');\n" "$PERFIL" > "$ALVO/includes/perfil.php"

( cd "$DIST" && zip -qr "$SLUG-$PERFIL-v$VERSION.zip" "$SLUG" )
# perfil.php é gerado pelo build — não conta como módulo.
INCLUIDOS=$(ls "$ALVO/includes"/*.php | grep -v '/perfil\.php$' | wc -l | tr -d ' ')
TOTAL=$(ls includes/*.php | wc -l | tr -d ' ')
rm -rf "$ALVO"

echo "perfil:    $PERFIL"
echo "módulos:   $INCLUIDOS de $TOTAL"
[ -n "$FALTANDO" ] && echo "AVISO: não encontrados:$FALTANDO"
echo "pacote:    $DIST/$SLUG-$PERFIL-v$VERSION.zip"
