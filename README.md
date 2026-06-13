# EINUNDZWANZIG Mobile

Eine mobile App der [EINUNDZWANZIG](https://einundzwanzig.space)-Community für Android:
Meetups, Termine, Kurse, Referenten und Orte aus dem
[EINUNDZWANZIG-Portal](https://portal.einundzwanzig.space) — direkt in deiner Tasche.

- 📅 Meetups & Termine mit Regions-Filter
- 🗺️ Karte aller Meetups (OpenStreetMap)
- 🎓 Kurse & Referenten
- ⚡ Login per Lightning (LNURL-auth) oder Nostr (NIP-55, z. B. [Amber](https://github.com/greenart7c3/Amber))
- 📦 Offline-fähig: zuletzt geladene Daten bleiben verfügbar
- 🌐 Deutsch & Englisch

Gebaut mit [NativePHP Mobile](https://nativephp.com/mobile), Laravel, Livewire und Flux UI.

## Download & Installation

> Es gibt **keinen** Play-Store-Release. Offizielle Builds gibt es über
> [Zapstore](https://zapstore.dev) und GitHub Releases:

- **Zapstore:** In der [Zapstore](https://zapstore.dev)-App nach **EINUNDZWANZIG** suchen.
  Installation und Updates sind Nostr-signiert und werden automatisch gegen die Identität
  des Projekts geprüft (siehe unten).
- **Obtainium:** [Obtainium](https://obtainium.imranr.dev) installiert die App direkt aus den
  GitHub-Releases und hält sie automatisch aktuell. Quelle per 1-Klick hinzufügen:
  **[➜ In Obtainium öffnen](https://apps.obtainium.imranr.dev/redirect.html?r=obtainium://add/https://github.com/HolgerHatGarKeineNode/einundzwanzig-mobile-app)**
  — oder in Obtainium **Add App** öffnen und die Repo-URL
  `https://github.com/HolgerHatGarKeineNode/einundzwanzig-mobile-app` einfügen. Obtainium
  installiert Updates nur, wenn sie dieselbe Android-Signatur tragen (siehe unten).
- **GitHub:** APK aus dem [neuesten Release](https://github.com/HolgerHatGarKeineNode/einundzwanzig-mobile-app/releases/latest) laden — danach **verifizieren** (siehe unten)

## Sicherheit & Verifikation

Alle Releases werden kryptografisch signiert: Das Release-Manifest (`manifest-vX.Y.Z.txt`
mit den SHA256-Prüfsummen aller APKs) ist GPG-signiert, die APKs tragen die
Android-Signatur des Projekts.

Die vollständige Anleitung steht in [VERIFY_RELEASES.md](VERIFY_RELEASES.md).

**GPG-Signaturschlüssel:**

```
Key-ID:      B2DD9D9969E61E617125346E6D5B01E06AA11B68
Fingerprint: B2DD 9D99 69E6 1E61 7125  346E 6D5B 01E0 6AA1 1B68
```

**Android-Signaturzertifikat** (für [AppVerifier](https://github.com/soupslurpr/AppVerifier) / `apksigner`):

```
Package: space.einundzwanzig.mobile
SHA-256: 44:41:1E:20:A1:B4:3D:0F:66:CF:99:E1:23:8A:33:E7:E8:FD:92:48:F0:D0:D2:58:F5:E0:72:7C:FA:BF:0B:7C
```

**Zapstore (Nostr):** Über Zapstore verteilte Releases sind mit dem Nostr-Schlüssel des
Projekts signiert. Das oben genannte Android-Signaturzertifikat ist per **NIP-C1** kryptografisch
an diese Identität gebunden — Zapstore prüft bei Installation und Update automatisch, dass die
App vom echten Herausgeber stammt.

```
Publisher-npub: npub1pt0kw36ue3w2g4haxq3wgm6a2fhtptmzsjlc2j2vphtcgle72qesgpjyc6
```

Schwachstellen bitte vertraulich melden — siehe [SECURITY.md](SECURITY.md).

## Entwicklung

```bash
composer install && yarn install
yarn build --mode=android
php artisan native:run android        # Build + Start im Emulator/Gerät
php artisan test --compact            # Tests
```

Details zu Setup, Dev-Loop und Architektur: [`docs/nativephp-ausfuehrungsplan.md`](docs/nativephp-ausfuehrungsplan.md)
und [`PLAN.md`](PLAN.md).

## Release veröffentlichen

```bash
php artisan native:release patch      # Version bumpen
./scripts/release.sh                  # APK bauen, Manifest erzeugen, GPG-signieren
```

Das Skript legt alle GitHub-Release-Artefakte unter `dist/v<version>/` ab
(APK, `manifest-v<version>.txt`, `manifest-v<version>.txt.sig`). Anschließend wird über zwei
Kanäle veröffentlicht:

1. **GitHub:** Release unter dem Tag `v<version>` anlegen und die `dist/`-Artefakte anhängen.
2. **Zapstore:** *nach* dem GitHub-Release mit dem Nostr-Schlüssel des Projekts publizieren:

   ```bash
   zsp publish zapstore.yaml          # zieht die APK aus dem GitHub-Release
   ```

   Metadaten und Screenshots stehen in [`zapstore.yaml`](zapstore.yaml); signiert wird per
   NIP-46 (Amber-Bunker).

## Lizenz

[MIT](LICENSE)
