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
#   - ein JDK zwischen 17 und 24 auffindbar (der Android-Studio-JBR reicht NICHT
#     mehr, er ist 25 — siehe den Block „JDK-Wahl" unten)
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
# Gemessen wird am ARTEFAKT, nicht am exit code. NativePHP schraenkt den
# App-Link-Filter seit 4.4.0 selbst auf config('nativephp.deeplink_paths') ein
# (davor tat das ein lokaler Vendor-Patch); faellt dieses Scoping aus — Downgrade,
# Upstream-Umbau, ein leerer Konfigurationswert —, erzeugt NativePHP wieder
# android:pathPrefix="/" und die App beansprucht den GANZEN Portal-Host. Genau das
# trug das v1.9.4-APK, und dieser Lauf hier meldete exit 0. Ein Blick ins Manifest
# haette es in einer Sekunde gezeigt.
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
        foreach ((array) config("nativephp.deeplink_paths") as $prefix) { echo $prefix, "\n"; }
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
        echo "❌ nativephp.deeplink_paths beansprucht den ganzen Host ('/')" >&2
        echo "   oder ist leer — bei leerer Liste faellt NativePHP selbst auf" >&2
        echo "   pathPrefix=\"/\" zurueck (dokumentiert in deepLinkPathData())." >&2
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
    #
    # Gesammelt wird NUR pathPrefix. Ein Konfigurationseintrag ohne Schraegstrich am
    # Ende wird von NativePHP 4.4.0 zu android:path (exakt) statt pathPrefix — ein
    # solcher Eintrag laesst diesen Riegel also rot werden. Das ist gewollt: lieber
    # laut, als dass hier still ein Pfad ungemessen durchginge. Wer exakte Pfade
    # beansprucht, zieht den awk-Zweig hier nach.
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
        echo "   v1.9.4-Fehler: das Deeplink-Scoping hat nicht gegriffen (NativePHP" >&2
        echo "   unter 4.4.0 heruntergestuft, oder deeplink_paths leer/ungelesen)." >&2
        echo "   Pruefen mit: bash scripts/apply-vendor-patches.sh, dann neu bauen." >&2
    fi
    return 1
}

# ── Signatur-Riegel: welche APK-Signaturschemata traegt das Artefakt? ─────────
#
# Gemessen wird am ARTEFAKT, nicht am exit code — dieselbe Regel wie beim
# Bundle- und beim Manifest-Riegel.
#
# Bis v1.12.1 trug jedes Release-APK NUR das v2-Schema:
#   $ apksigner verify --verbose dist/v1.12.1/twenty-one-companion-v1.12.1.apk
#   Verified using v1 scheme (JAR signing): false
#   Verified using v2 scheme (APK Signature Scheme v2): true
#   Verified using v3 scheme (APK Signature Scheme v3): false
# Dasselbe in v1.9.5, v1.10.0, v1.11.0, v1.12.0. Ursache: der signingConfigs-Block
# des NativePHP-Templates setzt keine enableV*Signing-Flags, also entscheiden die
# AGP-Defaults, und die sind bei minSdk 33 v2 allein. Gesetzt werden die Flags
# seit 2026-09-22 von patch_gradle_signing() in scripts/apply-vendor-patches.sh;
# dieser Riegel prueft, ob sie im fertigen APK auch angekommen sind.
#
# DER ERWARTETE ZERTIFIKATS-HASH STEHT HIER HARTKODIERT, und das ist Absicht:
# 44411e20…0b7c ist der SHA-256 des Maintainer-Zertifikats. Er steht als
# `apk_certificate_hash` in jedem veroeffentlichten Nostr-Event (zapstore) und im
# NIP-C1-Nachweis; Android bindet jede bestehende Installation an genau dieses
# Zertifikat. Ein anderer Hash bedeutet ein anderes Zertifikat und damit: kein
# Update mehr fuer irgendeinen Bestandsnutzer, ein ungueltiger C1-Proof, eine
# Neuinstallation als einziger Weg. Der Wert darf deshalb NICHT aus dem Keystore
# oder aus dem APK selbst abgeleitet werden — eine Pruefung, die ihren Sollwert
# aus dem Pruefling zieht, sagt nichts. Er darf nur dann geaendert werden, wenn
# ein Zertifikatswechsel BEWUSST beschlossen und angekuendigt ist.
#
# Einzeln aufrufbar, damit die Kontrolle in tests/Feature/ReleaseSignatureGuardTest.php
# den Riegel ohne APK-Build fahren kann:
#   ./scripts/release.sh --pruefe-signatur <apk-oder-apksigner-ausgabe>
# <datei> ist entweder ein .apk (dann wird apksigner gebraucht) oder ein bereits
# erzeugter `apksigner verify --verbose --print-certs`-Text.
APK_ZERT_SHA256="44411e20a1b43d0f66cf99e1238a33e7e8fd9248f0d0d258f5e0727cfabf0b7c"

