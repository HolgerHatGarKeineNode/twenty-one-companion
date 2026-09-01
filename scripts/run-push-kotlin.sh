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
# Quellwurzel des Plugin-Compilers, NICHT src/main/java. Ab NativePHP 4.x
# registriert app/build.gradle.kts:12-15 zusaetzlich `src/nativephp/kotlin` im
# main-SourceSet, und AndroidPluginCompiler schreibt die Plugin-Quellen dorthin
# (:60). Der alte Pfad ist in einem 4.x-Projekt nicht nur leer, er ist eine
# Falle: removeLegacyPackageCopies() (:700-731, Aufruf :252) raeumt genau dort
# nach Paket+Dateiname auf. Gemessen am 2026-09-01 (P1) sind es zwei
# Fehlerbilder, je nach Reihenfolge:
#   - dieses Skript NACH einem Plugin-Compile: dieselben drei Klassen liegen in
#     zwei registrierten Quellwurzeln -> Kotlin-`Redeclaration`;
#   - ein Compile NACH diesem Skript: die Kopien verschwinden still, und der
#     Lauf misst den Stand des letzten Builds statt den des Repos.
# Der Testquellordner unten ist davon nicht betroffen — der Aufraeumer fasst
# ausschliesslich src/main/java an.
PAKET="app/src/nativephp/kotlin/com/einundzwanzig/push"
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
