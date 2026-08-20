#!/usr/bin/env bash
#
# Prüft die Textarbeit des Push-Workers auf der JVM — den Text, den ein Nutzer
# in der Statusleiste liest (`RelayPollWorker.readableBody`).
#
# WARUM EIN EIGENES SKRIPT: Der Kotlin-Code des Plugins liegt in
# `packages/push/resources/android/` und wird von NativePHP erst beim Build in
# das GENERIERTE Projekt unter `nativephp/` kopiert — und das ist komplett
# gitignoriert. Ein Test, der dort abgelegt wird, ist beim nächsten
# `native:install` weg. Also liegt er unter `packages/push/tests/`, und dieses
# Skript stellt die Verbindung her: Quellen + Test hineinkopieren, Gradle
# darauf ansetzen.
#
# Geprüft wird NUR reine Textarbeit (Regex, String). Socket, NIP-42, Amber und
# WorkManager bleiben weiterhin nur am Gerät prüfbar
# (`adb logcat -s PushPoll`, Debug-Route `debug/push-poll`).
#
# Voraussetzung: das Android-Projekt wurde schon einmal erzeugt
#   php artisan native:install
#
# Nutzung:
#   composer test:push-kotlin
#   scripts/run-push-kotlin.sh          # identisch
set -euo pipefail

WURZEL="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PROJEKT="${WURZEL}/nativephp/android"
PAKET="app/src/main/java/com/einundzwanzig/push"
TESTPAKET="app/src/test/java/com/einundzwanzig/push"

if [[ ! -x "${PROJEKT}/gradlew" ]]; then
    echo "✗ Android-Projekt fehlt (${PROJEKT})." >&2
    echo "  Einmalig erzeugen mit: php artisan native:install" >&2
    exit 1
fi

mkdir -p "${PROJEKT}/${PAKET}" "${PROJEKT}/${TESTPAKET}"

# Die Quellen frisch hineinkopieren, damit der Test gegen den Stand im Repo
# läuft und nicht gegen den des letzten Builds.
cp "${WURZEL}"/packages/push/resources/android/*.kt "${PROJEKT}/${PAKET}/"
cp "${WURZEL}"/packages/push/tests/*.kt "${PROJEKT}/${TESTPAKET}/"

cd "${PROJEKT}"

# --offline: die Abhängigkeiten liegen nach dem ersten Build im Gradle-Cache,
# und ein Prüflauf soll nicht am Netz hängen.
./gradlew :app:testDebugUnitTest --offline \
    --tests 'com.einundzwanzig.push.*' "$@"
