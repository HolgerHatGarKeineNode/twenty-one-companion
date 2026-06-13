# Releases verifizieren

Alle offiziellen Releases von **EINUNDZWANZIG Mobile** werden ausschließlich über
[GitHub Releases](https://github.com/HolgerHatGarKeineNode/einundzwanzig-mobile-app/releases)
veröffentlicht. Jedes Release enthält:

- die signierten APKs (`einundzwanzig-universal-vX.Y.Z.apk`)
- `manifest-vX.Y.Z.txt` — SHA256-Prüfsummen aller APKs
- `manifest-vX.Y.Z.txt.sig` — GPG-Signatur des Manifests

Verifiziere **immer** sowohl die GPG-Signatur des Manifests als auch die Prüfsumme der
heruntergeladenen APK, bevor du sie installierst.

## 1. GPG installieren

```bash
# Ubuntu/Debian
sudo apt install gnupg

# macOS (Homebrew)
brew install gnupg

# Windows: https://gpg4win.org
```

## 2. Signatur-Schlüssel importieren

Variante A — vom Keyserver:

```bash
gpg --keyserver hkps://keys.openpgp.org --recv-keys B2DD9D9969E61E617125346E6D5B01E06AA11B68
```

Variante B — aus diesem Repository ([`keys/B2DD9D9969E61E617125346E6D5B01E06AA11B68.asc`](keys/B2DD9D9969E61E617125346E6D5B01E06AA11B68.asc)):

```bash
curl -fsSL https://raw.githubusercontent.com/HolgerHatGarKeineNode/einundzwanzig-mobile-app/master/keys/B2DD9D9969E61E617125346E6D5B01E06AA11B68.asc | gpg --import
```

In beiden Fällen gilt: Vertraue dem Schlüssel erst nach dem Fingerprint-Check in Schritt 3.

## 3. Fingerprint prüfen

```bash
gpg --fingerprint B2DD9D9969E61E617125346E6D5B01E06AA11B68
```

Der angezeigte Fingerprint muss **exakt** lauten:

```
B2DD 9D99 69E6 1E61 7125  346E 6D5B 01E0 6AA1 1B68
```

Stimmt der Fingerprint nicht exakt überein: **NICHT FORTFAHREN.**

## 4. Manifest-Signatur prüfen

Lade `manifest-vX.Y.Z.txt` und `manifest-vX.Y.Z.txt.sig` aus dem Release herunter:

```bash
gpg --verify manifest-v1.0.0.txt.sig manifest-v1.0.0.txt
```

Erwartete Ausgabe (u. a.):

```
gpg: Good signature from "…"
```

## 5. APK-Prüfsumme vergleichen

```bash
sha256sum einundzwanzig-universal-v1.0.0.apk
# macOS: shasum -a 256 einundzwanzig-universal-v1.0.0.apk
```

Der ausgegebene Hash muss mit dem Eintrag in `manifest-v1.0.0.txt` übereinstimmen.

## 6. APK-Signaturzertifikat prüfen (optional, empfohlen)

Mit [AppVerifier](https://github.com/soupslurpr/AppVerifier) oder `apksigner` kannst du
zusätzlich das Android-Signaturzertifikat der APK prüfen:

```bash
apksigner verify --print-certs einundzwanzig-universal-v1.0.0.apk
```

Erwartete Werte:

```
Package: space.einundzwanzig.mobile
SHA-256: 44:41:1E:20:A1:B4:3D:0F:66:CF:99:E1:23:8A:33:E7:E8:FD:92:48:F0:D0:D2:58:F5:E0:72:7C:FA:BF:0B:7C
```

## Automatisches Verifikations-Skript

```bash
#!/usr/bin/env bash
# verify-einundzwanzig.sh — Verwendung: ./verify-einundzwanzig.sh <version> <apk-datei>
set -euo pipefail

VERSION="${1:?Verwendung: $0 <version> <apk-datei>}"
APK="${2:?Verwendung: $0 <version> <apk-datei>}"
FINGERPRINT="B2DD9D9969E61E617125346E6D5B01E06AA11B68"

gpg --keyserver hkps://keys.openpgp.org --recv-keys "$FINGERPRINT" \
    || curl -fsSL "https://raw.githubusercontent.com/HolgerHatGarKeineNode/einundzwanzig-mobile-app/master/keys/${FINGERPRINT}.asc" | gpg --import

echo "→ Prüfe Manifest-Signatur …"
gpg --verify "manifest-v${VERSION}.txt.sig" "manifest-v${VERSION}.txt"

echo "→ Prüfe SHA256-Hash von ${APK} …"
EXPECTED=$(grep "$(basename "$APK")" "manifest-v${VERSION}.txt" | awk '{print $1}')
ACTUAL=$(sha256sum "$APK" | awk '{print $1}')

if [ "$EXPECTED" = "$ACTUAL" ]; then
    echo "✅ Hash stimmt überein: $ACTUAL"
else
    echo "❌ HASH STIMMT NICHT ÜBEREIN!"
    echo "   erwartet: $EXPECTED"
    echo "   erhalten: $ACTUAL"
    exit 1
fi
```

## Wichtige Hinweise

- Lade Releases **nur** von der offiziellen GitHub-Releases-Seite.
- Verifiziere bei jedem Update erneut — nicht nur beim ersten Download.
