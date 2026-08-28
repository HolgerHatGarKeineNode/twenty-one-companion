#!/usr/bin/env bash
#
# Bringt alle Abhängigkeiten auf Stand und prüft sie — der Schritt VOR einem Release.
#
# Bewusst NICHT Teil von release.sh: ein Release soll den getesteten Stand
# ausliefern. Wer Update und Build in einen Lauf packt und dann ein rotes
# Ergebnis bekommt, weiß nicht, ob der eigene Code oder eine Fremdversion schuld
# ist — bei ~40 geänderten Paketen ist das teuer zu halbieren. Deshalb: erst
# hier updaten, prüfen und committen; danach erst versionieren und bauen.
#
# Verwendung:
#   ./scripts/pre-release-update.sh
#   SKIP_TESTS=1 ./scripts/pre-release-update.sh   # nur aktualisieren
#
# Danach:
#   php artisan native:release patch && ./scripts/release.sh
set -euo pipefail

cd "$(dirname "$0")/.."

echo "═══ 1/5  Composer aktualisieren ═══"
# Nur innerhalb der Constraints. Major-Sprünge bleiben gesperrt und werden am
# Ende gemeldet — sie gehören in einen eigenen Vorgang, nicht in eine Routine.
composer update --no-interaction

echo
echo "═══ 2/5  npm aktualisieren ═══"
# Dieses Projekt nutzt npm, NICHT yarn: getrackt ist package-lock.json, ein
# yarn.lock existiert nicht. `yarn install` würde den Lock ignorieren und die
# Versionen neu auflösen — also andere Pakete installieren als gelockt, auch in
# den Assets, die ins APK wandern. yarn.lock steht deshalb in .gitignore.
#
# Die Registry dieser Umgebung liefert nur Pakete, die einige Tage alt sind — bewusster
# Schutz gegen Supply-Chain-Angriffe: ein kompromittiertes Release soll nicht sofort in
# einen Build wandern. Bleibt unten in `npm outdated` etwas stehen, ist das KEIN
# unerledigter Rest — die Version ist nur noch nicht freigegeben. Meldet npm
# `notarget ... with a date before <datum>`, gilt dasselbe: warten, nicht ausweichen.
# Nicht umgehen — der Cutoff sitzt im Registry-Proxy, nicht in `npm config`.
npm update

echo
echo "═══ 3/5  Vendor-Patches erneut anwenden ═══"
# Idempotent und fail-fast: hat ein Update eine gepatchte Stelle verschoben,
# bricht das Skript hier ab — und nicht still im fertigen APK.
bash scripts/apply-vendor-patches.sh

echo
echo "═══ 4/5  Frontend-Assets bauen ═══"
# Gleicher Aufruf wie im Release. `--mode=android` ist nicht kosmetisch:
# das NativePHP-Vite-Plugin liest process.argv danach und schaltet den
# Hot-File-Pfad auf public/android-hot.
npm run build -- --mode=android

echo
if [ -n "${SKIP_TESTS:-}" ]; then
    echo "═══ 5/5  Tests übersprungen (SKIP_TESTS gesetzt) ═══"
else
    echo "═══ 5/5  Testtore ═══"
    composer test
    composer test:browser
    composer test:push-kotlin
    # test:integration bleibt bewusst draußen: die Fälle überspringen sich
    # selbst, solange das Portal nicht erreichbar ist und PORTAL_TEST_TOKEN
    # fehlt. Ein grüner Lauf dort ist kein Beleg — separat und bewusst fahren.
fi

echo
echo "═══ Was NICHT automatisch aktualisiert wurde ═══"
echo "Diese Pakete liegen außerhalb ihrer Constraints. Ein Major-Sprung ist eine"
echo "Migration, kein Update — eigener Vorgang, eigener Test, eigener Commit:"
echo
composer outdated --direct --no-interaction 2>/dev/null | grep -vE '^(Color|Legend|! )' || true
npm outdated 2>/dev/null || true

echo
echo "✅ Abhängigkeiten aktuell und geprüft."
echo
echo "Nächste Schritte:"
echo "  1. composer.lock + package-lock.json committen (eigener Commit)"
echo "  2. php artisan native:release patch|minor|major"
echo "  3. ./scripts/release.sh"
