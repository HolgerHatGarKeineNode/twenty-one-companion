<?php

use App\Data\Portal\CourseData;
use App\Data\Portal\CourseDetailData;
use App\Data\Portal\CourseEventData;
use App\Data\Portal\EventMeetupData;
use App\Data\Portal\LecturerDetailData;
use App\Data\Portal\MapMeetupData;
use App\Data\Portal\MeetupData;
use App\Data\Portal\MeetupEventData;
use App\Data\Portal\NextEventData;
use App\Data\Portal\UserProfileData;
use App\Http\Integrations\Portal\PortalConnector;
use App\Http\Integrations\Portal\Requests\GetCitiesRequest;
use App\Http\Integrations\Portal\Requests\GetCountriesRequest;
use App\Http\Integrations\Portal\Requests\GetCourseRequest;
use App\Http\Integrations\Portal\Requests\GetCoursesRequest;
use App\Http\Integrations\Portal\Requests\GetLecturerRequest;
use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMeetupEventsRequest;
use App\Http\Integrations\Portal\Requests\GetMyCourseEventsRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetUserRequest;
use App\Http\Integrations\Portal\Requests\GetVenuesRequest;
use App\Services\PortalApi;
use App\Services\PortalAuth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Native\Mobile\Facades\Network;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Http\Response;

afterEach(fn () => MockClient::destroyGlobal());

/**
 * PortalApi mit Retry ohne Wartezeit, damit Fehler-Tests nicht schlafen.
 */
function portalApi(): PortalApi
{
    $connector = app(PortalConnector::class);
    $connector->retryInterval = 0;

    return new PortalApi($connector, app(PortalAuth::class));
}

function courseFixture(): array
{
    return [
        'id' => 2,
        'name' => 'Bitcoin, Blockchain und Geld',
        'image' => 'https://portal.einundzwanzig.space/img/einundzwanzig.png',
        'media' => [],
    ];
}

function courseEventFixture(): array
{
    return [
        'id' => 9,
        'course_id' => 5,
        'venue_id' => 3,
        'from' => '2026-07-01T18:00:00.000000Z',
        'to' => '2026-07-01T20:00:00.000000Z',
        'link' => 'https://example.com/kurs',
        'created_by' => 7,
        'created_at' => '2026-06-01T00:00:00.000000Z',
        'updated_at' => '2026-06-01T00:00:00.000000Z',
        'course' => ['id' => 5, 'name' => 'Bitcoin, Blockchain und Geld'],
        'venue' => ['id' => 3, 'name' => 'Volkshochschule'],
    ];
}

function userFixture(): array
{
    return [
        'id' => 7,
        'name' => 'Satoshi',
        'email' => 'satoshi@example.com',
        'nostr' => 'npub1xyz',
        'is_lecturer' => false,
        'is_leader' => true,
        'avatar' => 'https://portal.einundzwanzig.space/storage/avatar.png',
    ];
}

it('maps the public map meetups to DTOs including the next event', function () {
    withoutPortalToken();
    MockClient::global([GetMapMeetupsRequest::class => MockResponse::make([
        mapMeetupFixture(),
        // Historische GitHub-Daten liefern state je nach Meetup als Liste.
        mapMeetupFixture(['name' => 'Einundzwanzig Schweiz', 'state' => ['Bern', 'Zürich']]),
    ])]);

    $meetups = portalApi()->mapMeetups();

    expect($meetups)->toHaveCount(2)
        ->and($meetups->first())->toBeInstanceOf(MapMeetupData::class)
        ->and($meetups->first()->longitude)->toBe(9.146998)
        ->and($meetups->first()->next_event)->toBeInstanceOf(NextEventData::class)
        ->and($meetups->first()->next_event->start->format('Y-m-d H:i'))->toBe('2026-06-19 16:30')
        ->and($meetups->first()->next_event->attendees)->toBe(1)
        ->and($meetups->last()->state)->toBe(['Bern', 'Zürich']);
});