finde_apksigner() {  # schreibt den Pfad nach stdout, 1 = nicht gefunden
    # Wortgleich zu finde_aapt2(): explizit gesetztes APKSIGNER gewinnt und wird
    # nicht heimlich uebergangen, danach PATH, dann die SDK-Wurzeln, dort immer die
    # hoechste Build-Tools-Version. Nie eine Version hartkodiert — der SDK-Manager
    # raeumt alte Verzeichnisse weg, und ein toter fester Pfad machte den Riegel
    # still wirkungslos.
    if [ -n "${APKSIGNER:-}" ]; then
        [ -x "$APKSIGNER" ] || return 1
        echo "$APKSIGNER"
        return 0
    fi
    if command -v apksigner >/dev/null 2>&1; then
        command -v apksigner
        return 0
    fi
    local sdk kandidat
    for sdk in "${ANDROID_HOME:-}" "${ANDROID_SDK_ROOT:-}" "$HOME/Android/Sdk"; do
        [ -n "$sdk" ] && [ -d "$sdk/build-tools" ] || continue
        kandidat=$(find "$sdk/build-tools" -mindepth 2 -maxdepth 2 -name apksigner -type f 2>/dev/null | sort -V | tail -n1)
        [ -n "$kandidat" ] && { echo "$kandidat"; return 0; }
    done
    return 1
}

signatur_bericht() {  # $1 = APK oder apksigner-Ausgabe, $2 = min-sdk (leer = aus dem APK)
    local ziel="$1" minsdk="${2:-}" werkzeug args=()
    if [ ! -f "$ziel" ]; then
        echo "❌ Signatur-Quelle nicht gefunden: $ziel" >&2
        return 1
    fi
    case "$ziel" in
        *.apk)
            if ! werkzeug=$(finde_apksigner); then
                echo "❌ apksigner nicht gefunden — die APK-Signatur ist nicht lesbar." >&2
                echo "   Gesucht in: \$APKSIGNER, PATH, \$ANDROID_HOME, \$ANDROID_SDK_ROOT," >&2
                echo "   \$HOME/Android/Sdk/build-tools/*/apksigner." >&2
                echo "   Der Lauf bricht ab, statt die Pruefung zu ueberspringen: ein" >&2
                echo "   Riegel, der still nichts tut, ist genau der Fehler von v1.9.4." >&2
                echo "   Pfad notfalls direkt setzen: APKSIGNER=/pfad/zu/apksigner $0 …" >&2
                return 1
            fi
            args=(verify --verbose --print-certs)
            [ -n "$minsdk" ] && args+=(--min-sdk-version "$minsdk")
            # 2>&1: apksigner schreibt unter neueren JDKs Warnungen nach stderr
            # ("restricted method … loadLibrary") und im Fehlerfall seine ERROR-Zeilen.
            # Beides soll sichtbar bleiben; gelesen wird zeilengenau, Warnungen stoeren
            # dabei nicht.
            "$werkzeug" "${args[@]}" "$ziel" 2>&1
            ;;
        *)
            cat "$ziel"
            ;;
    esac
}

