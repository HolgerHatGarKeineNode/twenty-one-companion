<?php

use App\Http\Integrations\Portal\Requests\CreateCourseEventRequest;
use App\Http\Integrations\Portal\Requests\CreateMeetupEventRequest;
use App\Http\Integrations\Portal\Requests\GetCoursesRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupEventsRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupsRequest;
use App\Services\AppPreferences;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

afterEach(fn () => MockClient::destroyGlobal());

it('defaults to Europe/Berlin without a stored preference', function () {
    resetOnboarding();

    expect(app(AppPreferences::class)->timezone())->toBe('Europe/Berlin');
});

it('rejects an invalid timezone identifier', function () {
    withTimezone('Europe/Berlin');

    app(AppPreferences::class)->setTimezone('Not/AZone');

    expect(app(AppPreferences::class)->timezone())->toBe('Europe/Berlin');
});

it('converts a UTC instant into the user timezone for display', function () {
    withTimezone('Europe/Berlin');

    // Winter (UTC+1): 19:00 UTC → 20:00 Berlin.
    expect(Clock::toDisplay(CarbonImmutable::parse('2026-12-31 19:00', 'UTC'))->format('Y-m-d H:i'))
        ->toBe('2026-12-31 20:00');

    // Über den Tageswechsel: 23:30 UTC → 01:30 Berlin am Folgetag (Sommer, UTC+2).
    expect(Clock::toDisplay(CarbonImmutable::parse('2026-07-01 23:30', 'UTC'))->format('Y-m-d H:i'))
        ->toBe('2026-07-02 01:30');
});

it('converts local input back to UTC for the api', function () {
    withTimezone('Europe/Berlin');

    // Winter: 19:00 Berlin → 18:00 UTC.
    expect(Clock::localToUtc('2026-12-31 19:00'))->toBe('2026-12-31 18:00');
    // Sommer: 20:00 Berlin → 18:00 UTC.
    expect(Clock::localToUtc('2026-07-01 20:00'))->toBe('2026-07-01 18:00');
});

it('exposes a forDisplay carbon macro', function () {
    withTimezone('Europe/Berlin');

    expect(CarbonImmutable::parse('2026-12-31 19:00', 'UTC')->forDisplay()->format('H:i'))->toBe('20:00');
});

it('sends a meetup event start converted from the user timezone to utc', function () {
    withTimezone('Europe/Berlin');
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
        CreateMeetupEventRequest::class => MockResponse::make(myMeetupEventFixture(['id' => 99]), 201),
    ]);

    Livewire::test('event-editor')
        ->call('open')
        ->set('form.meetup_id', 21)
        ->set('form.date', '2026-12-31')
        ->set('form.time', '19:00')
        ->call('save')
        ->assertHasNoErrors();

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof CreateMeetupEventRequest
        && $request->body()->all()['start'] === '2026-12-31 18:00');
});

it('loads a meetup event into the editor converted to the user timezone', function () {
    withTimezone('Europe/Berlin');
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21])]]),
        GetMyMeetupEventsRequest::class => MockResponse::make(['data' => [
            myMeetupEventFixture(['id' => 55, 'meetup_id' => 21, 'start' => '2026-12-31T19:00:00.000000Z']),
        ]]),
    ]);

    Livewire::test('event-editor')
        ->call('open', 55)
        ->assertSet('form.date', '2026-12-31')
        ->assertSet('form.time', '20:00');
});

it('sends course event from/to converted from the user timezone to utc', function () {
    withTimezone('Europe/Berlin');
    withPortalToken();
    withCachedPortalProfile(['id' => 7, 'is_lecturer' => true]);
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture(['id' => 5])]),
        CreateCourseEventRequest::class => MockResponse::make(['id' => 99], 201),
    ]);

    Livewire::test('course-event-editor')
        ->call('open')
        ->set('form.course_id', 5)
        ->call('selectVenue', 3, 'Volkshochschule')
        ->set('form.date', '2030-01-01')
        ->set('form.from_time', '18:00')
        ->set('form.to_time', '20:00')
        ->set('form.link', 'https://example.com/anmeldung')
        ->call('save')
        ->assertHasNoErrors();

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof CreateCourseEventRequest
        && $request->body()->all()['from'] === '2030-01-01 17:00'
        && $request->body()->all()['to'] === '2030-01-01 19:00');
});

it('saves a chosen timezone from the profile page', function () {
    withoutPortalToken();

    Livewire::test('pages::profile.index')
        ->set('timezone', 'Europe/Zurich')
        ->assertHasNoErrors();

    expect(app(AppPreferences::class)->timezone())->toBe('Europe/Zurich');
});
