<?php

use App\Http\Integrations\Portal\PortalConnector;
use App\Http\Integrations\Portal\Requests\CreateMeetupRequest;
use App\Http\Integrations\Portal\Requests\UpdateMeetupRequest;
use App\Services\PortalApi;
use App\Services\PortalAuth;
use App\Services\PortalWriter;
use App\Services\WriteStatus;
use Illuminate\Support\Facades\Cache;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Http\Response;

afterEach(fn () => MockClient::destroyGlobal());

function portalWriter(): PortalWriter
{
    $connector = app(PortalConnector::class);
    $connector->retryInterval = 0;

    return new PortalWriter($connector, app(PortalAuth::class), app(PortalApi::class));
}

it('sends an authenticated create-meetup request and returns the created body', function () {
    withPortalToken('12|secrettoken');
    MockClient::global([CreateMeetupRequest::class => MockResponse::make(['data' => myMeetupFixture(['id' => 99])], 201)]);

    $result = portalWriter()->createMeetup(['name' => 'Einundzwanzig Ansbach', 'city_id' => 42, 'is_active' => true]);

    expect($result->successful())->toBeTrue()
        ->and($result->status)->toBe(WriteStatus::Success)
        ->and($result->data['data']['id'])->toBe(99);

    MockClient::global()->assertSent(fn (Request $request, Response $response): bool => $response->getPendingRequest()->headers()->get('Authorization') === 'Bearer 12|secrettoken'
        && $request->body()->all() === ['name' => 'Einundzwanzig Ansbach', 'city_id' => 42, 'is_active' => true]);
});

it('invalidates the affected read caches after a successful write', function () {
    withPortalToken();
    Cache::put('portal_api:my-meetups', [myMeetupFixture()], 900);
    Cache::put('portal_api:map-meetups', [mapMeetupFixture()], 900);
    Cache::forever('portal_api:my-meetups:stale', [myMeetupFixture()]);
    MockClient::global([CreateMeetupRequest::class => MockResponse::make(['data' => myMeetupFixture()], 201)]);

    portalWriter()->createMeetup(['name' => 'Neu', 'city_id' => 1]);

    // Frischer Cache verworfen → nächster Lesezugriff lädt neu.
    expect(Cache::has('portal_api:my-meetups'))->toBeFalse()
        ->and(Cache::has('portal_api:map-meetups'))->toBeFalse()
        // Stale-Kopie bleibt als Offline-Netz erhalten.
        ->and(Cache::has('portal_api:my-meetups:stale'))->toBeTrue();
});

it('maps a 422 response to structured field errors', function () {
    withPortalToken();
    MockClient::global([CreateMeetupRequest::class => MockResponse::make([
        'message' => 'The given data was invalid.',
        'errors' => [
            'name' => ['Der Name ist erforderlich.'],
            'city_id' => ['Die Stadt ist erforderlich.'],
        ],
    ], 422)]);

    $result = portalWriter()->createMeetup([]);

    expect($result->status)->toBe(WriteStatus::ValidationError)
        ->and($result->hasValidationErrors())->toBeTrue()
        ->and($result->failed())->toBeTrue()
        ->and($result->errorFor('name'))->toBe('Der Name ist erforderlich.')
        ->and($result->errorFor('city_id'))->toBe('Die Stadt ist erforderlich.')
        ->and($result->errorFor('unknown'))->toBeNull()
        ->and($result->message)->toBe('The given data was invalid.');

    // Bei Fehlern bleibt der Cache unberührt.
    MockClient::global()->assertSentCount(1);
});

it('maps a 403 response to a forbidden result (not creator/admin)', function () {
    withPortalToken();
    MockClient::global([UpdateMeetupRequest::class => MockResponse::make(['message' => 'This action is unauthorized.'], 403)]);

    $result = portalWriter()->updateMeetup(7, ['intro' => 'Neu']);

    expect($result->status)->toBe(WriteStatus::Forbidden)
        ->and($result->failed())->toBeTrue();

    MockClient::global()->assertSent(fn (Request $request, Response $response): bool => str_ends_with(
        (string) $response->getPendingRequest()->getUri(),
        '/api/meetup/7',
    ));
});

it('maps a 401 response to an unauthorized result', function () {
    withPortalToken();
    MockClient::global([CreateMeetupRequest::class => MockResponse::make(['message' => 'Unauthenticated.'], 401)]);

    $result = portalWriter()->createMeetup(['name' => 'X', 'city_id' => 1]);

    expect($result->status)->toBe(WriteStatus::Unauthorized);
});

it('maps a 500 response to a network failure without retrying the write', function () {
    withPortalToken();
    MockClient::global([CreateMeetupRequest::class => MockResponse::make(['message' => 'Server Error'], 500)]);

    $result = portalWriter()->createMeetup(['name' => 'X', 'city_id' => 1]);

    expect($result->status)->toBe(WriteStatus::NetworkFailure);

    // Writes werden nie wiederholt (Duplikat-Schutz): genau ein Versand.
    MockClient::global()->assertSentCount(1);
});

it('refuses to send a write without a portal token', function () {
    withoutPortalToken();
    MockClient::global([]);

    $result = portalWriter()->createMeetup(['name' => 'X', 'city_id' => 1]);

    expect($result->status)->toBe(WriteStatus::Unauthorized);

    MockClient::global()->assertNothingSent();
});

it('builds the correct request for each write entity', function (string $entity, string $endpointFragment) {
    withPortalToken();
    MockClient::global([MockResponse::make(['data' => []], 201)]);

    $writer = portalWriter();

    match ($entity) {
        'meetup-event' => $writer->createMeetupEvent(['meetup_id' => 7, 'start' => '2026-08-01 18:00:00']),
        'venue' => $writer->createVenue(['city_id' => 42, 'name' => 'Bitcoin Bar']),
        'city' => $writer->createCity(['country_id' => 1, 'name' => 'Ansbach']),
    };

    MockClient::global()->assertSent(fn (Request $request, Response $response): bool => str_contains(
        (string) $response->getPendingRequest()->getUri(),
        $endpointFragment,
    ));
})->with([
    'meetup event' => ['meetup-event', '/api/meetup-events'],
    'venue' => ['venue', '/api/venues'],
    'city' => ['city', '/api/cities'],
]);