pruefe_signaturschemata() {  # $1 = APK oder apksigner-Ausgabe
    local ziel="$1" bericht apksigner_fehler="" rc=0 fehlend="" schema digests

    # --min-sdk-version 21, und das ist KEIN Detail: apksigner verifiziert nur die
    # Schemata, die der angegebene SDK-Bereich BRAUCHT, und meldet alle anderen als
    # `false` — auch wenn sie im APK stehen. Gemessen am 2026-09-22 an einem APK, das
    # nachweislich v1+v2+v3 traegt (META-INF/T.SF, T.RSA, MANIFEST.MF im Zip):
    #   --min-sdk-version 21 → v1 true  · v2 true  · v3 true
    #   --min-sdk-version 23 → v1 true  · v2 true  · v3 true
    #   --min-sdk-version 24 → v1 FALSE · v2 true  · v3 true
    #   --min-sdk-version 28 → v1 FALSE · v2 FALSE · v3 true
    #   ohne Angabe (33 aus dem Manifest) → v1 FALSE · v2 FALSE · v3 true
    # Ohne den Schalter haette dieser Riegel also JEDES kuenftige Release abgelehnt,
    # obwohl alle drei Schemata drin sind — ein Riegel, der die richtige Antwort gibt,
    # weil er die falsche Frage stellt. 21 ist der Wert, unter dem apksigner alle drei
    # wirklich prueft; die App selbst bleibt bei minSdk 33.
    bericht=$(signatur_bericht "$ziel" 21) || rc=$?

    if [ "$rc" -ne 0 ]; then
        # apksigner verweigert bei --min-sdk-version 21 die Verifikation GANZ, sobald
        # v1 fehlt ("DOES NOT VERIFY / ERROR: Missing META-INF/MANIFEST.MF") — dann gibt
        # es keine Schema-Zeilen zum Auswerten. Der Befund kommt deshalb aus einem
        # zweiten Lauf ohne den Schalter: dort zaehlt apksigner die Schemata einzeln
        # auf, und genau das ist die Ausgabe, die den Anlass dieser Aenderung belegt.
        apksigner_fehler="$bericht"
        bericht=$(signatur_bericht "$ziel") || true
    fi

    # Erst ueberhaupt ein lesbarer Bericht? Ein leerer oder fremder Text darf nicht als
    # "alle Schemata fehlen" durchgehen, sondern als "nicht verifizierbar" — sonst liest
    # sich ein kaputter Aufruf wie ein Befund. Fail-closed in beiden Faellen, aber mit
    # unterschiedlicher Ursache.
    if ! printf '%s\n' "$bericht" | grep -qx 'Verifies'; then
        echo "❌ Kein lesbarer apksigner-Bericht aus ${ziel} — nicht verifizierbar." >&2
        echo "   Erwartet wird die Ausgabe von 'apksigner verify --verbose --print-certs'." >&2
        if [ -n "$apksigner_fehler" ]; then
            printf '%s\n' "$apksigner_fehler" | grep -vE '^WARNING: ' | sed 's/^/   /' >&2
        fi
        return 1
    fi

    # Zeilengenau (grep -qxF), nicht als Teilstring: 'v3' kaeme sonst auch in
    # 'Verified using v3.1 scheme' und 'v3.2' vor, und die stehen in jedem Bericht.
    # v4 wird bewusst NICHT verlangt — das ist eine separate .idsig-Datei neben dem
    # APK, nur fuer ADB-Incremental-Installs; weder GitHub-Release noch zapstore
    # transportieren sie.
    grep -qxF 'Verified using v1 scheme (JAR signing): true' <<<"$bericht" || fehlend="$fehlend v1"
    grep -qxF 'Verified using v2 scheme (APK Signature Scheme v2): true' <<<"$bericht" || fehlend="$fehlend v2"
    grep -qxF 'Verified using v3 scheme (APK Signature Scheme v3): true' <<<"$bericht" || fehlend="$fehlend v3"

    if [ -n "$fehlend" ]; then
        echo "❌ Dem APK fehlen Signaturschemata:${fehlend}" >&2
        for schema in v1 v2 v3; do
            printf '%s\n' "$bericht" | grep -E "^Verified using ${schema} scheme " | sed 's/^/   /' >&2
        done
        if [ -n "$apksigner_fehler" ]; then
            printf '%s\n' "$apksigner_fehler" | grep -vE '^WARNING: ' | sed 's/^/   /' >&2
        fi
        echo "   Erwartet werden v1 (JAR), v2 und v3 — alle drei aus DEMSELBEN Keystore." >&2
        echo "   Ohne die enableV*Signing-Flags signiert AGP bei minSdk 33 nur mit v2." >&2
        echo "   Pruefen mit: bash scripts/apply-vendor-patches.sh, dann neu bauen." >&2
        return 1
    fi

    # Die Zertifikats-Pruefung ist KEIN Beiwerk zur Schema-Pruefung, sondern ihr
    # Gegengewicht: drei Schemata aus einem anderen Keystore waeren schlimmer als ein
    # Schema aus dem richtigen. Gesammelt werden ALLE Zertifikats-Digests des Berichts,
    # praefix-unabhaengig: apksigner benennt den Signierer je nach verifiziertem Schema
    # ('V2 Signer: …' im v1.12.1-Bericht, 'V3.0 Signer: …' im Gegenversuch mit allen
    # drei Schemata, 'Signer #1 …' bei reinem v1). Jeder einzelne muss stimmen.
    digests=$(printf '%s\n' "$bericht" | grep -oE 'certificate SHA-256 digest: [0-9a-f]{64}' | awk '{ print $NF }' | sort -u)
    if [ -z "$digests" ]; then
        echo "❌ Kein Zertifikats-SHA-256 im apksigner-Bericht — nicht verifizierbar." >&2
        echo "   (Lief apksigner ohne --print-certs?)" >&2
        return 1
    fi
    if [ "$digests" != "$APK_ZERT_SHA256" ]; then
        echo "❌ Das APK traegt ein ANDERES Signatur-Zertifikat." >&2
        echo "   erwartet:  ${APK_ZERT_SHA256}" >&2
        echo "   im APK:    $(printf '%s\n' "$digests" | tr '\n' ' ')" >&2
        echo "   Das ist ein Ausschlusskriterium, kein Detail: Android bindet jede" >&2
        echo "   bestehende Installation an das Zertifikat. Ein Wechsel heisst kein" >&2
        echo "   Update mehr fuer Bestandsnutzer, und der veroeffentlichte" >&2
        echo "   apk_certificate_hash sowie der NIP-C1-Nachweis werden ungueltig." >&2
        echo "   Falscher Keystore in .env (ANDROID_KEYSTORE_*)?" >&2
        return 1
    fi

    echo "   ✓ Signaturschemata: v1, v2, v3 (Zertifikat ${APK_ZERT_SHA256:0:16}…)"
    return 0
}

