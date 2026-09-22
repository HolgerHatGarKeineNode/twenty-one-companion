<?php

use App\Http\Integrations\Portal\Requests\GetCitiesRequest;
use App\Http\Integrations\Portal\Requests\GetCountriesRequest;
use App\Http\Integrations\Portal\Requests\GetMobileMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMyCitiesRequest;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * The map and places surfaces of this app — AFTER the move (P5).
 *
 * ══ What `/map` was, and where its three tabs live now ═══════════════════════
 *
 * Since P4 the MAP is the one view only this chassis can bind
 * (`/bereich/meetups?ansicht=karte` through `meetup_map_view` → `partials/portal/karte`),
 * and with it the Leaflet code and its markers. The two LISTS („Städte", „Orte") were
 * left on a page that existed only because of them; since P5 they are the „Alle" scope of
 * `/ich/inhalte/orte` — that is where they belong by subject, and whoever creates a place
 * of their own looks first whether the city already exists anyway.
 *
 * This file measures the lists at their new address and the three addresses that lead
 * there. That the map really renders with markers in the package is measured in
 * `PortalCatalogBindingTest` („renders the package meetup page WITH the app map").
 */
afterEach(fn () => MockClient::destroyGlobal());

// ── 1. The lists at their new address ───────────────────────────────────────

it('lists all cities with country and flag in the „alle" scope', function () {
    withPortalToken();
    MockClient::global([
        GetCitiesRequest::class => MockResponse::make([
            cityFixture(),
            cityFixture(['id' => 81, 'name' => 'Wien', 'country_id' => 2, 'country' => ['id' => 2, 'name' => 'Austria', 'code' => 'at'], 'flag' => 'https://portal.einundzwanzig.space/vendor/blade-flags/country-at.svg']),
        ]),
    ]);

    // Through the ADDRESS and not with `set()`: `Livewire::test()` renders in the default
    // scope „Meine" first and fetched the own lists on the way — a call this case does not
    // mean.
    Livewire::withQueryParams(['umfang' => 'alle', 'tab' => 'staedte', 'country' => ''])->test('pages::mine.places')
        ->assertSee('Regensburg')
        ->assertSee('Germany')
        ->assertSee('country-de.svg')
        ->assertSee('Wien');

    // `withDetails=1` lifts the Portal's limit of 10 — the same call the map and the place
    // editor make, hence the same cache entry.
    MockClient::global()->assertSent(fn ($request, $response): bool => $response->getPendingRequest()->query()->get('withDetails') === '1');
});

it('filters all cities by city or country name and by region', function () {
    withPortalToken();
    MockClient::global([
        GetCitiesRequest::class => MockResponse::make([
            cityFixture(),
            cityFixture(['id' => 81, 'name' => 'Wien', 'country_id' => 2, 'country' => ['id' => 2, 'name' => 'Austria', 'code' => 'at'], 'flag' => 'https://portal.einundzwanzig.space/vendor/blade-flags/country-at.svg']),
        ]),
    ]);

    Livewire::withQueryParams(['umfang' => 'alle', 'tab' => 'staedte', 'country' => ''])->test('pages::mine.places')
        ->set('search', 'wien')
        ->assertSee('Wien')
        ->assertDontSee('Regensburg')
        ->set('search', '')
        ->set('country', 'de')
        ->assertSee('Regensburg')
        ->assertDontSee('Wien');
});

it('shows an empty state for an unknown search on the „alle" lists', function () {
    withPortalToken();
    MockClient::global([
        GetCitiesRequest::class => MockResponse::make([cityFixture()]),
    ]);

    Livewire::withQueryParams(['umfang' => 'alle', 'tab' => 'staedte'])->test('pages::mine.places')
        ->set('search', 'gibtesnicht')
        ->assertSee('Keine Städte gefunden');
});

it('opens on the OWN entries and offers the other scope', function () {
    // The default is „Meine": that is the page this address carries — „Alle" is the guest
    // on it.
    withPortalToken();
    MockClient::global([
        GetMyCitiesRequest::class => MockResponse::make(['data' => [myCityFixture()]]),
        GetCountriesRequest::class => MockResponse::make([countryFixture(['id' => 1, 'name' => 'Germany'])]),
    ]);

    Livewire::test('pages::mine.places')
        ->assertSet('umfang', 'meine')
        ->assertSee('data-orte-umfang', false)
        ->assertSee('Stadt anlegen');
});

it('falls back to the own entries on an unknown scope', function () {
    withPortalToken();

    Livewire::withQueryParams(['umfang' => 'quatsch'])->test('pages::mine.places')
        ->assertSet('umfang', 'meine');
});

// ── 2. The old address, with its three destinations ─────────────────────────

it('forwards /map to the package map and its two lists to the new scope', function () {
    completeOnboarding();

    // Without a tab: the real map — this chassis binds it (unlike the web, which points at
    // the Portal's map there).
    $this->get('/map')->assertRedirect('/bereich/meetups?ansicht=karte');
    $this->get('/map?country=at')->assertRedirect('/bereich/meetups?ansicht=karte&land=at');

    // The city list to its new address, with the scope it stands under there. The venue
    // list is gone with the portal's venue model; its old link lands on the cities too.
    $this->get('/map?tab=staedte')->assertStatus(301)->assertRedirect('/ich/inhalte/orte?umfang=alle');
    $this->get('/map?tab=orte')->assertStatus(301)->assertRedirect('/ich/inhalte/orte?umfang=alle');
});

it('renders the package map with its tiles and marker through this chassis', function () {
    completeOnboarding();
    withoutPortalToken();
    MockClient::global([
        GetMobileMeetupsRequest::class => MockResponse::make([mobileMeetupFixture()]),
    ]);

    // The promise that could have disappeared with the deleted page: the map is still
    // there, with its tiles and its marker — only inside the package chassis now.
    $this->get('/bereich/meetups?ansicht=karte')
        ->assertOk()
        ->assertSee('tiles.openfreemap.org', false)
        ->assertDontSee('basemaps.cartocdn.com', false)
        ->assertSee('btc_marker.png');
});
