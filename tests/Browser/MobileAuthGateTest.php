<?php

declare(strict_types=1);

/**
 * The device gate in the browser (host flag simulated server-side, like
 * `OnboardingHandOffTest`): a guest keeps Start and the read-only areas, a guest on a page
 * behind `nostr.auth` goes to the login, a signed-in reader is left alone.
 */
function openInAppMode(string $path): object
{
    config(['nativephp-internal.running' => true]);

    $page = visit($path)->on()->mobile()->inDarkMode();
    $page->page()->waitForLoadState('networkidle');

    return $page;
}

/** Long enough for the gate: it runs once the auth stores have synced (≈1 s measured). */
function pathAfterGate(object $page): string
{
    usleep(3_000_000);

    return (string) $page->script('() => location.pathname + location.search');
}

it('leaves a guest on Start after a cold start', function () {
    $page = openInAppMode('/start');

    expect($page->script('() => window.__nostrMobile'))->toBeTrue()
        ->and(pathAfterGate($page))->toBe('/start');
});

it('sends a guest on a page behind nostr.auth to the login, with the way back', function () {
    $page = openInAppMode('/bereich/wallet');

    expect(pathAfterGate($page))->toBe('/nostr-login?return=%2Fbereich%2Fwallet');
});

it('leaves a signed-in reader on a page behind nostr.auth', function () {
    $page = openInAppMode('/start');

    // Secret key 1 and its public key (the x coordinate of the secp256k1 generator): a
    // fixed, valid test pair, stored the way welshman's `sync` writes it.
    $page->script(<<<'JS'
        () => {
            const pk = '79be667ef9dcbbac55a06295ce870b07029bfcdb2dce28d959f2815b16f81798';
            const sk = '0000000000000000000000000000000000000000000000000000000000000001';
            localStorage.setItem('pubkey', JSON.stringify(pk));
            localStorage.setItem('sessions', JSON.stringify({ [pk]: { method: 'nip01', pubkey: pk, secret: sk } }));
            location.assign('/bereich/wallet');
        }
    JS);

    // The embedded server is still busy with Start for a few seconds; the old document stays
    // on screen until the new one arrives. The gated page carries the marker — wait for it,
    // or this case would measure Start.
    for ($i = 0; $i < 100 && ! $page->script('() => !!document.querySelector(\'meta[name="nostr-auth-required"]\')'); $i++) {
        usleep(200_000);
    }

    expect($page->script('() => !!document.querySelector(\'meta[name="nostr-auth-required"]\')'))->toBeTrue()
        ->and(pathAfterGate($page))->toBe('/bereich/wallet');
});

it('looks again after a wire:navigate into a page behind nostr.auth', function () {
    $page = openInAppMode('/start');
    usleep(2_000_000);
    // A plain `wire:navigate` link, as a room link inside an article would be — not a
    // gated tile (those open the login sheet on the tap already, `authGate.gateTap`).
    $page->script(<<<'JS'
        () => {
            const link = document.createElement('a');
            link.href = '/bereich/wallet';
            link.setAttribute('wire:navigate', '');
            link.textContent = 'wallet';
            link.id = 'probe-link';
            document.body.prepend(link);
        }
    JS);
    $page->click('#probe-link');

    // The embedded test server answers one request at a time, and Start keeps it busy for a
    // while (the portal index against a dead port) — wait for the navigation itself first.
    for ($i = 0; $i < 100 && $page->script('() => location.pathname') === '/start'; $i++) {
        usleep(200_000);
    }

    expect(pathAfterGate($page))->toBe('/nostr-login?return=%2Fbereich%2Fwallet');
});
