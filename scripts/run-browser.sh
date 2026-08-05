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
#
# Offen (2026-08-06): Dieses Skript ist der EINZIGE Einstieg — anders als die
# Integration-Suite (`composer test:integration`) hat der Browser-Pfad kein
# eigenes composer-Script. Wer nur composer.json liest, findet ihn nicht.
# Ein `test:browser` zu ergänzen wäre der naheliegende Schritt.
#
# Ebenfalls offen: Die Chromium-Anbindung baut hier auf `npx playwright
# install`. Ein Nachbau über PLAYWRIGHT_BROWSERS_PATH-Symlinks auf ein
# Host-Chromium schlug am 2026-08-06 in einer Prüfung mit
# PlaywrightOutdatedException fehl (Revisions-Mismatch). Wer den Pfad braucht:
# dieses Skript nehmen, nicht selbst verdrahten.
set -euo pipefail

# Chromium für Playwright sicherstellen (idempotent, schneller No-op wenn da).
if ! npx playwright install chromium >/dev/null 2>&1; then
    echo "✗ Konnte Chromium für Playwright nicht installieren." >&2
    echo "  Prüfe Node/Playwright: yarn install && npx playwright install chromium" >&2
    exit 1
fi

exec php vendor/bin/pest -c phpunit.browser.xml "$@"