it('nests the dotted meetup keys of meetup events and filters by month', function () {
    withoutPortalToken();
    MockClient::global([GetMeetupEventsRequest::class => MockResponse::make([meetupEventFixture()])]);

    $events = portalApi()->meetupEvents('2022-12-01');

    $event = $events->first();
    expect($event)->toBeInstanceOf(MeetupEventData::class)
        ->and($event->start->format('Y-m-d H:i'))->toBe('2022-12-17 19:00')
        ->and($event->meetup)->toBeInstanceOf(EventMeetupData::class)
        ->and($event->meetup->name)->toBe('Einundzwanzig Franken')
        ->and($event->meetup->latitude)->toBe(49.589674);

    MockClient::global()->assertSent(fn (Request $request, Response $response): bool => str_ends_with(
        (string) $response->getPendingRequest()->getUri(),
        '/api/meetup-events/2022-12-01',
    ));
});

it('maps cities, venues and countries with their nested relations', function () {
    withoutPortalToken();
    MockClient::global([
        GetCitiesRequest::class => MockResponse::make([
            ['id' => 221, 'name' => 'Cottbus', 'country_id' => 1, 'country' => ['id' => 1, 'name' => 'Germany']],
        ]),
        GetVenuesRequest::class => MockResponse::make([
            [
                'id' => 131,
                'name' => 'AfueraFest 2025',
                'city_id' => 80,
                'flag' => 'https://portal.einundzwanzig.space/vendor/blade-flags/country-de.svg',
                'description' => 'Regensburg, ',
                'city' => [
                    'id' => 80,
                    'name' => 'Regensburg',
                    'country_id' => 1,
                    'country' => ['id' => 1, 'name' => 'Germany', 'code' => 'de'],
                ],
            ],
        ]),
        GetCountriesRequest::class => MockResponse::make([
            ['id' => 22, 'name' => 'Afghanistan', 'code' => 'af', 'flag' => 'https://portal.einundzwanzig.space/vendor/blade-flags/country-af.svg'],
        ]),
    ]);

    $api = portalApi();

    expect($api->cities()->first()->country->name)->toBe('Germany')
        ->and($api->venues()->first()->city->country->code)->toBe('de')
        ->and($api->countries()->first()->flag)->toContain('country-af.svg');
});

it('sends the bearer token from the keystore on authenticated requests', function () {
    withPortalToken('12|secrettoken');
    MockClient::global([GetUserRequest::class => MockResponse::make(userFixture())]);

    $user = portalApi()->user();

    expect($user)->toBeInstanceOf(UserProfileData::class)
        ->and($user->is_leader)->toBeTrue()
        ->and($user->is_lecturer)->toBeFalse();

    MockClient::global()->assertSent(fn (Request $request, Response $response): bool => $response->getPendingRequest()->headers()->get('Authorization') === 'Bearer 12|secrettoken');
});

it('sends no authorization header on public requests without a token', function () {
    withoutPortalToken();
    MockClient::global([GetCoursesRequest::class => MockResponse::make([courseFixture()])]);

    $courses = portalApi()->courses();

    expect($courses->first()->name)->toBe('Bitcoin, Blockchain und Geld');

    MockClient::global()->assertSent(fn (Request $request, Response $response): bool => $response->getPendingRequest()->headers()->get('Authorization') === null);
});

it('passes search parameters as query string', function () {
    withoutPortalToken();
    MockClient::global([GetCoursesRequest::class => MockResponse::make([])]);

    portalApi()->courses(search: 'bitcoin', userId: 7);

    MockClient::global()->assertSent(fn (Request $request, Response $response): bool => $response->getPendingRequest()->query()->all() === ['search' => 'bitcoin', 'user_id' => 7]);
});

it('unwraps the data wrapper of my-meetups and casts dates and booleans', function () {
    withPortalToken();
    MockClient::global([GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture()]])]);

    $meetups = portalApi()->myMeetups();

    $meetup = $meetups->first();
    expect($meetup)->toBeInstanceOf(MeetupData::class)
        ->and($meetup->is_active)->toBeTrue()
        ->and($meetup->visible_on_map)->toBeTrue()
        ->and($meetup->logo)->toBe('https://portal.einundzwanzig.space/storage/meetups/21/conversions/logo-thumb.jpg')
        ->and($meetup->last_event_at)->toBeInstanceOf(CarbonImmutable::class)
        ->and($meetup->last_event_at->toDateString())->toBe('2026-06-01');
});

