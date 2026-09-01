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

# ── Manifest-Riegel: welche Pfade beansprucht das APK als App-Link? ───────────
#
# Gemessen wird am ARTEFAKT, nicht am exit code. `scripts/apply-vendor-patches.sh`
# schraenkt den NativePHP-Deeplink-Filter auf config('nativephp.deeplink_path_prefixes')
# ein; faellt dieser Patch aus (composer update ueberschreibt vendor/), erzeugt
# NativePHP wieder android:pathPrefix="/" und die App beansprucht den GANZEN
# Portal-Host. Genau das trug das v1.9.4-APK, und dieser Lauf hier meldete exit 0.
# Ein Blick ins Manifest haette es in einer Sekunde gezeigt.
#
# Einzeln aufrufbar, damit die Kontrolle in tests/Feature/ReleaseManifestGuardTest.php
# den Riegel ohne APK-Build fahren kann:
#   ./scripts/release.sh --pruefe-manifest <apk-oder-xmltree-dump>
# <datei> ist entweder ein .apk (dann wird aapt2 gebraucht) oder ein bereits
# erzeugter `aapt2 dump xmltree`-Text.

finde_aapt2() {  # schreibt den Pfad nach stdout, 1 = nicht gefunden
    # Explizit gesetztes AAPT2 gewinnt — und wenn es unbrauchbar ist, wird NICHT
    # heimlich weitergesucht: wer den Pfad setzt, will genau dieses Binary gemessen.
    if [ -n "${AAPT2:-}" ]; then
        [ -x "$AAPT2" ] || return 1
        echo "$AAPT2"
        return 0
    fi
    if command -v aapt2 >/dev/null 2>&1; then
        command -v aapt2
        return 0
    fi
    local sdk kandidat
    for sdk in "${ANDROID_HOME:-}" "${ANDROID_SDK_ROOT:-}" "$HOME/Android/Sdk"; do
        [ -n "$sdk" ] && [ -d "$sdk/build-tools" ] || continue
        # Hoechste Build-Tools-Version, nie eine Version hartkodiert: der SDK-Manager
        # raeumt alte Verzeichnisse weg, ein fester Pfad waere nach dem naechsten
        # Update tot und der Riegel damit still wirkungslos.
        kandidat=$(find "$sdk/build-tools" -mindepth 2 -maxdepth 2 -name aapt2 -type f 2>/dev/null | sort -V | tail -n1)
        [ -n "$kandidat" ] && { echo "$kandidat"; return 0; }
    done
    return 1
}

manifest_dump() {  # $1 = APK oder xmltree-Dump; Dump nach stdout
    local ziel="$1" werkzeug
    if [ ! -f "$ziel" ]; then
        echo "❌ Manifest-Quelle nicht gefunden: $ziel" >&2
        return 1
    fi
    case "$ziel" in
        *.apk)
            if ! werkzeug=$(finde_aapt2); then
                echo "❌ aapt2 nicht gefunden — das APK-Manifest ist nicht lesbar." >&2
                echo "   Gesucht in: \$AAPT2, PATH, \$ANDROID_HOME, \$ANDROID_SDK_ROOT," >&2
                echo "   \$HOME/Android/Sdk/build-tools/*/aapt2." >&2
                echo "   Der Lauf bricht ab, statt die Pruefung zu ueberspringen: ein" >&2
                echo "   Riegel, der still nichts tut, ist genau der Fehler von v1.9.4." >&2
                echo "   Pfad notfalls direkt setzen: AAPT2=/pfad/zu/aapt2 $0 …" >&2
                return 1
            fi
            "$werkzeug" dump xmltree "$ziel" --file AndroidManifest.xml
            ;;
        *)
            cat "$ziel"
            ;;
    esac
}

