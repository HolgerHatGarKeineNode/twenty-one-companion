<?php

use App\Http\Integrations\Portal\Requests\GetMobileMeetupsRequest;
use App\Services\AppPreferences;
use Livewire\Livewire;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\SecureStorage;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

beforeEach(function () {
    withoutPortalToken();
    MockClient::global([
        GetMobileMeetupsRequest::class => MockResponse::make([]),
    ]);
});

/**
 * The app-only settings sections, injected into the PACKAGE hub (Concept C, P2).
 *
 * ── What this file replaces ─────────────────────────────────────────────────────────
 *
 * `ProfilePageTest`, which measured `/profile`: this app's own settings screen, carrying the
 * Portal preferences (Livewire server state) AND the Nostr sections inline, while the package
 * route `/settings` rendered a second, thinner version of the same sections. Two places for
 * one thing, depending on which way you came — and a `settings_route` config line existed
 * solely to say which one this host meant.
 *
 * There is one place now. The package hub understands `view:` entries in
 * `config('group.settings')`, and each of this app's entries mounts a nested Livewire
 * component — which is where the server state lives, because the hub has none.
 *
 * The cases below are the old ones, retargeted: same demands, new address and new component
 * names. What is NEW is the first case — that the sections really arrive in the hub, in the
 * configured order.
 */
it('injects the app sections into the package hub, in the configured order', function () {
    $html = (string) $this->get(route('group.ich.einstellungen'))->assertOk()->getContent();

    // The Nostr sections of the package …
    expect($html)->toContain('id="settings-account"');
    expect($html)->toContain('x-data="nostrSpaceSettings"');
    // … and this app's own, from the `view:` entries.
    expect($html)->toContain(__('Dein Portal-Konto'));
    expect($html)->toContain('id="settings-region"');
    expect($html)->toContain('id="settings-push"');
    expect($html)->toContain('id="settings-about"');
    expect($html)->toContain('id="settings-session"');

    // ORDER, not merely presence: the registry decides it, and a registry whose order is not
    // measured is a list nobody has to keep.
    $positions = [];
    foreach (['settings-account', 'settings-region', 'settings-push', 'settings-about', 'settings-session'] as $id) {
        $at = mb_strpos($html, 'id="'.$id.'"');
        expect($at)->not->toBeFalse("section {$id} missing — the order is not measurable");
        $positions[$id] = $at;
    }
    $sorted = $positions;
    asort($sorted);
    expect(array_keys($sorted))->toBe(array_keys($positions));
});

it('keeps theme and sign-out exactly once each', function () {
    // De-duplication (IA §3). The theme switch belongs to the package's `appearance` section;
    // this app must not bring a second one. Sign-out is the app's OWN — it revokes the Portal
    // token as well — and it REPLACES the package's `session` partial instead of standing next
    // to it: two buttons would leave whoever presses the wrong one half signed in, with a
    // valid Portal token on a device that looks signed out.
    $html = (string) $this->get(route('group.ich.einstellungen'))->assertOk()->getContent();

    expect(substr_count($html, 'x-model="$flux.appearance"'))->toBe(1);
    expect(substr_count($html, 'wire:click="logout"'))->toBe(1);
});

it('renders the about section with the version and the way out to the Portal', function () {
    $this->get(route('group.ich.einstellungen'))
        ->assertOk()
        ->assertSee(__('Version'))
        ->assertSee(config('nativephp.version'))
        ->assertSee(__('EINUNDZWANZIG-Portal öffnen'));
});

it('loads the stored preferences', function () {
    completeOnboarding(country: 'ch');

    Livewire::test('settings.region')
        ->assertSet('locale', 'de')
        ->assertSet('country', 'ch');
});

it('saves a changed region', function () {
    Livewire::test('settings.region')
        ->set('country', 'at');

    expect(app(AppPreferences::class)->country())->toBe('at');
});

it('reverts an unknown region to the stored one', function () {
    completeOnboarding(country: 'de');

    Livewire::test('settings.region')
        ->set('country', 'xx')
        ->assertSet('country', 'de');

    expect(app(AppPreferences::class)->country())->toBe('de');
});

it('saves a changed language', function () {
    Livewire::test('settings.region')
        ->set('locale', 'en')
        ->assertSet('locale', 'en');

    expect(app(AppPreferences::class)->locale())->toBe('en');
});

it('reverts an unsupported language', function () {
    Livewire::test('settings.region')
        ->set('locale', 'fr')
        ->assertSet('locale', 'de');

    expect(app(AppPreferences::class)->locale())->toBe('de');
});

it('opens the portal in the in-app browser', function () {
    Browser::shouldReceive('inApp')
        ->once()
        ->with('https://portal.einundzwanzig.space');

    Livewire::test('settings.about')
        ->call('openPortal');
});

it('runs the ONE teardown without error (Portal token plus the client Nostr session)', function () {
    // `withoutPortalToken` (beforeEach) → `PortalAuth::logout` skips the HTTP revocation (no
    // token); `forgetToken()` deletes the key-store entry (delete mocked). `assertOk` proves
    // the wiring, `route('group.start')` and the `js()` teardown without an error.
    SecureStorage::shouldReceive('delete')->with('portal_api_token')->andReturnTrue();

    Livewire::test('settings.logout')->call('logout')->assertOk();
});
