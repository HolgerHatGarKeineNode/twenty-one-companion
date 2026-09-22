<?php

declare(strict_types=1);

use Illuminate\Support\Str;
use Tests\Browser\Support\PortalFixtures;
use Tests\Browser\Support\RouteInventory;
use Tests\Browser\Support\Sichtung;

/**
 * The read-only sighting ahead of v1.13.0 (see assignment) — visits EVERY view from
 * `RouteInventory::pages()` in three passes (narrow/dark, desktop/dark, narrow/light),
 * measures HTTP status, browser console, thrown exceptions, network responses ≥ 400 and
 * horizontal overflow, and writes every finding to
 * `storage/app/sichtung-v1.13.0/befunde.json` plus one screenshot per case.
 *
 * Deliberately READ-ONLY: no click on submit/save/create/delete, no POST/PUT/DELETE —
 * only `visit()`, `script()`, measuring, screenshot.
 */
const SIGHTING_PASSES = [
    'a-schmal-dunkel' => ['breite' => 320, 'hell' => false],
    'b-desktop-dunkel' => ['breite' => 1280, 'hell' => false],
    'c-schmal-hell' => ['breite' => 320, 'hell' => true],
];

/**
 * The four portal-HTTP surfaces for which a filled state is deterministically producible
 * (see `PortalFixtures`) — including the two tab variants the DoD names explicitly
 * (dates, lecturers). Not a separate route entry: query variants of the same route, not
 * new routes — the fail-closed guard still counts 56.
 *
 * @return array<string, string>
 */
function filledSightingRoutes(): array
{
    return [
        'bereich/meetups (filled)' => '/bereich/meetups',
        'bereich/meetups?ansicht=termine (filled)' => '/bereich/meetups?ansicht=termine',
        'bereich/meetups/{slug} (filled)' => '/bereich/meetups/'.RouteInventory::MEETUP_SLUG,
        'bereich/kurse (filled)' => '/bereich/kurse',
        'bereich/kurse?ansicht=referenten (filled)' => '/bereich/kurse?ansicht=referenten',
        'bereich/kurse/{id} (filled)' => '/bereich/kurse/'.RouteInventory::COURSE_ID,
        'bereich/kurse/referenten/{id} (filled)' => '/bereich/kurse/referenten/'.RouteInventory::LECTURER_ID,
    ];
}

/**
 * A single sighting case: HTTP status separately (Laravel test client, no browser —
 * `visit()` follows redirects transparently and would never show the FIRST status code),
 * then the browser pass with its own instrumentation, overflow measurement and
 * screenshot. Errors are NOT propagated (one broken case must not take the other 188
 * down with it) — they end up in the `fehler` field of the finding.
 *
 * @return array<string, mixed>
 */