# ── JDK-Wahl: Gradle braucht hier eine Java-Version zwischen 17 und 24 ────────
#
# Bis zum 2026-09-01 stand hier fest der JetBrains-Runtime aus der Android-Studio-
# Installation. Gemessen an diesem Tag ist er UNBRAUCHBAR: mit
# JAVA_HOME=<JBR 25.0.2> stirbt `./gradlew help` nach 0,6 s an
#   java.lang.IllegalArgumentException: 25.0.2
#   in org.jetbrains.kotlin.com.intellij.util.lang.JavaVersion.parse
# Das ist die in kotlin 2.0.0 mitgelieferte IntelliJ-Hilfsklasse; sie kennt kein
# zweistelliges Feature-Release ab 25. Reproduziert auf dem 4.3.1-Projekt
# (Gradle 8.14.5) UND auf einem frischen 3.3.7-Projekt (Gradle 8.13) — die
# Ursache sind die in beiden identischen Pins agp = 8.13.2 / kotlin = 2.0.0,
# nicht der NativePHP-Umstieg.
#
# Dass bisher trotzdem gebaut wurde, lag daran, dass Gradle sich fuer die
# COMPILE-Tasks sein eigenes Toolchain-JDK zieht
# (~/.gradle/jdks/eclipse_adoptium-21-amd64-linux.2, javaVersion 21). Das
# rettet den Launcher aber nicht: die Exception faellt bereits in der
# Konfigurationsphase, also in der JVM, die aus JAVA_HOME kommt. Eine
# Toolchain-Loesung scheidet damit aus — sie kaeme zu spaet und muesste
# ausserdem im generierten Projekt stehen, das native:install jederzeit neu
# schreibt. Deshalb wird hier ein passendes JDK GESUCHT und gepinnt.
#
# Reihenfolge der Kandidaten: ein vom Aufrufer gesetztes JAVA_HOME zuerst (es
# wird trotzdem geprueft, ein falsches gewinnt nichts), dann Gradles eigenes
# Toolchain-Verzeichnis — ueber das jeder bisherige Build real lief —, dann die
# systemweiten JDKs, und der JBR ganz zuletzt und nur, wenn er die Grenze haelt.
#
# Findet sich keines, bricht der Lauf LAUT ab. Ein stiller Rueckfall auf ein JDK,
# das nicht bauen kann, ist genau der Fehlermodus, der hier behoben wird.
#
# JDK_SUCHPFADE (durch ':' getrennt) ersetzt die Suchorte — nur fuer die
# Kontrolle in tests/Feature/ReleaseJdkGuardTest.php, damit der Negativfall
# messbar ist, ohne die JDKs dieser Maschine anzufassen.
JDK_MIN=17
JDK_MAX=24

