#!/usr/bin/env bash
#
# Every test category, one after the other, timed — and the budget as a check.
#
# The rule (user, 2026-10-23): the WHOLE suite — default, browser and the other groups
# together — runs in under 10 minutes. A budget nobody measures is not one; the sum is
# measured here, in one place, serially (two suites at once would load each other and
# invalidate both timings).
#
#   composer test:all                 # all categories, table at the end
#   TEST_BUDGET_SECONDS=300 composer test:all
#
# Categories (each is its own config, only the first runs by default):
#   default      phpunit.xml              php artisan test --parallel
#   browser      phpunit.browser.xml      scripts/run-browser.sh      (real Chromium)
#   integration  phpunit.integration.xml  a portal on THIS machine; skipped without one
#   kotlin       scripts/run-push-kotlin.sh  (needs `php artisan native:install` once)
#
# Exit code: 1 if a category failed or the budget is exceeded. A category that could not
# run at all (no Android project) is reported as NOT RUN and does not count as green.
set -uo pipefail

cd "$(dirname "${BASH_SOURCE[0]}")/.."

BUDGET="${TEST_BUDGET_SECONDS:-600}"

NAMES=()
CODES=()
SECONDS_TAKEN=()

run_category() { # $1 = name, rest = command
    local name="$1"
    shift
    local started
    started=$(date +%s)
    echo
    echo "━━ ${name}: $*"
    "$@"
    local code=$?
    NAMES+=("${name}")
    CODES+=("${code}")
    SECONDS_TAKEN+=("$(( $(date +%s) - started ))")
}

php artisan config:clear --ansi >/dev/null

run_category default php artisan test --parallel
run_category browser bash scripts/run-browser.sh
run_category integration php vendor/bin/pest -c phpunit.integration.xml

if [[ -x nativephp/android/gradlew ]]; then
    run_category kotlin bash scripts/run-push-kotlin.sh
else
    NAMES+=(kotlin)
    CODES+=(not-run)
    SECONDS_TAKEN+=(0)
fi

total=0
failed=0
echo
echo "━━ categories"
printf '%-13s %-9s %s\n' category result seconds
for i in "${!NAMES[@]}"; do
    code="${CODES[$i]}"
    if [[ "${code}" == "0" ]]; then
        result=ok
    elif [[ "${code}" == "not-run" ]]; then
        result="NOT RUN"
    else
        result="FAILED(${code})"
        failed=1
    fi
    printf '%-13s %-9s %s\n' "${NAMES[$i]}" "${result}" "${SECONDS_TAKEN[$i]}"
    total=$(( total + SECONDS_TAKEN[i] ))
done
printf '%-13s %-9s %s (budget %s)\n' total '' "${total}" "${BUDGET}"

if (( total > BUDGET )); then
    echo "✗ budget exceeded: ${total}s > ${BUDGET}s — optimise, merge or cut before adding tests." >&2
    exit 1
fi

exit "${failed}"
