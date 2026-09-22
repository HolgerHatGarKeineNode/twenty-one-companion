<?php

declare(strict_types=1);

use Tests\Browser\Support\Sichtung;

/**
 * Positive controls for the four measurement channels of the v1.13.0 sighting (DoD item
 * 4). Without them, "no errors found" is a measurement that never ran, and that looks
 * exactly like a passing one — every channel here gets a known defect injected and must
 * report it before `SichtungTest.php` may trust its silence on a real route.
 */
test('the console channel catches console.error AND console.warn', function () {
    $webpage = seite('/start');
    Sichtung::instrument($webpage);

    $webpage->script("console.error('SIGHTING-CONTROL-ERROR'); console.warn('SIGHTING-CONTROL-WARN');");

    $messages = collect(Sichtung::measure($webpage)['consoleErrors'])->pluck('nachricht');

    expect($messages->contains('SIGHTING-CONTROL-ERROR'))
        ->toBeTrue('console.error was injected but not found in the recording — the console channel is blind.');
    expect($messages->contains('SIGHTING-CONTROL-WARN'))
        ->toBeTrue('console.warn was injected but not found in the recording — the console channel is blind.');
})->group('a11y', 'sichtung');

test('the pageerror channel catches thrown exceptions AND unhandled promise rejections', function () {
    $webpage = seite('/start');
    Sichtung::instrument($webpage);

    // Synchronous: a synthetic ErrorEvent on window triggers the same capture-phase
    // listener as a real, uncaught exception — deterministic, no waiting needed.
    $webpage->script("window.dispatchEvent(new ErrorEvent('error', { message: 'SIGHTING-CONTROL-THROW' }));");

    // The rejection is ALSO triggered synthetically (a PromiseRejectionEvent carrying an
    // already-caught promise as its payload), not through a genuinely unhandled rejection:
    // a real one propagates in this Playwright bridge as a runtime exception into the
    // NEXT protocol call (reproduced 2026-09-22 — the following evaluate()/waitForFunction()
    // failed with exactly this message instead of checking the assertion). The listener
    // itself only reads `e.reason`, it does not care whether the promise genuinely hangs —
    // so the control stays synchronous and deterministic.
    $webpage->script(<<<'JS'
        window.dispatchEvent(new PromiseRejectionEvent('unhandledrejection', {
            promise: Promise.reject().catch(() => {}),
            reason: new Error('SIGHTING-CONTROL-REJECTION'),
        }));
        JS);

    $messages = collect(Sichtung::measure($webpage)['pageErrors'])->pluck('nachricht');

    expect($messages->contains('SIGHTING-CONTROL-THROW'))
        ->toBeTrue('A synthetic window error was not reported — the pageerror channel is blind to thrown exceptions.');
    expect($messages->contains(fn (string $n): bool => str_contains($n, 'SIGHTING-CONTROL-REJECTION')))
        ->toBeTrue('A real unhandled promise rejection was not reported — the pageerror channel is blind to rejections.');
})->group('a11y', 'sichtung');

test('the network channel catches fetch responses at 400 and above', function () {
    $webpage = seite('/start');
    Sichtung::instrument($webpage);

    $webpage->script("fetch('/sighting-control-nonexistent-path');");
    $webpage->page()->waitForFunction(
        '() => (window.__sichtung.netzwerkFehler || []).length > 0'
    );

    $hit = collect(Sichtung::measure($webpage)['netzwerkFehler'])
        ->first(fn (array $f): bool => str_contains($f['url'], 'sighting-control-nonexistent-path'));

    expect($hit)
        ->not->toBeNull('A guaranteed-nonexistent path was requested via fetch(), but no hit shows up in the recording — the network channel is blind.');
    expect($hit['status'])->toBe(404);
})->group('a11y', 'sichtung');

test('the overflow channel measures actual horizontal overflow', function () {
    $webpage = seite('/start');

    $webpage->script(<<<'JS'
        (() => {
            const d = document.createElement('div');
            d.id = 'sighting-control-overflow';
            d.style.cssText = 'position:absolute;left:0;top:0;width:9999px;height:10px';
            document.body.appendChild(d);
        })();
        JS);

    $result = Sichtung::measureOverflow($webpage);

    expect($result['ueberlauf'])
        ->toBeTrue('A 9999px-wide injected element was not detected as overflow — the overflow channel is blind.');
    expect($result['ueberlaufPx'])->toBeGreaterThan(0);
    expect(collect($result['ueberstehend'])->pluck('selektor')->contains(fn (string $s): bool => str_contains($s, 'sighting-control-overflow')))
        ->toBeTrue('The overflow was counted, but the overflowing element itself was not identified — the finding would not be locatable.');
})->group('a11y', 'sichtung');