it('maps my course events including course and venue summaries', function () {
    withPortalToken();
    MockClient::global([GetMyCourseEventsRequest::class => MockResponse::make(['data' => [courseEventFixture()]])]);

    $events = portalApi()->myCourseEvents(5);

    $event = $events->first();
    expect($event)->toBeInstanceOf(CourseEventData::class)
        ->and($event->from)->toBeInstanceOf(CarbonImmutable::class)
        ->and($event->course?->name)->toBe('Bitcoin, Blockchain und Geld')
        ->and($event->venue?->name)->toBe('Volkshochschule');

    MockClient::global()->assertSent(fn (Request $request, Response $response): bool => $response->getPendingRequest()->query()->get('course_id') === 5);
});

it('exposes the DTOs directly on the saloon response', function () {
    withoutPortalToken();
    MockClient::global([GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()])]);

    $dto = app(PortalConnector::class)->send(new GetMapMeetupsRequest)->dtoOrFail();

    expect($dto->first())->toBeInstanceOf(MapMeetupData::class);
});

it('serves the fresh cache without a second request', function () {
    withoutPortalToken();
    MockClient::global([MockResponse::make([courseFixture()])]);

    $api = portalApi();
    $api->courses();
    $api->courses();

    MockClient::global()->assertSentCount(1);
});

it('falls back to the stale copy when the portal returns errors', function () {
    withoutPortalToken();
    MockClient::global([
        MockResponse::make([courseFixture()]),
        MockResponse::make(['message' => 'Server Error'], 500),
        MockResponse::make(['message' => 'Server Error'], 500),
    ]);

    $api = portalApi();
    expect($api->courses())->toHaveCount(1);

    Cache::forget('portal_api:courses');

    $courses = $api->courses();

    expect($courses)->toHaveCount(1)
        ->and($courses->first()->name)->toBe('Bitcoin, Blockchain und Geld');

    MockClient::global()->assertSentCount(3);
});

it('serves stale data without a request when the device is offline', function () {
    withoutPortalToken();
    Network::shouldReceive('status')->andReturn((object) ['connected' => false]);
    Cache::forever('portal_api:courses:stale', [courseFixture()]);
    MockClient::global([]);

    $courses = portalApi()->courses();

    expect($courses)->toHaveCount(1)
        ->and($courses->first()->name)->toBe('Bitcoin, Blockchain und Geld');

    MockClient::global()->assertNothingSent();
});

it('returns empty results for my-data without a portal connection', function () {
    withoutPortalToken();
    MockClient::global([]);

    $api = portalApi();

    expect($api->myMeetups())->toBeEmpty()
        ->and($api->myCourseEvents())->toBeEmpty()
        ->and($api->myCourses())->toBeEmpty()
        ->and($api->user())->toBeNull();

    MockClient::global()->assertNothingSent();
});

it('maps detailed courses and sends the withDetails flag', function () {
    withoutPortalToken();
    MockClient::global([GetCoursesRequest::class => MockResponse::make([detailedCourseFixture()])]);

    $course = portalApi()->courses(withDetails: true)->first();

    expect($course)->toBeInstanceOf(CourseData::class)
        ->and($course->nextEvent()?->format('Y-m-d H:i'))->toBe('2026-07-01 18:00')
        ->and($course->lecturerOrNull()?->name)->toBe('Toni Stack')
        ->and($course->descriptionHtml())->toContain('<strong>Bitcoin</strong>');

    MockClient::global()->assertSent(fn (Request $request, Response $response): bool => $response->getPendingRequest()->query()->get('withDetails') === '1');
});

it('sends the withDetails flag for cities and venues and maps the flag url', function () {
    withoutPortalToken();
    MockClient::global([
        GetCitiesRequest::class => MockResponse::make([cityFixture()]),
        GetVenuesRequest::class => MockResponse::make([venueFixture()]),
    ]);

    $api = portalApi();

    expect($api->cities(withDetails: true)->first()->flag)->toContain('country-de.svg')
        ->and($api->venues(withDetails: true)->first()->description)->toBe('Regensburg, Hauptstraße 1');

    MockClient::global()->assertSentCount(2);
    MockClient::global()->assertSent(fn (Request $request, Response $response): bool => $response->getPendingRequest()->query()->get('withDetails') === '1');
});