jdk_hauptversion() {  # $1 = JDK-Wurzel; Hauptversion nach stdout, leer = unbrauchbar
    local d="$1" v=""
    # javac, nicht java: ein reines JRE bringt Gradle nicht durch die Konfiguration.
    [ -x "$d/bin/javac" ] || return 0
    if [ -r "$d/release" ]; then
        v=$(grep -oP '^JAVA_VERSION="\K[0-9]+' "$d/release" 2>/dev/null || true)
    fi
    if [ -z "$v" ]; then
        v=$("$d/bin/java" -version 2>&1 | grep -oP 'version "\K[0-9]+' | head -n1 || true)
    fi
    printf '%s' "$v"
}

finde_jdk() {  # schreibt "<pfad>\t<version>" nach stdout, 1 = keines passend
    local kandidaten=() d v
    if [ -n "${JDK_SUCHPFADE:-}" ]; then
        IFS=':' read -r -a kandidaten <<<"$JDK_SUCHPFADE"
    else
        [ -n "${JAVA_HOME:-}" ] && kandidaten+=("$JAVA_HOME")
        for d in "$HOME"/.gradle/jdks/*/; do kandidaten+=("${d%/}"); done
        for d in /usr/lib/jvm/*/; do kandidaten+=("${d%/}"); done
        kandidaten+=("$HOME/.local/share/JetBrains/Toolbox/apps/android-studio/jbr")
    fi
    for d in "${kandidaten[@]}"; do
        [ -n "$d" ] && [ -d "$d" ] || continue
        v=$(jdk_hauptversion "$d")
        [ -n "$v" ] || continue
        if [ "$v" -ge "$JDK_MIN" ] && [ "$v" -le "$JDK_MAX" ]; then
            printf '%s\t%s' "$d" "$v"
            return 0
        fi
    done
    return 1
}

waehle_jdk() {  # setzt JAVA_HOME/PATH oder bricht laut ab
    local treffer
    if ! treffer=$(finde_jdk); then
        echo "❌ Kein JDK zwischen $JDK_MIN und $JDK_MAX gefunden — Gradle kann nicht bauen." >&2
        echo "   Ab Java 25 stirbt die Konfigurationsphase an" >&2
        echo "   'IllegalArgumentException: <version>' in JavaVersion.parse (kotlin 2.0.0)." >&2
        echo "   Gesucht in: \$JAVA_HOME, \$HOME/.gradle/jdks/*, /usr/lib/jvm/*," >&2
        echo "   Android-Studio-JBR." >&2
        echo "   Der Lauf bricht ab, statt auf ein zu neues JDK zurueckzufallen: ein" >&2
        echo "   Build, der erst nach 20 Minuten an der Java-Version stirbt, kostet" >&2
        echo "   mehr als dieser Abbruch." >&2
        echo "   Abhilfe: ein Temurin 21 installieren, z. B. nach \$HOME/.gradle/jdks/." >&2
        return 1
    fi
    export JAVA_HOME="${treffer%%$'\t'*}"
    export PATH="$JAVA_HOME/bin:$PATH"
    echo "→ JDK ${treffer##*$'\t'}: $JAVA_HOME"
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

# Ebenso einzeln aufrufbar: nur den Signatur-Riegel fahren.
if [ "${1:-}" = "--pruefe-signatur" ]; then
    if [ -z "${2:-}" ]; then
        echo "❌ Aufruf: $0 --pruefe-signatur <apk-oder-apksigner-ausgabe>" >&2
        exit 2
    fi
    if pruefe_signaturschemata "$2"; then exit 0; else exit 1; fi
fi

# Ebenso einzeln aufrufbar: nur die JDK-Wahl fahren und melden.
if [ "${1:-}" = "--pruefe-jdk" ]; then
    if waehle_jdk; then exit 0; else exit 1; fi
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

waehle_jdk

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

echo "→ Signaturschemata im APK pruefen …"
# VOR Manifest und GPG-Signatur: eine Pruefsumme ueber ein APK, das die falschen
# Schemata oder das falsche Zertifikat traegt, ist eine Zusicherung auf ein
# unbrauchbares Artefakt — und die GPG-Signatur macht sie glaubwuerdig.
if ! pruefe_signaturschemata "${DIST}/${APK_NAME}"; then
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
