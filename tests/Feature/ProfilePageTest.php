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

it('renders the account, settings and about sections', function () {
    $this->get(route('profile'))
        ->assertOk()
        ->assertSee(__('Dein Portal-Konto'))
        ->assertSee(__('Sprache'))
        ->assertSee(__('Region'))
        ->assertSee(__('Darstellung'))
        ->assertSee(__('Version'))
        ->assertSee(config('nativephp.version'))
        ->assertSee(__('EINUNDZWANZIG-Portal öffnen'));
});

it('loads the stored preferences', function () {
    completeOnboarding(country: 'ch');

    Livewire::test('pages::profile.index')
        ->assertSet('locale', 'de')
        ->assertSet('country', 'ch');
});

it('saves a changed region', function () {
    Livewire::test('pages::profile.index')
        ->set('country', 'at');

    expect(app(AppPreferences::class)->country())->toBe('at');
});

it('reverts an unknown region to the stored one', function () {
    completeOnboarding(country: 'de');

    Livewire::test('pages::profile.index')
        ->set('country', 'xx')
        ->assertSet('country', 'de');

    expect(app(AppPreferences::class)->country())->toBe('de');
});

it('saves a changed language', function () {
    Livewire::test('pages::profile.index')
        ->set('locale', 'en')
        ->assertSet('locale', 'en');

    expect(app(AppPreferences::class)->locale())->toBe('en');
});

it('reverts an unsupported language', function () {
    Livewire::test('pages::profile.index')
        ->set('locale', 'fr')
        ->assertSet('locale', 'de');

    expect(app(AppPreferences::class)->locale())->toBe('de');
});

it('pulls the portal-connected flash so the toast fires only once', function () {
    $this->withSession(['portal-connected' => true])
        ->get(route('profile'))
        ->assertOk()
        ->assertSessionMissing('portal-connected');
});

it('opens the portal in the in-app browser', function () {
    Browser::shouldReceive('inApp')
        ->once()
        ->with('https://portal.einundzwanzig.space');

    Livewire::test('pages::profile.index')
        ->call('openPortal');
});

it('verschmilzt Portal + Nostr auf EINEM Screen: Nostr-Sektionen inline, Theme 1×, Logout 1× (P6)', function () {
    $html = $this->get(route('profile'))->assertOk()->getContent();

    // Nostr-Sektionen sind INLINE (welshman-Partials im group-Vollbild-Layout) —
    // KEIN Shortcut mehr zu group.settings, sondern die echten Sektionen direkt hier.
    expect($html)
        ->toContain('x-data="nostrAuth"')              // welshman-Insel aktiv
        ->toContain('id="settings-account"')           // Konto & Identität (account-Partial)
        ->toContain('x-data="nostrSpaceSettings"');    // Space & Räume (space-Partial)

    // De-Dup (IA §3): Theme erscheint GENAU EINMAL (aus dem Portal-Block entfernt,
    // lebt nur noch in „Darstellung"); Abmelden GENAU EINMAL (der Portal+Nostr-logout,
    // NICHT das Nostr-only session-Partial).
    expect(substr_count($html, 'x-model="$flux.appearance"'))->toBe(1);
    expect(substr_count($html, 'wire:click="logout"'))->toBe(1);
});

it('logout läuft fehlerfrei durch den EINEN Teardown (Portal + Client-Nostr-Session)', function () {
    // withoutPortalToken (beforeEach) → PortalAuth::logout überspringt den HTTP-
    // Widerruf (kein Token); forgetToken() löscht den Keystore-Key (delete mocken).
    // assertOk beweist Wiring, route('meetups') und den js()-Teardown ohne Fehler.
    SecureStorage::shouldReceive('delete')->with('portal_api_token')->andReturnTrue();

    Livewire::test('pages::profile.index')->call('logout')->assertOk();
});
