<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/**
 * Regressions from the E2E emulator report (plans/REPORT.md), mobile side. The authGate
 * store (app.js) and the shared package fixes are checked by the main repository resp. by
 * Playwright; here: the mobile fixes a Blade/i18n/branding test can decide.
 *
 * P2 moved two of them to a different surface — the "Mehr" hub they were measured on is gone
 * (Concept C) — but not one of them lost its demand. The comments at those cases say which
 * surface took over and why the demand still holds.
 */
afterEach(fn () => app()->setLocale('de'));

test('🔴 list-link-card mit navigate=false rendert einen harten Link (kein wire:navigate)', function () {
    // Cross-Bundle-Links (ins Chat-group.js) müssen hart laden, sonst bootet
    // group.js nicht via alpine:init (wire:navigate trägt den <head> mit).
    $hard = Blade::render('<x-list-link-card href="/x" :navigate="false">y</x-list-link-card>');
    expect($hard)->toContain('href="/x"')->and($hard)->not->toContain('wire:navigate');

    // Default bleibt SPA (wire:navigate) für In-Bundle-Navigation.
    $spa = Blade::render('<x-list-link-card href="/x">y</x-list-link-card>');
    expect($spa)->toContain('wire:navigate');
});

test('🔴 the sign-in card leads to /nostr-login as a HARD load', function () {
    // Cross-bundle: the login view lives in the chat bundle, so the anchor must not
    // SPA-navigate — `group.js` boots on `alpine:init`, and `wire:navigate` carries the old
    // `<head>` along.
    //
    // Until P2 the anchor stood on the "Mehr" hub, which is gone. It stands on „Ich" now,
    // where the identity lives, and the demand is the same one.
    withoutPortalToken();

    $html = (string) $this->get(route('group.ich'))->assertOk()->getContent();

    // A guest sees the invitation, and signing in runs through the auth-gate store — which
    // ends in `location.assign('/nostr-login')`, i.e. a hard load by construction.
    expect($html)->toContain('$store.authGate.requireAuth');

    // CONTROL: the page really rendered the guest branch and not an empty shell.
    expect($html)->toContain(__('Noch nicht angemeldet'));
});

test('🟠 the Postfach nav label is translated at render time (en → Inbox)', function () {
    // The bug this case was written for: nav labels came from `config('group.nav')`, which
    // loads BEFORE the locale middleware, so a `__()` in the config always resolved to the
    // default language („Mehr" instead of „More"). The registry is gone; the labels are now
    // `__()` calls in the bar's own markup. The demand survives the rebuild — only the
    // measured label changed, because the tabs did.
    withoutPortalToken();
    completeOnboarding(locale: 'en');

    // Precondition: the key exists (locale set explicitly here — the middleware sets it in
    // the HTTP request, not in the test body).
    app()->setLocale('en');
    expect(__('Postfach'))->toBe('Inbox');

    $html = (string) $this->get(route('meetups'))->assertOk()->getContent();

    // ONLY the bottom-nav region — a bare `assertSee('Inbox')` would also match a page title
    // and mask the very fix this case is about. Anchored on `data-bottom-nav` and not on the
    // `aria-label`: the package renders the label through `__()` on a multi-line tag, so a
    // `<nav\s+aria-label=…>` pattern finds nothing and the case would fail for the wrong
    // reason (measured 2026-09-18).
    $nav = mb_strstr($html, 'data-bottom-nav');
    expect($nav)->not->toBeFalse('bottom nav not found');
    $nav = (string) mb_strstr((string) $nav, '</nav>', true);

    expect($nav)->toContain('Inbox')
        ->and($nav)->not->toContain('>Postfach<');
});

test('🟠 en.json trägt keine „TWENTY ONE"-Marke mehr (EINUNDZWANZIG im UI)', function () {
    $en = json_decode((string) file_get_contents(base_path('lang/en.json')), true);

    foreach ($en as $key => $value) {
        expect($value)->not->toContain('TWENTY ONE', "en.json-Wert für Key {$key} enthält noch TWENTY ONE");
    }
});
