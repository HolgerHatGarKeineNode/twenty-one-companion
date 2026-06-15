#!/usr/bin/env bash
#
# Baut ein signiertes Release-APK und erzeugt die GitHub-Release-Artefakte
# im Amber-Stil:
#
#   dist/v<version>/twenty-one-companion-v<version>.apk
#   dist/v<version>/manifest-v<version>.txt        (SHA256-Prüfsummen)
#   dist/v<version>/manifest-v<version>.txt.sig    (GPG-Signatur, detached)
#
# Voraussetzungen:
#   - ANDROID_KEYSTORE_* in .env (siehe credentials/)
#   - JAVA_HOME/keytool verfügbar (JetBrains JBR reicht)
#   - GPG-Key des Maintainers im lokalen Schlüsselbund
#
# Verwendung:
#   ./scripts/release.sh            # signiert mit dem Maintainer-Key (siehe GPG_KEY unten)
#   GPG_KEY=<fingerprint> ./scripts/release.sh
#   SKIP_BUILD=1 ./scripts/release.sh   # nur Artefakte aus vorhandenem Build erzeugen
set -euo pipefail

# Maintainer-Signaturschlüssel (siehe README.md / VERIFY_RELEASES.md)
GPG_KEY="${GPG_KEY:-B2DD9D9969E61E617125346E6D5B01E06AA11B68}"

cd "$(dirname "$0")/.."

JBR="$HOME/.local/share/JetBrains/Toolbox/apps/android-studio/jbr"
[ -d "$JBR" ] && export JAVA_HOME="$JBR" PATH="$JBR/bin:$PATH"

VERSION=$(grep -oP '^NATIVEPHP_APP_VERSION=\K.*' .env)
if [ -z "$VERSION" ] || [ "$VERSION" = "DEBUG" ]; then
    echo "❌ NATIVEPHP_APP_VERSION in .env muss eine echte Version sein (z. B. 1.0.0), nicht: '${VERSION:-leer}'"
    echo "   Versionen bitte mit 'php artisan native:release patch|minor|major' bumpen."
    exit 1
fi

APK_SOURCE="nativephp/android/app/build/outputs/apk/release/app-release.apk"
DIST="dist/v${VERSION}"
APK_NAME="twenty-one-companion-v${VERSION}.apk"
MANIFEST="manifest-v${VERSION}.txt"

if [ -z "${SKIP_BUILD:-}" ]; then
    echo "→ Frontend-Assets bauen …"
    yarn build --mode=android

    echo "→ Signiertes Release-APK bauen …"
    php artisan native:package android --build-type=release --no-tty --no-interaction
fi

if [ ! -f "$APK_SOURCE" ]; then
    echo "❌ Build-Artefakt fehlt: $APK_SOURCE"
    exit 1
fi

echo "→ Artefakte nach ${DIST}/ kopieren …"
mkdir -p "$DIST"
cp "$APK_SOURCE" "${DIST}/${APK_NAME}"

echo "→ ${MANIFEST} erzeugen …"
(cd "$DIST" && sha256sum ./*.apk | sed 's|\./||' > "$MANIFEST")

echo "→ Manifest mit GPG signieren (Key ${GPG_KEY}) …"
gpg --local-user "$GPG_KEY" --detach-sign "${DIST}/${MANIFEST}"

echo "→ Signatur gegenprüfen …"
gpg --verify "${DIST}/${MANIFEST}.sig" "${DIST}/${MANIFEST}"

echo
echo "✅ Release-Artefakte bereit in ${DIST}/:"
ls -la "$DIST"
echo
echo "Nächste Schritte:"
echo "  1. Release-Build einmal auf dem Gerät rauchtesten:"
echo "     adb install ${DIST}/${APK_NAME}"
echo "  2. GitHub-Release anlegen (Tag v${VERSION}) und alle Dateien aus ${DIST}/ anhängen:"
echo "     gh release create v${VERSION} ${DIST}/* --title 'v${VERSION}' --notes-file <notes.md>"