pruefe_pfad_prefixe() {  # $1 = APK oder xmltree-Dump
    local ziel="$1" konfig host dump erwartet gefunden

    # Dieselbe Quelle, aus der der Patch die Filter baut — sonst prueft der Riegel
    # gegen eine zweite Wahrheit und geht bei einer Konfigurationsaenderung schief.
    if ! konfig=$(php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        echo (string) config("nativephp.deeplink_host"), "\n";
        foreach ((array) config("nativephp.deeplink_path_prefixes") as $prefix) { echo $prefix, "\n"; }
    '); then
        echo "❌ config/nativephp.php nicht lesbar — Manifest nicht verifizierbar." >&2
        return 1
    fi
    host=$(printf '%s\n' "$konfig" | head -n1)
    erwartet=$(printf '%s\n' "$konfig" | tail -n +2 | grep -v '^$' | sort -u)

    if [ -z "$host" ]; then
        echo "❌ nativephp.deeplink_host ist leer — das APK beansprucht gar keine" >&2
        echo "   App-Links. NATIVEPHP_DEEPLINK_HOST in .env fehlt." >&2
        return 1
    fi
    if [ -z "$erwartet" ] || printf '%s\n' "$erwartet" | grep -qx '/'; then
        echo "❌ nativephp.deeplink_path_prefixes beansprucht den ganzen Host ('/')" >&2
        echo "   oder ist leer — dann faellt der Patch selbst auf pathPrefix=\"/\" zurueck." >&2
        return 1
    fi

    dump=$(manifest_dump "$ziel") || return 1
    if ! printf '%s\n' "$dump" | grep -q 'E: manifest'; then
        echo "❌ Kein lesbarer AndroidManifest-Dump aus ${ziel} — nicht verifizierbar." >&2
        return 1
    fi

    # aapt2 gibt jedes <data> als Element mit seinen Attributen darunter aus. Gesammelt
    # wird deshalb pro data-Element, nicht ueber die ganze Datei: sonst wuerde ein host
    # aus einem Filter mit einem pathPrefix aus dem naechsten zusammenfallen.
    gefunden=$(printf '%s\n' "$dump" | awk -v h="$host" '
        function wert(zeile,   pos) {
            pos = match(zeile, /="[^"]*"/)
            return pos ? substr(zeile, pos + 2, RLENGTH - 3) : ""
        }
        /^[[:space:]]*E: / {
            if (indata && prefix != "" && host == h) { print prefix }
            indata = ($0 ~ /E: data \(/); host = ""; prefix = ""
            next
        }
        indata && /A: [^ ]*:?host\(/ { host = wert($0) }
        indata && /A: [^ ]*:?pathPrefix\(/ { prefix = wert($0) }
        END { if (indata && prefix != "" && host == h) { print prefix } }
    ' | sort -u)

    if [ "$gefunden" = "$erwartet" ]; then
        echo "   ✓ App-Link-Pfade: $(printf '%s\n' "$gefunden" | tr '\n' ' ')(Host ${host})"
        return 0
    fi

    echo "❌ Die App-Link-Pfade im Manifest stimmen nicht mit der Konfiguration ueberein." >&2
    echo "   Host:      ${host}" >&2
    echo "   erwartet:  $(printf '%s\n' "$erwartet" | tr '\n' ' ')" >&2
    echo "   im APK:    $(printf '%s\n' "${gefunden:-–}" | tr '\n' ' ')" >&2
    if printf '%s\n' "$gefunden" | grep -qx '/'; then
        echo "   pathPrefix=\"/\" beansprucht den GANZEN Portal-Host — das ist der" >&2
        echo "   v1.9.4-Fehler: der Deeplink-Patch in scripts/apply-vendor-patches.sh" >&2
        echo "   hat nicht gegriffen (vendor/ nach composer update ungepatcht?)." >&2
        echo "   Nachziehen mit: bash scripts/apply-vendor-patches.sh, dann neu bauen." >&2
    fi
    return 1
}

# Nur den Riegel fahren (fuer die Kontrolle im Testlauf) — bewusst VOR dem
# composer-Trap, damit dieser Einstieg keine Nebenwirkung auf vendor/ hat.
if [ "${1:-}" = "--pruefe-manifest" ]; then
    if [ -z "${2:-}" ]; then
        echo "❌ Aufruf: $0 --pruefe-manifest <apk-oder-xmltree-dump>" >&2
        exit 2
    fi
    if pruefe_pfad_prefixe "$2"; then exit 0; else exit 1; fi
fi

# Der --no-dev-Vorlauf unten raeumt pest/phpstan/phpunit aus dem Arbeitsplatz.
# Ohne diesen trap bliebe ein abgebrochener Lauf mit einem Repo zurueck, in dem
# keine Tests mehr laufen.
RESTORE_DEV=""
restore_dev_dependencies() {
    [ -n "$RESTORE_DEV" ] || return 0
    echo "→ Dev-Abhaengigkeiten wiederherstellen …"
    composer install --no-interaction --quiet || \
        echo "⚠️  'composer install' fehlgeschlagen — bitte von Hand nachholen."
}
trap restore_dev_dependencies EXIT

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
    npm run build -- --mode=android

    echo "→ Plugin-Manifeste pruefen …"
    # `native:plugin:validate` prueft Manifest-Syntax, Bridge-Function-Deklarationen,
    # Hook-Registrierungen und Asset-Praesenz. Unsere drei Manifeste sind handgeschrieben
    # und tragen zusammen 12 Bridge-Functions — bis 2026-08-28 lief nie eine Pruefung
    # darueber. Ein Tippfehler im Manifest faellt sonst erst auf dem Geraet auf.
    for PLUGIN in packages/push packages/calendar ../einundzwanzig-group/packages/amber-signer; do
        [ -f "$PLUGIN/nativephp.json" ] || continue
        php artisan native:plugin:validate "$PLUGIN" --no-interaction
    done

    echo "→ Boot-Optimierungs-Patches auf die NativePHP-Templates anwenden (opcache etc.) …"
    # Muss VOR native:package laufen: patcht die vendor-Templates, damit ein
    # etwaiges Neu-Scaffolding die Optimierungen enthält. Idempotent + fail-fast.
    bash scripts/apply-vendor-patches.sh

    # WURZEL des PathDownloader-Problems, eine Ebene tiefer als der Vorlauf unten:
    # native:package kopiert das Projekt nach nativephp/android/laravel/ und faehrt
    # dort `composer install`. Path-Repos loesen relativ zur composer.json auf — vom
    # Temp-Verzeichnis aus ist "../einundzwanzig-group/packages/…" also
    # nativephp/android/einundzwanzig-group/packages/…, und das gibt es nicht.
    # Ein Symlink genau dorthin macht die Pfade wieder aufloesbar. Er ist relativ,
    # es landet also kein rechnergebundener Pfad im Repo, und nativephp/ ist
    # vollstaendig gitignored (nativephp/.gitignore: "*").
    if [ -d nativephp/android ]; then
        if [ -d ../einundzwanzig-group ]; then
            ln -sfn ../../../einundzwanzig-group nativephp/android/einundzwanzig-group
            echo "→ Path-Repos fuer das Temp-Verzeichnis verlinkt …"
        else
            echo "⚠️  ../einundzwanzig-group fehlt — composer findet die Path-Pakete im"
            echo "    Temp-Verzeichnis nicht. Vorlauf und Bundle-Riegel fangen das ab."
        fi
    fi

    # Die Path-Repositories in composer.json zeigen aus dem Projekt heraus
    # (../einundzwanzig-group/…). native:package kopiert das Projekt in ein
    # Temp-Verzeichnis und faehrt dort `composer install --no-dev` — dieser
    # relative Pfad existiert dort NICHT. Sobald composer ein Path-Paket
    # anfassen muss (jedes Update, z. B. ein Flux-Bump), bricht es mit
    # "PathDownloader: Source path … is not found" ab. NativePHP protokolliert
    # das nur und baut weiter, exit 0. Die 89 dev-removals unterbleiben und
    # das Bundle traegt phpstan/PHPUnit/Pest/Faker/psy mit ins APK
    # (v1.9.4-Erstlauf: 85 MB statt 38 MB, Bundle 68,7 statt 20,6 MB).
    #
    # Deshalb hier VORAB im Projekt auf --no-dev stellen: die Pfade loesen auf,
    # der kopierte vendor ist bereits schlank, und ein Scheitern im Temp-Lauf
    # kann ihn nicht mehr aufblaehen.
    echo "→ vendor/ auf --no-dev stellen (Dev-Pakete gehoeren nicht ins APK) …"
    RESTORE_DEV=1
    composer install --no-dev --no-interaction --quiet

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

echo "→ Bundle gegen Dev-Pakete pruefen …"
# Der Fehlerpfad oben ist STILL: er endet mit exit 0 und einem fertigen APK.
# Nur eine Messung am Artefakt faengt ihn ab. Fail-closed: ist das Bundle nicht
# lesbar, gilt der Build als durchgefallen, nicht als sauber.
BUNDLE_TMP=$(mktemp -d)
trap 'rm -rf "$BUNDLE_TMP"; restore_dev_dependencies' EXIT
if ! unzip -o -q -j "${DIST}/${APK_NAME}" assets/laravel_bundle.zip -d "$BUNDLE_TMP"; then
    echo "❌ assets/laravel_bundle.zip nicht aus dem APK lesbar — Build nicht verifizierbar."
    exit 1
fi
# Das Muster kommt aus require-dev, nicht aus einer Handliste: eine Handliste
# altert und trifft daneben. Gemessen am v1.9.4-Lauf — 'vendor/phpstan/' fing
# phpstan/phpdoc-parser mit (haengt an spatie/laravel-data) und 'psy' gehoert zu
# laravel/tinker; beide sind require, beide waren schon in v1.9.3 im Bundle.
# Geprueft wird deshalb der volle Paketpfad der Top-Level-Dev-Pakete: faellt
# eines davon weg, fallen seine transitiven Kinder mit.
DEV_PATTERN=$(php -r '$c = json_decode(file_get_contents("composer.json"), true);
    $p = array_keys($c["require-dev"] ?? []);
    // Nur den Punkt escapen: Paketnamen bestehen aus [a-z0-9_.-] und "/", und
    // preg_quote maskiert auch "-", was POSIX-ERE nicht kennt ("stray \\ before -").
    echo implode("|", array_map(fn ($n) => str_replace(".", "\\.", "vendor/$n/"), $p));')
if [ -z "$DEV_PATTERN" ]; then
    echo "❌ require-dev aus composer.json nicht lesbar — Build nicht verifizierbar."
    exit 1
fi
DEV_HITS=$(unzip -l "$BUNDLE_TMP/laravel_bundle.zip" | grep -cE "$DEV_PATTERN" || true)
if [ "$DEV_HITS" -gt 0 ]; then
    echo "❌ Das Bundle traegt ${DEV_HITS} Dateien aus require-dev-Paketen:"
    unzip -l "$BUNDLE_TMP/laravel_bundle.zip" | grep -oE "$DEV_PATTERN" | sort -u | sed 's/^/     /'

    echo "   Ursache ist fast immer ein abgebrochenes 'composer install --no-dev' im"
    echo "   Temp-Verzeichnis — siehe nativephp/android-build.log, Stichwort PathDownloader."
    exit 1
fi
echo "   ✓ keine Dev-Pakete im Bundle"

echo "→ App-Link-Pfade im APK-Manifest pruefen …"
if ! pruefe_pfad_prefixe "${DIST}/${APK_NAME}"; then
    exit 1
fi

echo "→ ${MANIFEST} erzeugen …"
(cd "$DIST" && sha256sum ./*.apk | sed 's|\./||' > "$MANIFEST")

echo "→ Manifest mit GPG signieren (Key ${GPG_KEY}) …"
# Eine Signatur aus einem frueheren Lauf MUSS vorher weg. Scheitert das Signieren
# und bleibt sie liegen, steht eine .sig neben einem neueren Manifest, zu dem sie
# nicht gehoert — am 2026-08-28 gemessen als "BAD signature". Eine falsche
# Signatur ist schlimmer als gar keine: sie sieht aus wie eine Zusicherung.
rm -f "${DIST}/${MANIFEST}.sig"
# Kein --no-tty: das verbietet gpg, nach der Passphrase zu fragen, und laesst den
# Lauf scheitern, sobald der Agent sie nicht gecacht hat.
if ! gpg --local-user "$GPG_KEY" --detach-sign "${DIST}/${MANIFEST}"; then
    echo
    echo "❌ Signieren fehlgeschlagen."
    echo "   Haeufigste Ursache: kein TTY fuer die Passphrase (Hintergrundlauf,"
    echo "   Meldung 'no terminal at all requested'). APK und Manifest sind fertig,"
    echo "   es fehlt NUR die Signatur. In einer interaktiven Shell nachholen:"
    echo "     SKIP_BUILD=1 ./scripts/release.sh"
    exit 1
fi

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
