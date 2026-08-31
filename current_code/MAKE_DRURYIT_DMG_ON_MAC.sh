#!/bin/bash
set -e
APP_PATH="$1"
OUT="${2:-DruryIT-Support.dmg}"
TMP=$(mktemp -d)
cp -R "$APP_PATH" "$TMP/DruryIT Support.app"
ln -s /Applications "$TMP/Applications"
hdiutil create -volname "DruryIT Support" -srcfolder "$TMP" -ov -format UDZO "$OUT"
rm -rf "$TMP"
echo "Created $OUT"
