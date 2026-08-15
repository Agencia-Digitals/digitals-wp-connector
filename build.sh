#!/usr/bin/env bash
# Empacota o plugin em ZIP no formato que o WordPress espera:
#   adspirit-connector/
#     digitals-connector.php
#     includes/*.php
#     README.md
#
# O slug do plugin é "adspirit-connector" — derivado do nome da pasta dentro
# do ZIP. WP usa isso pra updates futuros via plugin-update-checker, então
# NÃO trocar.
#
# Uso: bash build.sh

set -euo pipefail

cd "$(dirname "$0")"

VERSION=$(grep -E "^\s*\*\s*Version:" digitals-connector.php | sed -E 's/.*Version:\s*//' | tr -d '[:space:]')
if [ -z "$VERSION" ]; then
  echo "ERROR: Version não encontrada no header de digitals-connector.php"
  exit 1
fi

SLUG="adspirit-connector"
DIST="dist"
OLD="${DIST}/old"
TARGET="${DIST}/${SLUG}"
ZIP_NAME="${SLUG}-v${VERSION}.zip"

echo "Building ${SLUG} v${VERSION}…"

# Zips já buildados vão pra dist/old antes da limpeza. Até 2026-08-13 o
# build fazia `rm -rf dist` e apagava a versão anterior — na hora de um
# rollback só sobrava reconstruir do git. Rollback é sempre no pior
# momento possível, então guardar o zip anterior é barato demais pra não
# fazer.
mkdir -p "${OLD}"
for z in "${DIST}"/*.zip; do
  [ -e "$z" ] || continue   # glob sem match vira literal; ignora
  mv -f "$z" "${OLD}/"
done

# Limpa só a área de montagem, nunca o dist inteiro (levaria o old junto).
rm -rf "${TARGET}"
mkdir -p "${TARGET}"

# Copia files essenciais
cp digitals-connector.php "${TARGET}/"
cp README.md "${TARGET}/"
cp -r includes "${TARGET}/"
# Assets (favicon AdSpirit + futuros recursos)
if [ -d assets ]; then
  cp -r assets "${TARGET}/"
fi

# Cria ZIP
cd "${DIST}"
zip -rq "${ZIP_NAME}" "${SLUG}"
cd ..

echo ""
echo "✓ Built: ${DIST}/${ZIP_NAME}"
echo "  Size:  $(du -h "${DIST}/${ZIP_NAME}" | cut -f1)"
# Publica no CRM local pro auto-update dos sites (servido como estático do
# Next em /plugin/*). Fluxo de release: build → commit no AGD-CRM → deploy.
CRM_PLUGIN_DIR="$HOME/Documents/GitHub/AGD-CRM/public/plugin"
if [ -d "$HOME/Documents/GitHub/AGD-CRM/public" ]; then
  mkdir -p "$CRM_PLUGIN_DIR"
  cp "${DIST}/${ZIP_NAME}" "$CRM_PLUGIN_DIR/adspirit-connector-latest.zip"
  printf '{"version":"%s","zip":"adspirit-connector-latest.zip","updated_at":"%s"}\n' \
    "$VERSION" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$CRM_PLUGIN_DIR/manifest.json"
  echo "✓ Publicado em AGD-CRM/public/plugin/ — commit + deploy do CRM pra liberar o update"
fi

echo ""
echo "Pra instalar:"
echo "  wp-admin → Plugins → Adicionar novo → Enviar plugin → ${DIST}/${ZIP_NAME}"
