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
# Nutzung (aus dem Mobile-App-Repo):
#   scripts/run-browser.sh                  # alle Smoke-Routen
#   scripts/run-browser.sh --filter=meetups # Argumente gehen an pest
set -euo pipefail

# Chromium für Playwright sicherstellen (idempotent, schneller No-op wenn da).
if ! npx playwright install chromium >/dev/null 2>&1; then
    echo "✗ Konnte Chromium für Playwright nicht installieren." >&2
    echo "  Prüfe Node/Playwright: yarn install && npx playwright install chromium" >&2
    exit 1
fi

exec php vendor/bin/pest -c phpunit.browser.xml "$@"
