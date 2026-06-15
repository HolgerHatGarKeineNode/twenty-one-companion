# TWENTY ONE Companion

A mobile companion app for the [TWENTY ONE Portal](https://portal.einundzwanzig.space) on Android:
meetups, events, courses, lecturers and places of the Bitcoin community — right in your pocket.

- 📅 Meetups & events with a region filter
- 🗺️ Map of all meetups (OpenStreetMap)
- 🎓 Courses & lecturers
- ⚡ Sign in with Lightning (LNURL-auth) or Nostr (NIP-55, e.g. [Amber](https://github.com/greenart7c3/Amber))
- 📦 Offline-ready: recently loaded data stays available
- 🌐 German & English

Built with [NativePHP Mobile](https://nativephp.com/mobile), Laravel, Livewire and Flux UI.

## Download & installation

> There is **no** Play Store release. Official builds are available via
> [Zapstore](https://zapstore.dev) and GitHub Releases:

- **Zapstore:** Search for **TWENTY ONE** in the [Zapstore](https://zapstore.dev) app.
  Installs and updates are Nostr-signed and automatically verified against the project's
  identity (see below).
- **Obtainium:** [Obtainium](https://obtainium.imranr.dev) installs the app directly from the
  GitHub releases and keeps it up to date automatically. Add the source with one click:
  **[➜ Open in Obtainium](https://apps.obtainium.imranr.dev/redirect.html?r=obtainium://add/https://github.com/HolgerHatGarKeineNode/twenty-one-companion)**
  — or open **Add App** in Obtainium and paste the repo URL
  `https://github.com/HolgerHatGarKeineNode/twenty-one-companion`. Obtainium only installs
  updates that carry the same Android signature (see below).
- **GitHub:** Download the APK from the [latest release](https://github.com/HolgerHatGarKeineNode/twenty-one-companion/releases/latest) — then **verify** it (see below).

## Security & verification

All releases are cryptographically signed: the release manifest (`manifest-vX.Y.Z.txt`
with the SHA256 checksums of all APKs) is GPG-signed, and the APKs carry the project's
Android signature.

The full guide is in [VERIFY_RELEASES.md](VERIFY_RELEASES.md).

**GPG signing key:**

```
Key-ID:      B2DD9D9969E61E617125346E6D5B01E06AA11B68
Fingerprint: B2DD 9D99 69E6 1E61 7125  346E 6D5B 01E0 6AA1 1B68
```

**Android signing certificate** (for [AppVerifier](https://github.com/soupslurpr/AppVerifier) / `apksigner`):

```
Package: space.einundzwanzig.mobile
SHA-256: 44:41:1E:20:A1:B4:3D:0F:66:CF:99:E1:23:8A:33:E7:E8:FD:92:48:F0:D0:D2:58:F5:E0:72:7C:FA:BF:0B:7C
```

**Zapstore (Nostr):** Releases distributed via Zapstore are signed with the project's Nostr key.
The Android signing certificate above is cryptographically bound to that identity via **NIP-C1** —
Zapstore automatically verifies on install and update that the app comes from the genuine publisher.

```
Publisher npub: npub1pt0kw36ue3w2g4haxq3wgm6a2fhtptmzsjlc2j2vphtcgle72qesgpjyc6
```

Please report vulnerabilities confidentially — see [SECURITY.md](SECURITY.md).

## Development

```bash
composer install && yarn install
yarn build --mode=android
php artisan native:run android        # Build + run on emulator/device
php artisan test --compact            # Tests
```

For setup, dev loop and architecture details: [`docs/nativephp-ausfuehrungsplan.md`](docs/nativephp-ausfuehrungsplan.md)
and [`PLAN.md`](PLAN.md).

## Publishing a release

```bash
php artisan native:release patch      # Bump the version
./scripts/release.sh                  # Build the APK, generate the manifest, GPG-sign
```

The script writes all GitHub release artifacts to `dist/v<version>/`
(APK, `manifest-v<version>.txt`, `manifest-v<version>.txt.sig`). It is then published through two
channels:

1. **GitHub:** Create a release under the tag `v<version>` and attach the `dist/` artifacts.
2. **Zapstore:** *after* the GitHub release, publish it with the project's Nostr key:

   ```bash
   zsp publish zapstore.yaml          # pulls the APK from the GitHub release
   ```

   Metadata and screenshots live in [`zapstore.yaml`](zapstore.yaml); signing is done via
   NIP-46 (Amber bunker).

## License

[MIT](LICENSE)
