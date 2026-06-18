<?php

use App\Http\Integrations\Portal\Requests\GetCitiesRequest;
use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetVenuesRequest;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

it('builds leaflet markers with escaped popup html and detail links', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([
            mapMeetupFixture(['name' => 'Einundzwanzig <Aschaffenburg>']),
        ]),
    ]);

    $markers = Livewire::test('pages::map.index')->instance()->markers();

    expect($markers)->toHaveCount(1)
        ->and($markers[0]['lat'])->toBe(49.977159)
        ->and($markers[0]['lng'])->toBe(9.146998)
        ->and($markers[0]['popup'])->toContain('Einundzwanzig &lt;Aschaffenburg&gt;')
        ->and($markers[0]['popup'])->toContain('Aschaffenburg · DE')
        ->and($markers[0]['popup'])->toContain(route('meetups.show', 'aschaffenburg'));
});

it('shows an empty state when no meetups are available for the map', function () {
    withoutPortalToken();
    MockClient::global([GetMapMeetupsRequest::class => MockResponse::make([])]);

    Livewire::test('pages::map.index')
        ->assertSee('Karte nicht verfügbar');
});

it('lists all cities with country and flag on the staedte tab', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetCitiesRequest::class => MockResponse::make([
            cityFixture(),
            cityFixture(['id' => 81, 'name' => 'Wien', 'country_id' => 2, 'country' => ['id' => 2, 'name' => 'Austria', 'code' => 'at'], 'flag' => 'https://portal.einundzwanzig.space/vendor/blade-flags/country-at.svg']),
        ]),
    ]);

    Livewire::test('pages::map.index')
        ->set('tab', 'staedte')
        ->set('country', '')
        ->assertSee('Regensburg')
        ->assertSee('Germany')
        ->assertSee('country-de.svg')
        ->assertSee('Wien');

    MockClient::global()->assertSent(fn ($request, $response): bool => $response->getPendingRequest()->query()->get('withDetails') === '1');
});

it('filters cities by city or country name', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetCitiesRequest::class => MockResponse::make([
            cityFixture(),
            cityFixture(['id' => 81, 'name' => 'Wien', 'country' => ['id' => 2, 'name' => 'Austria', 'code' => 'at']]),
        ]),
    ]);

    Livewire::test('pages::map.index')
        ->set('tab', 'staedte')
        ->set('country', '')
        ->set('search', 'austria')
        ->assertSee('Wien')
        ->assertDontSee('Regensburg')
        ->set('search', 'regens')
        ->assertSee('Regensburg')
        ->assertDontSee('Wien');
});

it('filters cities by the selected region', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetCitiesRequest::class => MockResponse::make([
            cityFixture(),
            cityFixture(['id' => 81, 'name' => 'Wien', 'country' => ['id' => 2, 'name' => 'Austria', 'code' => 'at']]),
        ]),
    ]);

    // Region DE (Onboarding-Default): nur deutsche Städte, kein Wien.
    Livewire::test('pages::map.index')
        ->set('tab', 'staedte')
        ->set('country', 'de')
        ->assertSee('Regensburg')
        ->assertDontSee('Wien')
        ->set('country', 'at')
        ->assertSee('Wien')
        ->assertDontSee('Regensburg');
});

it('filters map markers by region and re-fits the map on change', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([
            mapMeetupFixture(),
            mapMeetupFixture(['name' => 'Einundzwanzig Wien', 'country' => 'AT', 'city' => 'Wien']),
        ]),
    ]);

    $component = Livewire::test('pages::map.index')->set('country', 'de');

    expect($component->instance()->markers())->toHaveCount(1);

    $component->set('country', '')->assertDispatched('map-country-changed');

    expect($component->instance()->markers())->toHaveCount(2);
});

it('lists venues with trimmed description on the orte tab and filters them', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetVenuesRequest::class => MockResponse::make([
            venueFixture(),
            venueFixture(['id' => 132, 'name' => 'Volkshochschule', 'description' => 'Kempten, ']),
        ]),
    ]);

    Livewire::test('pages::map.index')
        ->set('tab', 'orte')
        ->assertSee('AfueraFest 2025')
        ->assertSee('Regensburg, Hauptstraße 1')
        ->assertSee('Volkshochschule')
        ->assertSee('Kempten')
        ->set('search', 'afuera')
        ->assertSee('AfueraFest 2025')
        ->assertDontSee('Volkshochschule');
});

it('shows an empty state for an unknown search on the lists', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetCitiesRequest::class => MockResponse::make([cityFixture()]),
    ]);

    Livewire::test('pages::map.index')
        ->set('tab', 'staedte')
        ->set('search', 'gibtesnicht')
        ->assertSee('Keine Städte gefunden');
});

it('renders the map page over http', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
    ]);

    $this->get(route('map'))
        ->assertOk()
        ->assertSee('basemaps.cartocdn.com')
        ->assertSee('btc_marker.png');
});
