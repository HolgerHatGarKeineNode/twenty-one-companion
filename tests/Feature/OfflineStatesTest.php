<?php

use App\Http\Integrations\Portal\PortalConnector;
use App\Http\Integrations\Portal\Requests\GetCourseRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupsRequest;
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
    /*
     * Build a FRESH one instead of reconfiguring the existing instance.
     *
     * A Saloon connector remembers the mock client it last sent with. Since P5 the
     * remaining pages fetch at mount time (the `load()` detour of the deleted Portal pages
     * is gone), so the instance in the container holds the client of the PREVIOUS case —
     * and `afterEach` destroyed that one. The result was a „Saloon was unable to guess a
     * mock response" that only appeared in sequence and vanished when the case ran alone.
     */
    app()->forgetInstance(PortalConnector::class);

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

/**
 * The same for one's OWN meetups — the list these states are measured on since P5. This
 * app's Portal pages are deleted (D9: the package reads, this host writes), but the
 * error, stale and session states are the business of `PortalApi` and the base class
 * `PortalPage` — and the remaining pages under „Ich › Meine Inhalte" carry them just the
 * same.
 *
 * @param  list<array<string, mixed>>  $meetups
 */
function staleMyMeetups(array $meetups): void
{
    Cache::forever('portal_api:v2:my-meetups:stale', $meetups);
}

it('shows the connection error state with retry when loading fails without a stale copy', function () {
    withPortalToken();
    withFastConnector();
    MockClient::global([GetMyMeetupsRequest::class => MockResponse::make([], 500)]);

    Livewire::test('pages::mine.meetups')
        ->assertSee('Meetups nicht verfügbar')
        ->assertSee('Erneut versuchen')
        ->assertDontSee('Keine Meetups gefunden');
});

it('shows the stale-data banner when the api fails but a stale copy exists', function () {
    withPortalToken();
    withFastConnector();
    staleMyMeetups([myMeetupFixture()]);
    MockClient::global([GetMyMeetupsRequest::class => MockResponse::make([], 500)]);

    Livewire::test('pages::mine.meetups')
        ->assertSeeText('Einundzwanzig Aschaffenburg')
        ->assertSee('Verbindungsproblem — Daten sind möglicherweise nicht aktuell.')
        ->assertDontSee('Erneut versuchen');
});

it('shows the offline banner with the last loaded data when the device is offline', function () {
    withPortalToken();
    Network::shouldReceive('status')->andReturn((object) ['connected' => false]);
    staleMyMeetups([myMeetupFixture()]);
    MockClient::global([]);

    Livewire::test('pages::mine.meetups')
        ->assertSee('Offline — du siehst zuletzt geladene Daten.')
        ->assertSeeText('Einundzwanzig Aschaffenburg');

    MockClient::global()->assertNothingSent();
});

it('shows the error state instead of the banner when offline without any cached data', function () {
    withPortalToken();
    Network::shouldReceive('status')->andReturn((object) ['connected' => false]);
    MockClient::global([]);

    Livewire::test('pages::mine.meetups')
        ->assertSee('Meetups nicht verfügbar')
        ->assertDontSee('Offline — du siehst zuletzt geladene Daten.');
});

it('alerts via the native dialog when retrying while offline', function () {
    withPortalToken();
    Network::shouldReceive('status')->andReturn((object) ['connected' => false]);
    MockClient::global([]);

    Dialog::shouldReceive('alert')
        ->once()
        ->withArgs(fn (string $title, string $message): bool => $title === 'Keine Verbindung');

    Livewire::test('pages::mine.meetups')
        ->assertSee('Erneut versuchen')
        ->call('retry');
});

it('alerts via the native dialog when a retry still cannot reach the portal', function () {
    withPortalToken();
    withFastConnector();
    MockClient::global([GetMyMeetupsRequest::class => MockResponse::make([], 500)]);

    Dialog::shouldReceive('alert')
        ->once()
        ->withArgs(fn (string $title, string $message): bool => $title === 'Portal nicht erreichbar');

    Livewire::test('pages::mine.meetups')
        ->assertSee('Erneut versuchen')
        ->call('retry');
});

it('confirms a successful retry with a native toast', function () {
    withPortalToken();
    withFastConnector();
    // First the failure (mount), then the successful attempt. One's own list is asked
    // exactly ONCE per render — measured, not assumed.
    MockClient::global([
        MockResponse::make([], 500),
        MockResponse::make(['data' => [myMeetupFixture()]]),
    ]);

    Dialog::shouldReceive('toast')->once()->with('Aktualisiert.');

    Livewire::test('pages::mine.meetups')
        ->assertSee('Erneut versuchen')
        ->call('retry')
        ->assertSeeText('Einundzwanzig Aschaffenburg')
        ->assertDontSee('Erneut versuchen');
});

it('gives native haptic feedback when retrying (Phase 1.3)', function () {
    withPortalToken();
    withFastConnector();
    // ONE call per render (failures are never cached): mount, then the retry.
    MockClient::global([
        MockResponse::make([], 500),
        MockResponse::make(['data' => [myMeetupFixture()]]),
    ]);

    Dialog::shouldReceive('toast');
    Device::shouldReceive('vibrate')->once();

    Livewire::test('pages::mine.meetups')->call('retry');
});

it('shows the session-expired state with a reconnect cta on a 401, not the unreachable error', function () {
    withPortalToken();
    withFastConnector();
    // Zwei Renders (mount + die Assertions darauf), je ein Aufruf.
    MockClient::global([
        MockResponse::make(['message' => 'Unauthenticated.'], 401),
        MockResponse::make(['message' => 'Unauthenticated.'], 401),
    ]);

    Livewire::test('pages::mine.meetups')
        ->assertSee('Sitzung abgelaufen')
        ->assertSee('Neu verbinden')
        // Ein 401 ist KEIN Verbindungsproblem — der Netzfehler-Zustand bleibt aus.
        ->assertDontSee('Meetups nicht verfügbar');
});

it('alerts with the expired-session dialog when a retry still returns 401', function () {
    withPortalToken();
    withFastConnector();
    MockClient::global([GetMyMeetupsRequest::class => MockResponse::make(['message' => 'Unauthenticated.'], 401)]);

    Dialog::shouldReceive('alert')
        ->once()
        ->withArgs(fn (string $title, string $message): bool => $title === 'Sitzung abgelaufen');

    Livewire::test('pages::mine.meetups')
        ->assertSee('Neu verbinden')
        ->call('retry');
});

it('shows the error state on the events page when loading fails', function () {
    withoutPortalToken();
    withFastConnector();
    // The Termine list and its country filter read two endpoints; failures are not cached,
    // so each render consumes one mock response per call.
    MockClient::global([
        MockResponse::make([], 500), MockResponse::make([], 500),
        MockResponse::make([], 500), MockResponse::make([], 500),
    ]);

    // The list lives in the package; its empty state reads the same `PortalApi` status
    // („offline") and therefore says that NOTHING WAS LOADED — not that there are no dates.
    // Those are opposite sentences, and swapping them is exactly what the move must not
    // have done.
    Livewire::withQueryParams(['ansicht' => 'termine'])->test('group::meetups')
        ->assertSee(__('Portal nicht erreichbar'));
});

it('shows the error state on the detail page when the portal is unreachable', function () {
    withoutPortalToken();
    withFastConnector();
    MockClient::global([GetCourseRequest::class => MockResponse::make([], 500)]);

    Livewire::test('group::kurs', ['id' => 7])
        ->assertSee(__('Portal nicht erreichbar'))
        ->assertDontSee('Kurs nicht gefunden');
});