it('maps the course detail including events with venue and city', function () {
    withoutPortalToken();
    MockClient::global([GetCourseRequest::class => MockResponse::make(courseDetailFixture())]);

    $course = portalApi()->course(5);

    expect($course)->toBeInstanceOf(CourseDetailData::class)
        ->and($course->portalLink)->toBe('https://portal.einundzwanzig.space/de/course/5')
        ->and($course->lecturer?->subtitleOrNull())->toBe('Bitcoin-Educator')
        ->and($course->events)->toHaveCount(1)
        ->and($course->events[0])->toBeInstanceOf(CourseEventData::class)
        ->and($course->events[0]->locationLabel())->toBe('Volkshochschule · Regensburg');

    MockClient::global()->assertSent(fn (Request $request, Response $response): bool => str_ends_with(
        (string) $response->getPendingRequest()->getUri(),
        '/api/courses/5',
    ));
});

it('returns null for an unknown course without flagging missing data', function () {
    withoutPortalToken();
    MockClient::global([GetCourseRequest::class => MockResponse::make(['message' => 'Not Found'], 404)]);

    $api = portalApi();

    // 404 ist eine verbindliche „existiert nicht“-Antwort, kein Verbindungsproblem.
    expect($api->course(999))->toBeNull()
        ->and($api->hasMissingData())->toBeFalse()
        ->and($api->servedStaleData())->toBeFalse();
});

it('keeps the status flags clear for fresh responses', function () {
    withoutPortalToken();
    MockClient::global([GetCoursesRequest::class => MockResponse::make([courseFixture()])]);

    $api = portalApi();
    $api->courses();

    expect($api->servedStaleData())->toBeFalse()
        ->and($api->hasMissingData())->toBeFalse();
});

it('flags served stale data after falling back to the stale copy', function () {
    withoutPortalToken();
    Cache::forever('portal_api:courses:stale', [courseFixture()]);
    MockClient::global([GetCoursesRequest::class => MockResponse::make([], 500)]);

    $api = portalApi();

    expect($api->courses())->toHaveCount(1)
        ->and($api->servedStaleData())->toBeTrue()
        ->and($api->hasMissingData())->toBeFalse();
});

it('flags missing data when a request fails without a stale copy', function () {
    withoutPortalToken();
    MockClient::global([GetCoursesRequest::class => MockResponse::make([], 500)]);

    $api = portalApi();

    expect($api->courses())->toBeEmpty()
        ->and($api->hasMissingData())->toBeTrue()
        ->and($api->servedStaleData())->toBeFalse();
});

it('clears the status flags on resetStatus', function () {
    withoutPortalToken();
    MockClient::global([GetCoursesRequest::class => MockResponse::make([], 500)]);

    $api = portalApi();
    $api->courses();

    expect($api->hasMissingData())->toBeTrue();

    $api->resetStatus();

    expect($api->hasMissingData())->toBeFalse()
        ->and($api->servedStaleData())->toBeFalse();
});

it('maps the lecturer profile with courses and social links', function () {
    withoutPortalToken();
    MockClient::global([GetLecturerRequest::class => MockResponse::make(lecturerDetailFixture())]);

    $lecturer = portalApi()->lecturer(3);

    expect($lecturer)->toBeInstanceOf(LecturerDetailData::class)
        ->and($lecturer->introHtml())->toContain('<strong>Bitcoin</strong>')
        ->and($lecturer->courses[0])->toBeInstanceOf(CourseData::class)
        ->and($lecturer->courses[0]->nextEvent()?->format('Y-m-d'))->toBe('2026-07-01')
        ->and($lecturer->socialLinks())->toBe([
            'Website' => 'https://tonistack.example',
            'X (Twitter)' => 'https://x.com/tonistack',
            'Nostr' => 'https://njump.me/npub1tonistack',
        ]);
});

it('loads my courses via the cached profile id', function () {
    withPortalToken();
    withCachedPortalProfile(['is_lecturer' => true]);
    MockClient::global([GetCoursesRequest::class => MockResponse::make([detailedCourseFixture()])]);

    $courses = portalApi()->myCourses();

    expect($courses)->toHaveCount(1);

    MockClient::global()->assertSent(fn (Request $request, Response $response): bool => $response->getPendingRequest()->query()->get('user_id') === 7
        && $response->getPendingRequest()->query()->get('withDetails') === '1');
});

it('returns no my-courses without a cached profile', function () {
    withPortalToken();
    MockClient::global([]);

    expect(portalApi()->myCourses())->toBeEmpty();

    MockClient::global()->assertNothingSent();
});
