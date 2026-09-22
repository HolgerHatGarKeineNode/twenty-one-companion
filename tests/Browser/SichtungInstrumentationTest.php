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

test('the pageerror channel tells an empty src attribute apart from an image that failed to load', function () {
    $webpage = seite('/start');
    Sichtung::instrument($webpage);

    // Known-bad: `src=""` resolves to the page URL and fires an error event. Known-good for
    // the empty-src latch: a real path that 404s also fires one, but carries its src.
    $webpage->script(<<<'JS'
        (() => {
            const empty = document.createElement('img');
            empty.setAttribute('src', '');
            empty.dataset.sightingControl = 'empty';
            document.body.appendChild(empty);
            const missing = document.createElement('img');
            missing.setAttribute('src', '/sighting-control-missing.png');
            missing.dataset.sightingControl = 'missing';
            document.body.appendChild(missing);
        })();
        JS);
    // Polled rather than `waitForFunction()`: the 404 image's error event arrives after a
    // network round trip, and a single wait returned before it (measured on this control).
    $controls = collect();
    for ($attempt = 0; $attempt < 50 && $controls->count() < 2; $attempt++) {
        usleep(100_000);
        $controls = collect(Sichtung::measure($webpage)['pageErrors'])
            ->filter(fn (array $e): bool => str_contains($e['html'] ?? '', 'data-sighting-control'));
    }
    $empty = $controls->first(fn (array $e): bool => str_contains($e['html'], 'data-sighting-control="empty"'));
    $missing = $controls->first(fn (array $e): bool => str_contains($e['html'], 'data-sighting-control="missing"'));

    expect($empty)->not->toBeNull('An injected <img src=""> raised no recorded error — the empty-src latch in SichtungTest would be blind.');
    expect($empty['element'])->toBe('img');
    expect($empty['srcAttribut'])->toBe('');
    expect($missing)->not->toBeNull('An injected <img> with a 404 path raised no recorded error.');
    expect($missing['srcAttribut'])->toBe('/sighting-control-missing.png');
})->group('a11y', 'sichtung');

test('the cross-origin channel records fetch, XHR, WebSocket and resource requests, and the production check sorts their hosts', function () {
    $webpage = seite('/start');
    Sichtung::instrument($webpage);

    // Two production hosts are named on purpose — the ones the SichtungTest latch must catch.
    // A pre-aborted signal and an XHR that is opened but never sent make sure no request
    // actually reaches them.
    $webpage->script(<<<'JS'
        (() => {
            fetch('https://portal.einundzwanzig.space/sighting-control-fetch', { signal: AbortSignal.abort() }).catch(() => {});
            new XMLHttpRequest().open('GET', 'https://group.einundzwanzig.space/sighting-control-xhr');
            fetch('http://127.0.0.1:9/sighting-control-dead-port', { signal: AbortSignal.abort() }).catch(() => {});
            fetch('/sighting-control-same-origin').catch(() => {});
            // WebSocket and resource doors, against hosts that are NOT production: a
            // production WebSocket or image would open a real connection there.
            new WebSocket('ws://127.0.0.1:7/sighting-control-websocket');
            const img = document.createElement('img');
            img.src = 'http://localhost:' + location.port + '/sighting-control-resource.png';
            document.body.appendChild(img);
        })();
        JS);

    // The resource entry is only reported once the image request has finished.
    for ($attempt = 0; $attempt < 50 && ! collect(Sichtung::measure($webpage)['fremdAnfragen'])->contains(fn (array $r): bool => str_contains($r['url'], 'sighting-control-resource')); $attempt++) {
        usleep(100_000);
    }

    $requests = collect(Sichtung::measure($webpage)['fremdAnfragen']);
    $hostOf = fn (string $marker): ?string => $requests->first(fn (array $r): bool => str_contains($r['url'], $marker))['host'] ?? null;

    expect($hostOf('sighting-control-fetch'))
        ->toBe('portal.einundzwanzig.space', 'A cross-origin fetch() was not recorded — the production latch in SichtungTest would be blind to fetch.');
    expect($hostOf('sighting-control-xhr'))
        ->toBe('group.einundzwanzig.space', 'A cross-origin XHR was not recorded — the production latch in SichtungTest would be blind to XHR.');
    expect($hostOf('sighting-control-same-origin'))
        ->toBeNull('A same-origin request was recorded as cross-origin — the channel does not tell origins apart.');

    expect($requests->first(fn (array $r): bool => str_contains($r['url'], 'sighting-control-websocket'))['art'] ?? null)
        ->toBe('websocket', 'A cross-origin WebSocket was not recorded — the production latch would be blind to relay connections.');
    expect($requests->first(fn (array $r): bool => str_contains($r['url'], 'sighting-control-resource'))['art'] ?? null)
        ->toBe('resource:img', 'A cross-origin <img> was not recorded — the production latch would be blind to resources.');

    expect(Sichtung::isProductionHost($hostOf('sighting-control-fetch')))->toBeTrue();
    expect(Sichtung::isProductionHost($hostOf('sighting-control-xhr')))->toBeTrue();
    expect(Sichtung::isProductionHost((string) $hostOf('sighting-control-dead-port')))
        ->toBeFalse('The dead test port was classified as production — the latch would fail on the configuration it relies on.');
    expect(Sichtung::isProductionHost('noteinundzwanzig.space'))
        ->toBeFalse('A look-alike domain was classified as production — the suffix check is not anchored at a dot.');
})->group('a11y', 'sichtung');
