<?php

use Illuminate\Support\Js;
use Livewire\Livewire;

/**
 * „Ich › Verein" in THIS app (D11, P5) — the door that is a different one here.
 *
 * ══ Why the page is measured twice ═══════════════════════════════════════════
 *
 * The surface itself lives in the package and is measured there (`IchVereinTest`
 * in the host repo: the gate, the four sentences, the way out, the association
 * that is not set up). What THIS host contributes is the door to the membership
 * API — and it is not the web's door:
 *
 *   web  `/api/verein/*`      session + CSRF; the route runs in the web host itself
 *   app  `/api/app/verein/*`  no session, no CSRF — the NIP-98 signature IS the
 *                             identity, and the route lives in the HOSTED web
 *                             instance, not here
 *
 * From that follows the one promise only this repo can measure: in the app
 * `window.__nostrVerein.proxy` has to name a foreign base. Without it the island
 * aims at its own local origin — where none of these routes exist, and every
 * signature would run into a 404. The island picks the door by `isMobile`
 * (`js/mitgliedschaft.ts`), the flag comes from the head partial; both halves are
 * below.
 */
beforeEach(function (): void {
    config([
        // The chassis of this host. Without the flag `nostr.auth` takes the web half and
        // redirects to the login — in the app there is no server session, the presence gate
        // is client-side (D4).
        'nativephp-internal.running' => true,
        'group.verein_api_url' => 'https://verein.test',
        'group.verein_proxy_base' => 'https://web.test',
        'group.verein_public_url' => 'https://verein.test/beitritt',
        'group.verein_activation_minutes' => 15,
    ]);
});

it('reaches the association page and carries the island', function () {
    completeOnboarding();
    withoutPortalToken();

    $this->get('/ich/verein')
        ->assertOk()
        ->assertSee('nostrVereinMitgliedschaft', false)
        ->assertSee('Deine Mitgliedschaft');
});

it('tells the island the SIGNED door — app flag and a foreign proxy base', function () {
    // Both halves in one case, because only together do they say anything: `isMobile`
    // picks `/api/app/verein`, and the base says which host that route lives on. With one
    // of them missing the signature would go to an address with no recipient.
    completeOnboarding();
    withoutPortalToken();

    $html = (string) $this->get('/ich/verein')->assertOk()->getContent();

    // The VALUE is compared, not a string: `@js` nests JSON inside a JS literal
    // (`JSON.parse('{\u0022api\u0022:…')`), and a hand-written expectation of that shape
    // either matches by accident or not at all. `Js::from` is precisely the encoder the
    // head partial uses.
    $erwartet = (string) Js::from([
        'api' => 'https://verein.test',
        'proxy' => 'https://web.test',
        'activationMinutes' => 15,
        'publicUrl' => 'https://verein.test/beitritt',
    ]);

    expect($html)->toContain('window.__nostrMobile = window.__nostrMobile ?? true')
        ->and($html)->toContain('window.__nostrVerein = window.__nostrVerein ?? '.$erwartet);
});

it('does not serve either proxy itself — this app is not the web instance', function () {
    // The counter-evidence to the case above: the routes the island points at do NOT exist
    // here. Had the proxy been shipped along by accident, the app bundle would carry the
    // association's `X-Api-Key` — exactly what the separate door prevents.
    completeOnboarding();

    $this->get('/api/app/verein/me')->assertNotFound();
    $this->get('/api/verein/me')->assertNotFound();
});

it('survives a Livewire roundtrip', function () {
    // The case a console measurement does not see: a 500 on this round trip is a rejected
    // promise, not a JS error (house rule 4b).
    completeOnboarding();
    withoutPortalToken();

    Livewire::test('group::ich-verein')->call('$refresh')->assertOk();
});

it('lists the association row under „Ich" and drops it without a configured API', function () {
    completeOnboarding();
    withoutPortalToken();

    $this->get('/ich')->assertOk()->assertSee('/ich/verein', false);

    config(['group.verein_api_url' => null]);
    $this->get('/ich')->assertOk()->assertDontSee('/ich/verein', false);
});
