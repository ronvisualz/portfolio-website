#!/bin/sh
# Builds admin/downloads/topper-render-farm.zip from a render-farm-topper checkout.
# Usage: tools/renderfarm/build.sh /path/to/render-farm-topper
set -e
HERE=$(cd "$(dirname "$0")" && pwd); SITE=$(cd "$HERE/../.." && pwd); SRC=$1
TMP=$(mktemp -d); mkdir "$TMP/topper-render-farm"
git -C "$SRC" archive HEAD | tar -x -C "$TMP/topper-render-farm"
cp "$HERE/Instalar.bat" "$HERE/install.ps1" "$HERE/Desinstalar.bat" "$TMP/topper-render-farm/"
mkdir -p "$SITE/admin/downloads"; rm -f "$SITE/admin/downloads/topper-render-farm.zip"
(cd "$TMP" && zip -qr "$SITE/admin/downloads/topper-render-farm.zip" topper-render-farm)
rm -rf "$TMP"; echo "built admin/downloads/topper-render-farm.zip"
