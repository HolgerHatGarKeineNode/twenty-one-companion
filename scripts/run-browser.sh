#!/usr/bin/env bash
#
# Startet die opt-in Browser-Smoke-Suite (echter Chromium via
# pest-plugin-browser) über phpunit.browser.xml. Diese Suite läuft NICHT in der
# Standard-Testsuite mit, damit der schnelle Unit/Feature-Loop und CI ohne
# Playwright-Browser nicht brechen.
#
# Voraussetzungen:
#   - Node + die Dev-Dependency `playwright` (yarn install)
#   - Der Chromium-Build (wird bei Bedarf unten installiert)
#
# Nutzung:
#   composer test:browser                          # alle Smoke-Routen
#   composer test:browser -- --filter=meetups      # Argumente gehen an pest
#   scripts/run-browser.sh --filter=meetups        # direkt, gleichwertig
#
# Das composer-Script ruft ausschliesslich dieses Skript auf — bewusst als
# EINZIGES Kommando im Array. Composer haengt zusaetzliche Argumente
# (`composer test:browser -- --filter=x`) an JEDES Kommando des Arrays an,
# nicht nur an das erste. Stuende neben dem `bash`-Aufruf noch ein zweites
# Kommando, das den Schalter nicht kennt — etwa `artisan config:clear` —,
# braeche der Lauf ab, und zwar unabhaengig von der Reihenfolge. (Am
# 2026-08-06 genau so passiert; die Positionsabhaengigkeit war eine
# Fehldiagnose, per Positionstausch-Probe mit composer 2.10.2 widerlegt.)
# Deshalb macht dieses Skript den config:clear selbst.
#
# Zur Chromium-Anbindung: `npx playwright install` unten ist der einzige
# unterstuetzte Weg. Ein Nachbau ueber PLAYWRIGHT_BROWSERS_PATH-Symlinks auf
# ein Host-Chromium schlug am 2026-08-06 mit PlaywrightOutdatedException fehl
# (Revisions-Mismatch). Nicht selbst verdrahten.
set -euo pipefail

# Analog zu den uebrigen Test-Scripts in composer.json: ein gecachter Config-
# Stand wuerde die Testumgebung verfaelschen.
php artisan config:clear --ansi

# Chromium für Playwright sicherstellen (idempotent, schneller No-op wenn da).
if ! npx playwright install chromium >/dev/null 2>&1; then
    echo "✗ Konnte Chromium für Playwright nicht installieren." >&2
    echo "  Prüfe Node/Playwright: yarn install && npx playwright install chromium" >&2
    exit 1
fi

exec php vendor/bin/pest -c phpunit.browser.xml "$@"
