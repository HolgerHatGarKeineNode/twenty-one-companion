<?php

use App\Http\Integrations\Portal\Requests\GetCourseRequest;
use App\Http\Integrations\Portal\Requests\GetCoursesRequest;
use App\Http\Integrations\Portal\Requests\GetLecturersRequest;
use Livewire\Livewire;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Share;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * The course surfaces of this app — AFTER the move (P5).
 *
 * ══ What `/courses` was, and where it lives now ══════════════════════════════
 *
 * The list and the detail page have lived in the package since P4 (`/bereich/kurse`,
 * `/bereich/kurse/{id}`, D9); P5 deleted this app's copies. Two things on them were
 * not reading, and they moved:
 *
 *   tab „Meine" (one's own courses)  →  `/ich/inhalte/lehre` (MineTeachingTest)
 *   „Kurs-Event anlegen" on a course →  the host slot at the foot of the page
 *                                       (`partials/portal/detail-aktionen`)
 *
 * What is measured here is what THIS host contributes — its data, its native
 * affordances — and that the old addresses lead there, `?tab=` included.
 */
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

// ── 1. List and lecturers through the package page ──────────────────────────

it('lists courses with upcoming events first', function () {
    completeOnboarding();
    withoutPortalToken();
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([miningCourseFixture(), detailedCourseFixture()]),
    ]);

    Livewire::test('group::kurse')
        ->assertSeeInOrder(['Bitcoin, Blockchain und Geld', 'Bitcoin Mining 101'])
        ->assertSee('Toni Stack')
        ->assertSee(route('group.bereich.kurse.show', 5));
});

it('filters courses by course or lecturer name', function () {
    completeOnboarding();
    withoutPortalToken();
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture(), miningCourseFixture()]),
    ]);

    Livewire::test('group::kurse')
        ->set('suche', 'mining')
        ->assertSee('Bitcoin Mining 101')
        ->assertDontSee('Blockchain und Geld')
        ->set('suche', 'toni')
        ->assertSee('Blockchain und Geld')
        ->assertDontSee('Bitcoin Mining 101');
});

it('lists lecturers in the referenten view, soonest event first', function () {
    completeOnboarding();
    withoutPortalToken();
    // Toni (default): early date 2026-07-01, Hash: a later one, Zoe: no date at all.
    $hash = detailedLecturerFixture(['id' => 4, 'name' => 'Hash Rate', 'next_event' => '2026-09-01 18:00:00']);
    $zoe = detailedLecturerFixture(['id' => 5, 'name' => 'Aaron Zoe', 'next_event' => null, 'future_events_count' => 0]);

    MockClient::global([
        GetLecturersRequest::class => MockResponse::make([$hash, $zoe, detailedLecturerFixture()]),
    ]);

    Livewire::withQueryParams(['ansicht' => 'referenten'])->test('group::kurse')
        ->assertSeeInOrder(['Toni Stack', 'Hash Rate', 'Aaron Zoe'])
        ->assertSee('Bitcoin-Educator')
        ->assertSee(route('group.bereich.referenten.show', 3));
});

it('renders the courses page over http', function () {
    completeOnboarding();
    withoutPortalToken();
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture()]),
    ]);

    $this->get(route('group.bereich.kurse'))
        ->assertOk()
        ->assertSee('Bitcoin, Blockchain und Geld');
});

// ── 2. The course detail page ───────────────────────────────────────────────

it('shows the course detail with events, description and lecturer', function () {
    completeOnboarding();
    withoutPortalToken();
    MockClient::global([
        GetCourseRequest::class => MockResponse::make(courseDetailFixture()),
    ]);

    Livewire::test('group::kurs', ['id' => 5])
        ->assertSee('Bitcoin, Blockchain und Geld')
        ->assertSee('Volkshochschule · Regensburg')
        ->assertSee('Grundlagen zu')
        ->assertSee('Toni Stack')
        ->assertSee(route('group.bereich.referenten.show', 3));
});

it('answers 200 for a course whose dates carry no venue, as the live portal sends them', function (string $path) {
    // Device sighting v1.13.0: courses 6, 36 and 38 answered 500 with
    // `CannotCreateData … Parameters missing: venue_id`. The portal names the place
    // of a course date as `location` + `city` now; the fixture has that shape.
    completeOnboarding();
    withoutPortalToken();
    MockClient::global([
        GetCourseRequest::class => MockResponse::make(courseDetailFixture()),
    ]);

    expect(courseDetailFixture()['events'][0])->not->toHaveKey('venue_id');

    $this->followingRedirects()->get($path)
        ->assertOk()
        ->assertSee('Volkshochschule · Regensburg');
})->with(['/bereich/kurse/5', '/courses/5']);

it('shows a friendly fallback for unknown courses', function () {
    withoutPortalToken();
    MockClient::global([
        GetCourseRequest::class => MockResponse::make(['message' => 'Not Found'], 404),
    ]);

    Livewire::test('group::kurs', ['id' => 999])
        ->assertSee('Kurs nicht gefunden');
});

it('shares the course link via the native share sheet', function () {
    // This host's native seam carries the package page: sharing goes through the system
    // sheet, not through the web's `navigator.share`.
    completeOnboarding();
    withoutPortalToken();
    MockClient::global([
        GetCourseRequest::class => MockResponse::make(courseDetailFixture()),
    ]);

    Share::shouldReceive('url')->once()->withArgs(
        fn (string $title, string $text, string $url): bool => $title === 'Bitcoin, Blockchain und Geld'
            && $url === 'https://portal.einundzwanzig.space/de/course/5',
    );

    Livewire::test('group::kurs', ['id' => 5])->call('teilen');
});

it('opens the event link in the in-app browser', function () {
    completeOnboarding();
    withoutPortalToken();
    MockClient::global([
        GetCourseRequest::class => MockResponse::make(courseDetailFixture()),
    ]);

    Browser::shouldReceive('inApp')->once()->with('https://example.com/kurs-anmeldung');

    Livewire::test('group::kurs', ['id' => 5])
        ->call('openLink', 'https://example.com/kurs-anmeldung');
});

it('offers the course-event editor to a connected user', function () {
    // The one button that would have been lost with this app's deleted course page:
    // „Kurs-Event anlegen". Since P5 it stands in the host slot at the foot of the page —
    // otherwise the only way there would have been through „Ich › Meine Inhalte".
    completeOnboarding();
    withPortalToken();
    MockClient::global([
        GetCourseRequest::class => MockResponse::make(courseDetailFixture()),
    ]);

    Livewire::test('group::kurs', ['id' => 5])
        ->assertSee('data-portal-host-editor="course-event"', false)
        ->assertSee(__('Kurs-Event anlegen'));
});

// ── 3. The old addresses ────────────────────────────────────────────────────

it('forwards the old course addresses, including the two tabs', function () {
    completeOnboarding();

    $this->get('/courses')->assertRedirect('/bereich/kurse');
    $this->get('/courses/5')->assertRedirect('/bereich/kurse/5');
    // `tab=referenten` is called `ansicht=referenten` there …
    $this->get('/courses?tab=referenten')->assertRedirect('/bereich/kurse?ansicht=referenten');
    // … and `tab=meine` was the write surface and leaves the list entirely.
    $this->get('/courses?tab=meine')->assertRedirect('/ich/inhalte/lehre');
});
