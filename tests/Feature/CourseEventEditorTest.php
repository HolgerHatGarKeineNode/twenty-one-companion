<?php

use App\Http\Integrations\Portal\Requests\CreateCourseEventRequest;
use App\Http\Integrations\Portal\Requests\GetCoursesRequest;
use App\Http\Integrations\Portal\Requests\GetMyCourseEventsRequest;
use App\Http\Integrations\Portal\Requests\GetVenuesRequest;
use App\Http\Integrations\Portal\Requests\UpdateCourseEventRequest;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

afterEach(fn () => MockClient::destroyGlobal());

it('creates a course event and assembles the from/to payload', function () {
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
        ->assertHasNoErrors()
        ->assertDispatched('teaching-changed')
        ->assertSet('editingId', null);

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof CreateCourseEventRequest
        && $request->body()->all()['course_id'] === 5
        && $request->body()->all()['venue_id'] === 3
        && $request->body()->all()['from'] === '2030-01-01 18:00'
        && $request->body()->all()['to'] === '2030-01-01 20:00'
        && $request->body()->all()['link'] === 'https://example.com/anmeldung');
});

it('requires course, venue, date, times and link before sending', function () {
    withPortalToken();
    withCachedPortalProfile(['id' => 7, 'is_lecturer' => true]);
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture(['id' => 5])]),
    ]);

    Livewire::test('course-event-editor')
        ->call('open')
        ->call('save')
        ->assertHasErrors([
            'form.course_id' => 'required',
            'form.venue_id' => 'required',
            'form.date' => 'required',
            'form.from_time' => 'required',
            'form.to_time' => 'required',
            'form.link' => 'required',
        ])
        ->assertNotDispatched('teaching-changed');
});

it('rejects an end time before the start time', function () {
    withPortalToken();
    withCachedPortalProfile(['id' => 7, 'is_lecturer' => true]);
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture(['id' => 5])]),
    ]);

    Livewire::test('course-event-editor')
        ->call('open')
        ->set('form.course_id', 5)
        ->call('selectVenue', 3, 'Volkshochschule')
        ->set('form.date', '2030-01-01')
        ->set('form.from_time', '20:00')
        ->set('form.to_time', '18:00')
        ->set('form.link', 'https://example.com/anmeldung')
        ->call('save')
        ->assertHasErrors(['form.to_time' => 'after'])
        ->assertNotDispatched('teaching-changed');
});

it('rejects a course event in the past when creating', function () {
    withPortalToken();
    withCachedPortalProfile(['id' => 7, 'is_lecturer' => true]);
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture(['id' => 5])]),
    ]);

    Livewire::test('course-event-editor')
        ->call('open')
        ->set('form.course_id', 5)
        ->call('selectVenue', 3, 'Volkshochschule')
        ->set('form.date', '2000-01-01')
        ->set('form.from_time', '18:00')
        ->set('form.to_time', '20:00')
        ->set('form.link', 'https://example.com/anmeldung')
        ->call('save')
        ->assertHasErrors('form.date')
        ->assertNotDispatched('teaching-changed');
});

it('searches venues for the picker from two characters', function () {
    withPortalToken();
    withCachedPortalProfile(['id' => 7, 'is_lecturer' => true]);
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture(['id' => 5])]),
        GetVenuesRequest::class => MockResponse::make([venueFixture(['name' => 'Volkshochschule'])]),
    ]);

    Livewire::test('course-event-editor')
        ->call('open')
        ->set('venueQuery', 'Volks')
        ->assertSee('Volkshochschule');
});

it('adopts a venue created inline via the venue-saved event', function () {
    withPortalToken();
    withCachedPortalProfile(['id' => 7, 'is_lecturer' => true]);
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture(['id' => 5])]),
    ]);

    Livewire::test('course-event-editor')
        ->call('open')
        ->dispatch('venue-saved', id: 3, name: 'Volkshochschule')
        ->assertSet('form.venue_id', 3)
        ->assertSet('form.venueName', 'Volkshochschule');
});

it('loads an own course event for editing and sends an update', function () {
    withPortalToken();
    withCachedPortalProfile(['id' => 7, 'is_lecturer' => true]);
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture(['id' => 5])]),
        GetMyCourseEventsRequest::class => MockResponse::make([myCourseEventFixture(['id' => 9, 'course_id' => 5, 'venue_id' => 3])]),
        UpdateCourseEventRequest::class => MockResponse::make(['id' => 9], 200),
    ]);

    Livewire::test('course-event-editor')
        ->call('open', 9)
        ->assertSet('editingId', 9)
        ->assertSet('form.course_id', 5)
        ->assertSet('form.venue_id', 3)
        ->assertSet('form.venueName', 'Volkshochschule')
        ->assertSet('form.date', '2026-07-01')
        ->assertSet('courseLocked', true)
        ->set('form.link', 'https://example.com/neu')
        ->call('save')
        ->assertHasNoErrors()
        ->assertDispatched('teaching-changed');

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof UpdateCourseEventRequest
        && $request->resolveEndpoint() === '/course-events/9'
        && $request->body()->all()['link'] === 'https://example.com/neu');
});

it('keeps the editor open and reports a 403 when editing a foreign course event', function () {
    withPortalToken();
    withCachedPortalProfile(['id' => 7, 'is_lecturer' => true]);
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture(['id' => 5])]),
        GetMyCourseEventsRequest::class => MockResponse::make([myCourseEventFixture(['id' => 9])]),
        UpdateCourseEventRequest::class => MockResponse::make(['message' => 'This action is unauthorized.'], 403),
    ]);

    Livewire::test('course-event-editor')
        ->call('open', 9)
        ->set('form.link', 'https://example.com/neu')
        ->call('save')
        ->assertNotDispatched('teaching-changed')
        ->assertSet('editingId', 9);
});

it('preselects and locks the course when opened for a specific course', function () {
    withPortalToken();
    withCachedPortalProfile(['id' => 7, 'is_lecturer' => true]);
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture(['id' => 5, 'name' => 'Bitcoin, Blockchain und Geld'])]),
    ]);

    Livewire::test('course-event-editor')
        ->call('open', null, 5)
        ->assertSet('form.course_id', 5)
        ->assertSet('courseLocked', true)
        ->assertSee('Bitcoin, Blockchain und Geld');
});

it('hints to create a course first when the user has none', function () {
    withPortalToken();

    Livewire::test('course-event-editor')
        ->call('open')
        ->assertSee(__('Lege zuerst einen eigenen Kurs an — Kurs-Events gehören immer zu einem Kurs.'));
});

it('does not send a write without a portal token', function () {
    withoutPortalToken();

    Livewire::test('course-event-editor')
        ->call('open')
        ->set('form.course_id', 5)
        ->call('selectVenue', 3, 'Volkshochschule')
        ->set('form.date', '2030-01-01')
        ->set('form.from_time', '18:00')
        ->set('form.to_time', '20:00')
        ->set('form.link', 'https://example.com/anmeldung')
        ->call('save')
        ->assertNotDispatched('teaching-changed');
});
