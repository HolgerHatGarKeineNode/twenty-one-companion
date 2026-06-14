<?php

use App\Http\Integrations\Portal\Requests\AddMeetupToMineRequest;
use App\Http\Integrations\Portal\Requests\CreateCityRequest;
use App\Http\Integrations\Portal\Requests\CreateMeetupEventRequest;
use App\Http\Integrations\Portal\Requests\CreateMeetupRequest;
use App\Http\Integrations\Portal\Requests\CreateVenueRequest;
use App\Http\Integrations\Portal\Requests\UpdateCityRequest;
use App\Http\Integrations\Portal\Requests\UpdateMeetupEventRequest;
use App\Http\Integrations\Portal\Requests\UpdateMeetupRequest;
use App\Http\Integrations\Portal\Requests\UpdateVenueRequest;
use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * Den JSON-Body eines schreibenden Requests als Array.
 *
 * @return array<string, mixed>
 */
function bodyOf(Request $request): array
{
    return $request->body()->all();
}

it('builds the create-meetup request', function () {
    $payload = ['name' => 'Einundzwanzig Ansbach', 'city_id' => 42, 'is_active' => true];

    $request = new CreateMeetupRequest($payload);

    expect($request->getMethod())->toBe(Method::POST)
        ->and($request->resolveEndpoint())->toBe('/meetup')
        ->and(bodyOf($request))->toBe($payload);
});

it('builds the update-meetup request with id in path and partial body', function () {
    $request = new UpdateMeetupRequest(7, ['intro' => 'Neuer Text']);

    expect($request->getMethod())->toBe(Method::PATCH)
        ->and($request->resolveEndpoint())->toBe('/meetup/7')
        ->and(bodyOf($request))->toBe(['intro' => 'Neuer Text']);
});

it('builds the add-meetup-to-mine request body-free with the slug in the path', function () {
    $request = new AddMeetupToMineRequest('wien');

    expect($request->getMethod())->toBe(Method::POST)
        ->and($request->resolveEndpoint())->toBe('/my-meetups/wien')
        ->and(bodyOf($request))->toBe([]);
});

it('builds the create-meetup-event request', function () {
    $payload = ['meetup_id' => 7, 'start' => '2026-08-01 18:00:00', 'location' => 'Café'];

    $request = new CreateMeetupEventRequest($payload);

    expect($request->getMethod())->toBe(Method::POST)
        ->and($request->resolveEndpoint())->toBe('/meetup-events')
        ->and(bodyOf($request))->toBe($payload);
});

it('builds the update-meetup-event request', function () {
    $request = new UpdateMeetupEventRequest(13, ['location' => 'Neuer Ort']);

    expect($request->getMethod())->toBe(Method::PATCH)
        ->and($request->resolveEndpoint())->toBe('/meetup-events/13')
        ->and(bodyOf($request))->toBe(['location' => 'Neuer Ort']);
});

it('builds the create-venue request', function () {
    $payload = ['city_id' => 42, 'name' => 'Bitcoin Bar', 'street' => 'Hauptstr. 1'];

    $request = new CreateVenueRequest($payload);

    expect($request->getMethod())->toBe(Method::POST)
        ->and($request->resolveEndpoint())->toBe('/venues')
        ->and(bodyOf($request))->toBe($payload);
});

it('builds the update-venue request', function () {
    $request = new UpdateVenueRequest(5, ['name' => 'Neuer Name']);

    expect($request->getMethod())->toBe(Method::PATCH)
        ->and($request->resolveEndpoint())->toBe('/venues/5')
        ->and(bodyOf($request))->toBe(['name' => 'Neuer Name']);
});

it('builds the create-city request', function () {
    $payload = ['country_id' => 1, 'name' => 'Ansbach', 'longitude' => 10.57, 'latitude' => 49.3];

    $request = new CreateCityRequest($payload);

    expect($request->getMethod())->toBe(Method::POST)
        ->and($request->resolveEndpoint())->toBe('/cities')
        ->and(bodyOf($request))->toBe($payload);
});

it('builds the update-city request', function () {
    $request = new UpdateCityRequest(9, ['population' => 41000]);

    expect($request->getMethod())->toBe(Method::PATCH)
        ->and($request->resolveEndpoint())->toBe('/cities/9')
        ->and(bodyOf($request))->toBe(['population' => 41000]);
});
