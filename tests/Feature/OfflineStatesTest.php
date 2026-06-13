<?php

use App\Http\Integrations\Portal\PortalConnector;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Native\Mobile\Facades\Device;
use Native\Mobile\Facades\Dialog;
use Native\Mobile\Facades\Network;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

/**
 * Connector ohne Retries/Wartezeit in den Container hängen, damit
 * Fehler-Tests nicht schlafen und je Versuch genau einen Mock verbrauchen.
 */
function withFastConnector(): void
{
    $connector = app(PortalConnector::class);
    $connector->tries = 1;
    $connector->retryInterval = 0;

    app()->instance(PortalConnector::class, $connector);
}

/**
 * Stale-Kopie für mapMeetups(withIntro: true, withLogos: true) ablegen
 * (Cache-Key-Schema von PortalApi::cacheKey()).
 *
 * @param  list<array<string, mixed>>  $meetups
 */
function staleMapMeetups(array $meetups): void
{
    Cache::forever('portal_api:map-meetups:'.md5((string) json_encode([true, true])).':stale', $meetups);
}

it('shows the connection error state with retry when loading fails without a stale copy', function () {
    withoutPortalToken();
    withFastConnector();
    MockClient::global([MockResponse::make([], 500)]);

    Livewire::test('pages::meetups.index')
        ->assertSee('Meetups nicht verfügbar')
        ->assertSee('Erneut versuchen')
        ->assertDontSee('Keine Meetups gefunden');
});

it('shows the stale-data banner when the api fails but a stale copy exists', function () {
    withoutPortalToken();
    withFastConnector();
    staleMapMeetups([mapMeetupFixture()]);
    MockClient::global([MockResponse::make([], 500)]);

    Livewire::test('pages::meetups.index')
        ->assertSee('Einundzwanzig Aschaffenburg')
        ->assertSee('Verbindungsproblem — Daten sind möglicherweise nicht aktuell.')
        ->assertDontSee('Erneut versuchen');
});

it('shows the offline banner with the last loaded data when the device is offline', function () {
    withoutPortalToken();
    Network::shouldReceive('status')->andReturn((object) ['connected' => false]);
    staleMapMeetups([mapMeetupFixture()]);
    MockClient::global([]);

    Livewire::test('pages::meetups.index')
        ->assertSee('Offline — du siehst zuletzt geladene Daten.')
        ->assertSee('Einundzwanzig Aschaffenburg');

    MockClient::global()->assertNothingSent();
});

it('shows the error state instead of the banner when offline without any cached data', function () {
    withoutPortalToken();
    Network::shouldReceive('status')->andReturn((object) ['connected' => false]);
    MockClient::global([]);

    Livewire::test('pages::meetups.index')
        ->assertSee('Meetups nicht verfügbar')
        ->assertDontSee('Offline — du siehst zuletzt geladene Daten.');
});

it('alerts via the native dialog when retrying while offline', function () {
    withoutPortalToken();
    Network::shouldReceive('status')->andReturn((object) ['connected' => false]);
    MockClient::global([]);

    Dialog::shouldReceive('alert')
        ->once()
        ->withArgs(fn (string $title, string $message): bool => $title === 'Keine Verbindung');

    Livewire::test('pages::meetups.index')
        ->assertSee('Erneut versuchen')
        ->call('retry');
});

it('alerts via the native dialog when a retry still cannot reach the portal', function () {
    withoutPortalToken();
    withFastConnector();
    MockClient::global([MockResponse::make([], 500), MockResponse::make([], 500)]);

    Dialog::shouldReceive('alert')
        ->once()
        ->withArgs(fn (string $title, string $message): bool => $title === 'Portal nicht erreichbar');

    Livewire::test('pages::meetups.index')
        ->assertSee('Erneut versuchen')
        ->call('retry');
});

it('confirms a successful retry with a native toast', function () {
    withoutPortalToken();
    withFastConnector();
    MockClient::global([MockResponse::make([], 500), MockResponse::make([mapMeetupFixture()])]);

    Dialog::shouldReceive('toast')->once()->with('Aktualisiert.');

    Livewire::test('pages::meetups.index')
        ->assertSee('Erneut versuchen')
        ->call('retry')
        ->assertSee('Einundzwanzig Aschaffenburg')
        ->assertDontSee('Erneut versuchen');
});

it('gives native haptic feedback when retrying (Phase 1.3)', function () {
    withoutPortalToken();
    withFastConnector();
    MockClient::global([MockResponse::make([], 500), MockResponse::make([mapMeetupFixture()])]);

    Dialog::shouldReceive('toast');
    Device::shouldReceive('vibrate')->once();

    Livewire::test('pages::meetups.index')->call('retry');
});

it('shows the error state on the events page when loading fails', function () {
    withoutPortalToken();
    withFastConnector();
    // events() und countries() lesen beide meetupEvents; Fehlschläge werden
    // nicht gecacht, also verbraucht der Render zwei Mock-Antworten.
    MockClient::global([MockResponse::make([], 500), MockResponse::make([], 500)]);

    Livewire::test('pages::events.index')
        ->assertSee('Termine nicht verfügbar')
        ->assertSee('Erneut versuchen');
});

it('shows the error state on the detail page when the portal is unreachable', function () {
    withoutPortalToken();
    withFastConnector();
    MockClient::global([MockResponse::make([], 500)]);

    Livewire::test('pages::courses.show', ['id' => 7])
        ->assertSee('Keine Verbindung zum Portal')
        ->assertDontSee('Kurs nicht gefunden');
});
