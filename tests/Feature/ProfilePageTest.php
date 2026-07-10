<?php

use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
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
        GetMapMeetupsRequest::class => MockResponse::make([]),
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

it('is the single settings hub: nostr shortcut + one logout (P5 Settings-Merge)', function () {
    $this->get(route('profile'))
        ->assertOk()
        // Nostr-Identität/Räume/Relays leben im welshman-Kontext (group.settings),
        // erreichbar per Shortcut — EIN Einstieg statt getrennter Settings-Orte.
        ->assertSee(route('group.settings'), false)
        ->assertSee(__('Nostr-Identität, Räume & Relays'))
        // Abmelden an EINEM Ort, mit Bestätigung (§5.4).
        ->assertSee('wire:click="logout"', false)
        ->assertSee(__('Abmelden'));
});

it('logout läuft fehlerfrei durch den EINEN Teardown (Portal + Client-Nostr-Session)', function () {
    // withoutPortalToken (beforeEach) → PortalAuth::logout überspringt den HTTP-
    // Widerruf (kein Token); forgetToken() löscht den Keystore-Key (delete mocken).
    // assertOk beweist Wiring, route('meetups') und den js()-Teardown ohne Fehler.
    SecureStorage::shouldReceive('delete')->with('portal_api_token')->andReturnTrue();

    Livewire::test('pages::profile.index')->call('logout')->assertOk();
});
