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

SHA256=$(shasum -a 256 "${DIST}/${ZIP_NAME}" | cut -d' ' -f1)

echo ""
echo "✓ Built: ${DIST}/${ZIP_NAME}"
echo "  Size:   $(du -h "${DIST}/${ZIP_NAME}" | cut -f1)"
echo "  sha256: ${SHA256}"
echo ""
echo "Pra instalar:"
echo "  wp-admin → Plugins → Adicionar novo → Enviar plugin → ${DIST}/${ZIP_NAME}"
echo ""
echo "Ritual de release (sync no CRM):"
echo "  1. copiar o zip pra AGD-CRM/public/downloads/adspirit-connector.zip"
echo "     + cópia versionada adspirit-connector-${VERSION}.zip (rollback barato)"
echo "  2. atualizar manifest.json com version=${VERSION} e sha256 acima"
echo "     (updater 2.30+ verifica o hash antes de instalar; campo ausente = sem verificação)"
