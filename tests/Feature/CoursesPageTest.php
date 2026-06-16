<?php

use App\Http\Integrations\Portal\Requests\GetCourseRequest;
use App\Http\Integrations\Portal\Requests\GetCoursesRequest;
use App\Http\Integrations\Portal\Requests\GetLecturersRequest;
use Livewire\Livewire;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Share;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

function miningCourseFixture(): array
{
    return detailedCourseFixture([
        'id' => 8,
        'name' => 'Bitcoin Mining 101',
        'next_event' => null,
        'lecturer' => ['id' => 4, 'name' => 'Hash Rate', 'image' => ''],
    ]);
}

it('lists courses with upcoming events first', function () {
    withoutPortalToken();
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([miningCourseFixture(), detailedCourseFixture()]),
    ]);

    Livewire::test('pages::courses.index')
        ->assertSeeInOrder(['Bitcoin, Blockchain und Geld', 'Bitcoin Mining 101'])
        ->assertSee('Toni Stack')
        ->assertSee(route('courses.show', 5));
});

it('filters courses by course or lecturer name', function () {
    withoutPortalToken();
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture(), miningCourseFixture()]),
    ]);

    Livewire::test('pages::courses.index')
        ->set('search', 'mining')
        ->assertSee('Bitcoin Mining 101')
        ->assertDontSee('Blockchain und Geld')
        ->set('search', 'toni')
        ->assertSee('Blockchain und Geld')
        ->assertDontSee('Bitcoin Mining 101');
});

it('lists lecturers on the referenten tab with future event count', function () {
    withoutPortalToken();
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([]),
        GetLecturersRequest::class => MockResponse::make([detailedLecturerFixture()]),
    ]);

    Livewire::test('pages::courses.index')
        ->set('tab', 'referenten')
        ->assertSee('Toni Stack')
        ->assertSee('Bitcoin-Educator')
        ->assertSee('2 kommende Termine')
        ->assertSee(route('lecturers.show', 3));
});

it('lists lecturers with the soonest upcoming event first, then by name', function () {
    withoutPortalToken();
    // Toni (Default): früher Termin 2026-07-01, Hash: später Termin, Zoe: kein Termin.
    $hash = detailedLecturerFixture(['id' => 4, 'name' => 'Hash Rate', 'next_event' => '2026-09-01 18:00:00']);
    $zoe = detailedLecturerFixture(['id' => 5, 'name' => 'Aaron Zoe', 'next_event' => null, 'future_events_count' => 0]);

    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([]),
        GetLecturersRequest::class => MockResponse::make([$hash, $zoe, detailedLecturerFixture()]),
    ]);

    Livewire::test('pages::courses.index')
        ->set('tab', 'referenten')
        ->assertSeeInOrder(['Toni Stack', 'Hash Rate', 'Aaron Zoe']);
});

it('hides the my-courses tab for guests and non-lecturers', function () {
    withoutPortalToken();
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture()]),
    ]);

    Livewire::test('pages::courses.index')
        ->assertDontSee('Meine');
});

it('shows the own courses on the my-courses tab for lecturers', function () {
    withPortalToken();
    withCachedPortalProfile(['is_lecturer' => true]);
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture()]),
    ]);

    Livewire::test('pages::courses.index')
        ->assertSee('Meine')
        ->set('tab', 'meine')
        ->assertSee('Bitcoin, Blockchain und Geld');
});

it('renders the courses page over http', function () {
    withoutPortalToken();
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture()]),
    ]);

    $this->get(route('courses'))
        ->assertOk()
        ->assertSee('Bitcoin, Blockchain und Geld');
});

it('shows the course detail with events, description and lecturer', function () {
    withoutPortalToken();
    MockClient::global([
        GetCourseRequest::class => MockResponse::make(courseDetailFixture()),
    ]);

    Livewire::test('pages::courses.show', ['id' => 5])
        ->assertSee('Bitcoin, Blockchain und Geld')
        ->assertSee('Kommende Termine')
        ->assertSee('Volkshochschule · Regensburg')
        ->assertSee('Grundlagen zu')
        ->assertSee('Toni Stack')
        ->assertSee(route('lecturers.show', 3));
});

it('shows a friendly fallback for unknown courses', function () {
    withoutPortalToken();
    MockClient::global([
        GetCourseRequest::class => MockResponse::make(['message' => 'Not Found'], 404),
    ]);

    Livewire::test('pages::courses.show', ['id' => 999])
        ->assertSee('Kurs nicht gefunden');
});

it('shares the course link via the native share sheet', function () {
    withoutPortalToken();
    MockClient::global([
        GetCourseRequest::class => MockResponse::make(courseDetailFixture()),
    ]);

    Share::shouldReceive('url')->once()->withArgs(
        fn (string $title, string $text, string $url): bool => $title === 'Bitcoin, Blockchain und Geld'
            && $url === 'https://portal.einundzwanzig.space/de/course/5',
    );

    Livewire::test('pages::courses.show', ['id' => 5])
        ->call('share');
});

it('opens the event link in the system browser', function () {
    withoutPortalToken();
    MockClient::global([
        GetCourseRequest::class => MockResponse::make(courseDetailFixture()),
    ]);

    Browser::shouldReceive('open')->once()->with('https://example.com/kurs-anmeldung');

    Livewire::test('pages::courses.show', ['id' => 5])
        ->call('openLink', 'https://example.com/kurs-anmeldung');
});
