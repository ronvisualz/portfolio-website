#!/bin/sh
# Builds admin/downloads/Instalar-Render-Farm.cmd: one file that installs and opens
# topper. Render Farm on Windows (header.cmd + setup.ps1 + the app as a base64 ZIP).
# Usage: tools/renderfarm/build.sh /path/to/render-farm-topper
set -e
HERE=$(cd "$(dirname "$0")" && pwd); SITE=$(cd "$HERE/../.." && pwd); SRC=$1
OUT="$SITE/admin/downloads/Instalar-Render-Farm.cmd"
TMP=$(mktemp -d); mkdir "$TMP/topper-render-farm"
git -C "$SRC" archive HEAD | tar -x -C "$TMP/topper-render-farm"
cp "$HERE/Abrir.cmd" "$HERE/Desinstalar.bat" "$TMP/topper-render-farm/"
(cd "$TMP" && zip -qr app.zip topper-render-farm)
mkdir -p "$SITE/admin/downloads"
{
  cat "$HERE/header.cmd"
  printf '#__PS1__#\r\n'
  cat "$HERE/setup.ps1"
  printf '#__ZIP__#\r\n'
  base64 -w 76 "$TMP/app.zip" | sed 's/$/\r/'
} > "$OUT"
rm -rf "$TMP"; echo "built $OUT ($(wc -c < "$OUT") bytes)"
