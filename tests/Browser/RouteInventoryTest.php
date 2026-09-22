<?php

declare(strict_types=1);

use Tests\Browser\Support\RouteInventory;

/**
 * Fail-closed guard for the v1.13.0 sighting (`SichtungTest.php`): the sighting can only
 * see every view if its route list cannot silently age. Exactly this happened once already
 * — `SmokeTest.php` still named ten routes that had become nine bare 301 redirects, and
 * nobody noticed until this survey was commissioned.
 *
 * `RouteInventory::check()` takes its inputs as parameters rather than reading the live
 * route table itself, so the mutation probe below can starve it of one entry and watch the
 * diagnosis change — no route is ever actually removed from the app or the inventory file.
 */
test('every live GET route is either a sighted page or a named, justified exception', function () {
    $result = RouteInventory::check(RouteInventory::liveGetUriPatterns());

    expect($result['missingFromSurvey'])->toBe([], 'Routes the app answers but that are in neither RouteInventory::pages() nor ::exceptions() — exactly the aging failure this guard exists to catch: '.implode(', ', $result['missingFromSurvey']));
    expect($result['orphanedInSurvey'])->toBe([], 'Inventory entries for which no live route exists anymore (route removed/renamed, inventory not updated): '.implode(', ', $result['orphanedInSurvey']));
})->group('a11y', 'sichtung');

/**
 * The aging case itself, reproduced: a live route missing from the inventory MUST break
 * the guard. Without this test, `check()` could silently always return `[]` because of a
 * typo (e.g. an empty `array_diff`).
 */
test('the guard fails when a live route is missing from the inventory', function () {
    $liveWithExtraRoute = array_merge(RouteInventory::liveGetUriPatterns(), ['brandnew/{id}']);

    $result = RouteInventory::check($liveWithExtraRoute);

    expect($result['missingFromSurvey'])->toBe(['brandnew/{id}']);
})->group('a11y', 'sichtung');

/**
 * The reverse direction: an inventory entry with no live route (route removed/renamed,
 * inventory forgotten) must be flagged too — otherwise the list only ever grows and
 * nobody notices when a path has disappeared.
 */
test('the guard fails when the inventory names a route that no longer exists', function () {
    $pagesWithGhostRoute = RouteInventory::pages();
    $pagesWithGhostRoute['long/since/removed'] = ['path' => '/long/since/removed', 'gated' => false];

    $result = RouteInventory::check(RouteInventory::liveGetUriPatterns(), $pagesWithGhostRoute);

    expect($result['orphanedInSurvey'])->toBe(['long/since/removed']);
})->group('a11y', 'sichtung');