function measureSightingCase(
    string $displayName,
    string $path,
    bool $gated,
    string $state,
    string $pass,
    int $width,
    bool $light,
    string $storageDir,
): array {
    if ($gated) {
        test()->withSession(['nostr_pubkey' => RouteInventory::PUBKEY]);
    }

    $finding = [
        'route' => $displayName,
        'pfad' => $path,
        'durchgang' => $pass,
        'zustand' => $state,
        'httpStatus' => null,
        'breiteAngefordert' => $width,
        'breiteGemessen' => null,
        'consoleErrors' => [],
        'pageErrors' => [],
        'netzwerkFehler' => [],
        'ueberlauf' => false,
        'ueberlaufPx' => 0,
        'ueberstehend' => [],
        'hauptHoehe' => null,
        'leer' => null,
        'screenshot' => null,
        'fehler' => null,
    ];

    try {
        $finding['httpStatus'] = test()->get($path)->getStatusCode();
    } catch (Throwable $e) {
        $finding['fehler'] = 'HTTP probe: '.$e::class.': '.mb_substr($e->getMessage(), 0, 200);
    }

    try {
        $webpage = $light ? visit($path)->inLightMode() : visit($path)->inDarkMode();
        // NOT ->on()->mobile(): the device profile brings its own viewport and beats a
        // later setViewportSize (the K4 trap, documented in TargetSizeTest.php — this
        // assertion here reproduces that same lesson).
        $webpage->page()->setViewportSize($width, 900);
        $webpage->page()->waitForLoadState('networkidle');

        Sichtung::instrument($webpage);

        $measured = Sichtung::measure($webpage);
        $overflow = Sichtung::measureOverflow($webpage);

        $fileName = 'sichtung-'.$pass.'-'.Str::slug($displayName);
        $webpage->page()->screenshot(true, $fileName);
        $source = base_path('tests/Browser/Screenshots/'.$fileName.'.png');
        $target = $storageDir.'/'.$pass.'/'.Str::slug($displayName).'.png';

        if (is_file($source)) {
            @mkdir(dirname($target), 0755, true);
            rename($source, $target);
            $finding['screenshot'] = $target;
        }

        $finding['breiteGemessen'] = $overflow['breite'];
        $finding['consoleErrors'] = $measured['consoleErrors'];
        $finding['pageErrors'] = $measured['pageErrors'];
        $finding['netzwerkFehler'] = $measured['netzwerkFehler'];
        $finding['ueberlauf'] = $overflow['ueberlauf'];
        $finding['ueberlaufPx'] = $overflow['ueberlaufPx'];
        $finding['ueberstehend'] = $overflow['ueberstehend'];
        $finding['hauptHoehe'] = $overflow['hauptHoehe'];
        $finding['leer'] = $overflow['leer'];
    } catch (Throwable $e) {
        $finding['fehler'] = ($finding['fehler'] !== null ? $finding['fehler'].' | ' : '').'Browser: '.$e::class.': '.mb_substr($e->getMessage(), 0, 300);
    }

    return $finding;
}

test('the nine legacy routes from the former SmokeTest each individually answer with 301', function () {
    foreach (RouteInventory::NINE_LEGACY_ROUTES as $path) {
        $response = test()->get($path);

        expect($response->getStatusCode())
            ->toBe(301, "{$path} answered with {$response->getStatusCode()} instead of 301 — assertRedirect() alone would have accepted any 3xx and missed this.");
    }
})->group('a11y', 'sichtung');

test('v1.13.0 sighting: every view, three passes, measured and backed by a screenshot', function () {
    $storageDir = storage_path('app/sichtung-v1.13.0');
    @mkdir($storageDir, 0755, true);

    $findings = [];

    foreach (SIGHTING_PASSES as $pass => $config) {
        foreach (RouteInventory::pages() as $uri => $info) {
            $findings[] = measureSightingCase(
                $uri,
                $info['path'],
                $info['gated'],
                'leer',
                $pass,
                $config['breite'],
                $config['hell'],
                $storageDir,
            );
        }

        foreach (filledSightingRoutes() as $displayName => $path) {
            PortalFixtures::filled();
            $findings[] = measureSightingCase(
                $displayName,
                $path,
                false,
                'gefuellt',
                $pass,
                $config['breite'],
                $config['hell'],
                $storageDir,
            );
            PortalFixtures::cleanUp();
        }
    }

    file_put_contents(
        $storageDir.'/befunde.json',
        (string) json_encode($findings, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );

    // The width assertion from the `on()->mobile()` trap (see TargetSizeTest.php):
    // measured width MUST be the requested one, proven at least once per pass — otherwise
    // this run would have measured a different surface than it claims.
    foreach (SIGHTING_PASSES as $pass => $config) {
        $measuredWidths = collect($findings)
            ->where('durchgang', $pass)
            ->pluck('breiteGemessen')
            ->filter(fn (?int $b): bool => $b !== null)
            ->unique()
            ->values();

        expect($measuredWidths->all())
            ->toBe([$config['breite']], "Pass {$pass}: measured width(es) ".json_encode($measuredWidths->all())." instead of [{$config['breite']}] — the wrong surface was measured.");
    }

    // Every route×pass must actually be present in the file (DoD item 2).
    $expectedCases = count(RouteInventory::pages()) * count(SIGHTING_PASSES)
        + count(filledSightingRoutes()) * count(SIGHTING_PASSES);
    expect($findings)->toHaveCount($expectedCases);
})->group('a11y', 'sichtung');
