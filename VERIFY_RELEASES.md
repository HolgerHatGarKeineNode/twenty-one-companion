# Verifying releases

All official releases of **TWENTY ONE Companion** are published exclusively via
[GitHub Releases](https://github.com/HolgerHatGarKeineNode/twenty-one-companion/releases).
Every release contains:

- the signed APKs (`twenty-one-companion-vX.Y.Z.apk`)
- `manifest-vX.Y.Z.txt` — SHA256 checksums of all APKs
- `manifest-vX.Y.Z.txt.sig` — GPG signature of the manifest

**Always** verify both the GPG signature of the manifest and the checksum of the
downloaded APK before installing it.

## 1. Install GPG

```bash
# Ubuntu/Debian
sudo apt install gnupg

# macOS (Homebrew)
brew install gnupg

# Windows: https://gpg4win.org
```

## 2. Import the signing key

Option A — from a keyserver:

```bash
gpg --keyserver hkps://keys.openpgp.org --recv-keys B2DD9D9969E61E617125346E6D5B01E06AA11B68
```

Option B — from this repository ([`keys/B2DD9D9969E61E617125346E6D5B01E06AA11B68.asc`](keys/B2DD9D9969E61E617125346E6D5B01E06AA11B68.asc)):

```bash
curl -fsSL https://raw.githubusercontent.com/HolgerHatGarKeineNode/twenty-one-companion/master/keys/B2DD9D9969E61E617125346E6D5B01E06AA11B68.asc | gpg --import
```

In both cases: only trust the key after the fingerprint check in step 3.

## 3. Check the fingerprint

```bash
gpg --fingerprint B2DD9D9969E61E617125346E6D5B01E06AA11B68
```

The displayed fingerprint must be **exactly**:

```
B2DD 9D99 69E6 1E61 7125  346E 6D5B 01E0 6AA1 1B68
```

If the fingerprint does not match exactly: **DO NOT CONTINUE.**

## 4. Verify the manifest signature

Download `manifest-vX.Y.Z.txt` and `manifest-vX.Y.Z.txt.sig` from the release:

```bash
gpg --verify manifest-v1.0.0.txt.sig manifest-v1.0.0.txt
```

Expected output (among others):

```
gpg: Good signature from "…"
```

## 5. Compare the APK checksum

```bash
sha256sum twenty-one-companion-v1.0.0.apk
# macOS: shasum -a 256 twenty-one-companion-v1.0.0.apk
```

The printed hash must match the entry in `manifest-v1.0.0.txt`.

## 6. Verify the APK signing certificate (optional, recommended)

With [AppVerifier](https://github.com/soupslurpr/AppVerifier) or `apksigner` you can
additionally check the APK's Android signing certificate:

```bash
apksigner verify --print-certs twenty-one-companion-v1.0.0.apk
```

Expected values:

```
Package: space.einundzwanzig.mobile
SHA-256: 44:41:1E:20:A1:B4:3D:0F:66:CF:99:E1:23:8A:33:E7:E8:FD:92:48:F0:D0:D2:58:F5:E0:72:7C:FA:BF:0B:7C
```

## Automatic verification script

```bash
#!/usr/bin/env bash
# verify-release.sh — usage: ./verify-release.sh <version> <apk-file>
set -euo pipefail

VERSION="${1:?Usage: $0 <version> <apk-file>}"
APK="${2:?Usage: $0 <version> <apk-file>}"
FINGERPRINT="B2DD9D9969E61E617125346E6D5B01E06AA11B68"

gpg --keyserver hkps://keys.openpgp.org --recv-keys "$FINGERPRINT" \
    || curl -fsSL "https://raw.githubusercontent.com/HolgerHatGarKeineNode/twenty-one-companion/master/keys/${FINGERPRINT}.asc" | gpg --import

echo "→ Verifying manifest signature …"
gpg --verify "manifest-v${VERSION}.txt.sig" "manifest-v${VERSION}.txt"

echo "→ Verifying SHA256 hash of ${APK} …"
EXPECTED=$(grep "$(basename "$APK")" "manifest-v${VERSION}.txt" | awk '{print $1}')
ACTUAL=$(sha256sum "$APK" | awk '{print $1}')

if [ "$EXPECTED" = "$ACTUAL" ]; then
    echo "✅ Hash matches: $ACTUAL"
else
    echo "❌ HASH MISMATCH!"
    echo "   expected: $EXPECTED"
    echo "   actual:   $ACTUAL"
    exit 1
fi
```

## Important notes

- Download releases **only** from the official GitHub releases page.
- Re-verify on every update — not just on the first download.
