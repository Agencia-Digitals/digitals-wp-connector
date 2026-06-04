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
TARGET="${DIST}/${SLUG}"
ZIP_NAME="${SLUG}-v${VERSION}.zip"

echo "Building ${SLUG} v${VERSION}…"

rm -rf "${DIST}"
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
echo ""
echo "Pra instalar:"
echo "  wp-admin → Plugins → Adicionar novo → Enviar plugin → ${DIST}/${ZIP_NAME}"
