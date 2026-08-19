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
# Zur Chromium-Anbindung: es wird NICHTS heruntergeladen. `link-host-chromium.sh`
# legt eine Symlink-Registry unter ~/.cache/ms-playwright an, die auf das Chromium
# dieses Rechners zeigt (/usr/bin/chromium).
#
# Hier stand das Gegenteil ("`npx playwright install` ist der einzige unterstuetzte
# Weg, Symlinks schlugen am 2026-08-06 mit PlaywrightOutdatedException fehl"). Das
# galt fuer jenen Versuch, nicht fuer diesen: der Fehler war ein Revisions-Mismatch,
# und genau den vermeidet dieses Skript, indem es die Revision aus
# node_modules/playwright-core/browsers.json LIEST statt sie festzuschreiben. Am
# 2026-08-19 gegengeprueft — die heruntergeladene Revision 1228 geloescht, nur die
# Symlinks gelegt, Browser-Suite 6 gruen.
#
# Der Anlass fuer den Wechsel war ein echter Ausfall: ~/.cache/ms-playwright ist
# zwischen den Repos geteilt, die Playwright-Versionen sind es nicht (hier 1.61.1,
# fair-btc 1.62.1, mim-pulse-flux ^1.59.1). Ein `playwright install` raeumt fremde
# Revisionen weg — nach einem Lauf hier war fair-btcs GESAMTE Suite rot. Was nie
# heruntergeladen wird, kann auch niemand wegraeumen.
set -euo pipefail

# Analog zu den uebrigen Test-Scripts in composer.json: ein gecachter Config-
# Stand wuerde die Testumgebung verfaelschen.
php artisan config:clear --ansi

# Host-Chromium verdrahten (idempotent, legt nur Symlinks).
"$(dirname "$0")/link-host-chromium.sh"

exec php vendor/bin/pest -c phpunit.browser.xml "$@"
