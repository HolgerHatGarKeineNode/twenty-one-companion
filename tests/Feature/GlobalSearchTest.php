<?php

use App\Http\Integrations\Portal\Requests\GetCoursesRequest;
use App\Http\Integrations\Portal\Requests\GetLecturersRequest;
use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Http\Response;

afterEach(fn () => MockClient::destroyGlobal());

it('does not search until at least two characters are entered', function () {
    withoutPortalToken();

    Livewire::test('global-search')
        ->assertSee(__('Tippe mindestens zwei Zeichen, um zu suchen.'))
        ->set('term', 'a')
        ->assertSee(__('Tippe mindestens zwei Zeichen, um zu suchen.'));
});

it('searches meetups, courses and lecturers and links to their detail pages', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture()]),
        GetLecturersRequest::class => MockResponse::make([detailedLecturerFixture()]),
    ]);

    Livewire::test('global-search')
        // Meetup-Treffer (Name/Stadt)
        ->set('term', 'asch')
        ->assertSee('Einundzwanzig Aschaffenburg')
        ->assertSee(route('meetups.show', 'aschaffenburg'))
        // Kurs-Treffer
        ->set('term', 'bitcoin')
        ->assertSee('Bitcoin, Blockchain und Geld')
        ->assertSee(route('courses.show', 5))
        // Referenten-Treffer
        ->set('term', 'toni')
        ->assertSee('Toni Stack')
        ->assertSee(route('lecturers.show', 3));
});

it('requests the uncapped course and lecturer lists so results beyond the first ten are searchable', function () {
    // Regression: das Portal begrenzt /courses und /lecturers ohne das
    // withDetails-Flag auf 10 Einträge. Die globale Suche muss die volle
    // Liste anfragen (withDetails=1), sonst übersieht sie alles ab dem 11.
    // Treffer (live verifiziert: „Nostr"-Kurse waren unauffindbar).
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture()]),
        GetLecturersRequest::class => MockResponse::make([detailedLecturerFixture()]),
    ]);

    Livewire::test('global-search')->set('term', 'bitcoin');

    $assertUncapped = fn (string $requestClass) => MockClient::global()->assertSent(
        fn (Request $request, Response $response): bool => $request instanceof $requestClass
            && $response->getPendingRequest()->query()->get('withDetails') === '1',
    );

    $assertUncapped(GetCoursesRequest::class);
    $assertUncapped(GetLecturersRequest::class);
});

it('shows an empty state when nothing matches', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture()]),
        GetLecturersRequest::class => MockResponse::make([detailedLecturerFixture()]),
    ]);

    Livewire::test('global-search')
        ->set('term', 'zzzznope')
        ->assertSee(__('Keine Treffer für „:term“.', ['term' => 'zzzznope']));
});
