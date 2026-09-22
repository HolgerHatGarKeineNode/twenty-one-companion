<?php

declare(strict_types=1);

use App\Services\AppPreferences;

/**
 * The first run on the device: /onboarding (host layout) hands off to /start (package
 * layout). Device sighting v1.13.0 measured two defects on that seam:
 *
 *   - `Alpine.$data(nostrAuth).mobile` was `false` while `window.__nostrMobile` was
 *     `true`: the island bundle had evaluated on /onboarding, whose head did not set the
 *     flag, and `core.ts` froze `isMobile` from it. The login sheet offered the QR signer
 *     instead of Amber until the next full load.
 *   - every inline island script stood twice in the head of /start: Livewire's
 *     `wire:navigate` head merge executes a clone of each new script and then appends the
 *     parsed original as well.
 *
 * The host flag is simulated the way the device sets it — server-side, through
 * `nativephp-internal.running` (what `Chassis::istApp()` reads) — and NOT with an init
 * script: an init script would put the flag on /onboarding as well and hide the defect.
 */
function startOnboardingAtLastStepInAppMode(): object
{
    config(['nativephp-internal.running' => true]);
    resetOnboarding();
    app(AppPreferences::class)->setOnboardingStep(AppPreferences::STEP_DONE);
    app(AppPreferences::class)->markNotificationsAsked();

    $page = visit('/onboarding')->on()->mobile()->inDarkMode();
    $page->page()->waitForLoadState('networkidle');

    // Survives a `wire:navigate`, dies with a full load.
    $page->script('() => { window.__sameDocument = true }');

    return $page;
}

function waitForIsland(object $page): void
{
    for ($i = 0; $i < 50 && $page->script('() => location.pathname') === '/onboarding'; $i++) {
        usleep(200_000);
    }

    $page->page()->waitForLoadState('networkidle');

    for ($i = 0; $i < 50 && ! $page->script('() => !!document.querySelector(\'[x-data="nostrAuth"]\')?._x_dataStack'); $i++) {
        usleep(200_000);
    }
}

/**
 * @return array{sameDocument: bool, path: string, flag: mixed, mobile: mixed, duplicateInlineScripts: list<string>}
 */
function readHandOffState(object $page): array
{
    return $page->script(<<<'JS'
        () => {
            const auth = document.querySelector('[x-data="nostrAuth"]');
            const inline = [...document.head.querySelectorAll('script:not([src])')].map((s) => s.textContent.trim());
            return {
                sameDocument: window.__sameDocument === true,
                path: location.pathname,
                flag: window.__nostrMobile ?? null,
                mobile: auth ? Alpine.$data(auth).mobile : 'no nostrAuth element',
                duplicateInlineScripts: [...new Set(inline.filter((text, i) => inline.indexOf(text) !== i))].map((t) => t.slice(0, 60)),
            };
        }
    JS);
}

it('hands /onboarding off to the island with a full load, so nostrAuth.mobile follows the host flag', function () {
    $page = startOnboardingAtLastStepInAppMode();

    // The real button, not a shortcut: `finish()` decides how the hand-off happens.
    $page->script("() => [...document.querySelectorAll('button')].find((b) => b.textContent.includes('App starten'))?.click()");
    waitForIsland($page);

    $state = readHandOffState($page);

    // No reload by the test: the hand-off itself replaces the document. On the old
    // `wire:navigate` hand-off `sameDocument` stayed true and `mobile` stayed false.
    expect($state['sameDocument'])->toBeFalse('the hand-off stayed in the onboarding document')
        ->and($state['flag'])->toBeTrue()
        ->and($state['mobile'])->toBeTrue('nostrAuth shows the web branch although the host is the app')
        ->and($state['duplicateInlineScripts'])->toBe([]);

    // A guest stays on Start (Concept C designs it for guests; the device gate acts only on
    // routes behind `nostr.auth`). The login sheet opened from here offers Amber — the
    // branch the device showed wrongly.
    usleep(3_000_000);
    $page->script("() => window.dispatchEvent(new CustomEvent('open-login-sheet', { detail: { intent: {} } }))");
    usleep(500_000);

    expect($page->script('() => location.pathname'))->toBe('/start')
        ->and($page->script("() => [...document.querySelectorAll('button')].some((b) => b.offsetParent !== null && b.textContent.includes('Mit Amber anmelden'))"))
        ->toBeTrue('the login sheet does not offer Amber');
});

it('boots the island as the app already on /onboarding, and keeps it across a wire:navigate', function () {
    // The host head sets the flag too, so no reader of `isMobile` can freeze as "web" on a
    // host-first document — whatever way the reader then leaves it. The guest stays on the
    // host page: the device gate acts only on routes behind `nostr.auth`.
    $page = startOnboardingAtLastStepInAppMode();

    expect($page->script('() => window.__nostrMobile ?? null'))->toBeTrue('the host head does not set the flag');

    usleep(3_000_000);
    expect($page->script('() => location.pathname'))->toBe('/onboarding');

    $page->script("() => Livewire.navigate('/start')");
    for ($i = 0; $i < 50 && $page->script('() => location.pathname') !== '/start'; $i++) {
        usleep(200_000);
    }
    for ($i = 0; $i < 50 && ! $page->script('() => !!document.querySelector(\'[x-data="nostrAuth"]\')?._x_dataStack'); $i++) {
        usleep(200_000);
    }

    $state = readHandOffState($page);

    expect($state['sameDocument'])->toBeTrue('this case must measure the SPA path, but the document was replaced')
        ->and($state['mobile'])->toBeTrue('nostrAuth froze isMobile before the host flag was set');
});
