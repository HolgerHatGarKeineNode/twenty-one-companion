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
 * Stale-Kopie für die schlanke App-Liste mobileMeetups() ablegen
 * (parameterloser Cache-Key, daher ohne md5-Suffix).
 *
 * @param  list<array<string, mixed>>  $meetups
 */
function staleMobileMeetups(array $meetups): void
{
    Cache::forever('portal_api:v2:mobile-meetups:stale', $meetups);
}

it('shows the connection error state with retry when loading fails without a stale copy', function () {
    withoutPortalToken();
    withFastConnector();
    MockClient::global([MockResponse::make([], 500)]);

    Livewire::test('pages::meetups.index')->call('load')
        ->assertSee('Meetups nicht verfügbar')
        ->assertSee('Erneut versuchen')
        ->assertDontSee('Keine Meetups gefunden');
});

it('shows the stale-data banner when the api fails but a stale copy exists', function () {
    withoutPortalToken();
    withFastConnector();
    staleMobileMeetups([mobileMeetupFixture()]);
    MockClient::global([MockResponse::make([], 500)]);

    Livewire::test('pages::meetups.index')->call('load')
        ->assertSeeText('Einundzwanzig Aschaffenburg')
        ->assertSee('Verbindungsproblem — Daten sind möglicherweise nicht aktuell.')
        ->assertDontSee('Erneut versuchen');
});

it('shows the offline banner with the last loaded data when the device is offline', function () {
    withoutPortalToken();
    Network::shouldReceive('status')->andReturn((object) ['connected' => false]);
    staleMobileMeetups([mobileMeetupFixture()]);
    MockClient::global([]);

    Livewire::test('pages::meetups.index')->call('load')
        ->assertSee('Offline — du siehst zuletzt geladene Daten.')
        ->assertSeeText('Einundzwanzig Aschaffenburg');

    MockClient::global()->assertNothingSent();
});

it('shows the error state instead of the banner when offline without any cached data', function () {
    withoutPortalToken();
    Network::shouldReceive('status')->andReturn((object) ['connected' => false]);
    MockClient::global([]);

    Livewire::test('pages::meetups.index')->call('load')
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

    Livewire::test('pages::meetups.index')->call('load')
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

    Livewire::test('pages::meetups.index')->call('load')
        ->assertSee('Erneut versuchen')
        ->call('retry');
});

it('confirms a successful retry with a native toast', function () {
    withoutPortalToken();
    withFastConnector();
    MockClient::global([MockResponse::make([], 500), MockResponse::make([mobileMeetupFixture()])]);

    Dialog::shouldReceive('toast')->once()->with('Aktualisiert.');

    Livewire::test('pages::meetups.index')->call('load')
        ->assertSee('Erneut versuchen')
        ->call('retry')
        ->assertSeeText('Einundzwanzig Aschaffenburg')
        ->assertDontSee('Erneut versuchen');
});

it('gives native haptic feedback when retrying (Phase 1.3)', function () {
    withoutPortalToken();
    withFastConnector();
    MockClient::global([MockResponse::make([], 500), MockResponse::make([mobileMeetupFixture()])]);

    Dialog::shouldReceive('toast');
    Device::shouldReceive('vibrate')->once();

    Livewire::test('pages::meetups.index')->call('retry');
});

it('shows the session-expired state with a reconnect cta on a 401, not the unreachable error', function () {
    withPortalToken();
    withFastConnector();
    MockClient::global([MockResponse::make(['message' => 'Unauthenticated.'], 401)]);

    Livewire::test('pages::meetups.index')
        ->set('tab', 'meine')
        ->call('load')
        ->assertSee('Sitzung abgelaufen')
        ->assertSee('Neu verbinden')
        // Ein 401 ist KEIN Verbindungsproblem — der Netzfehler-Zustand bleibt aus.
        ->assertDontSee('Meetups nicht verfügbar');
});

it('alerts with the expired-session dialog when a retry still returns 401', function () {
    withPortalToken();
    withFastConnector();
    MockClient::global([
        MockResponse::make(['message' => 'Unauthenticated.'], 401),
        MockResponse::make(['message' => 'Unauthenticated.'], 401),
    ]);

    Dialog::shouldReceive('alert')
        ->once()
        ->withArgs(fn (string $title, string $message): bool => $title === 'Sitzung abgelaufen');

    Livewire::test('pages::meetups.index')
        ->set('tab', 'meine')
        ->call('load')
        ->assertSee('Neu verbinden')
        ->call('retry');
});

it('shows the error state on the events page when loading fails', function () {
    withoutPortalToken();
    withFastConnector();
    // events() und countries() lesen beide meetupEvents; Fehlschläge werden
    // nicht gecacht, also verbraucht der Render zwei Mock-Antworten.
    MockClient::global([MockResponse::make([], 500), MockResponse::make([], 500)]);

    Livewire::test('pages::events.index')
        ->call('load')
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
