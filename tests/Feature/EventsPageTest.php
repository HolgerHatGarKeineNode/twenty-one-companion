<?php

use App\Http\Integrations\Portal\Requests\GetMeetupEventsRequest;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Native\Mobile\Facades\Share;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;
use Saloon\Http\Response;

afterEach(fn () => MockClient::destroyGlobal());

function upcomingEventFixtures(): array
{
    $tomorrow = CarbonImmutable::today()->addDay();

    return [
        meetupEventFixture(['start' => $tomorrow->setTime(19, 0)->format('Y-m-d H:i')]),
        meetupEventFixture([
            'start' => $tomorrow->setTime(20, 30)->format('Y-m-d H:i'),
            'location' => 'Wien',
            'description' => 'Stammtisch im Kaffeehaus',
            'meetup.name' => 'Einundzwanzig Wien',
            'meetup.portalLink' => 'https://portal.einundzwanzig.space/at/meetup/wien',
            'meetup.city' => 'Wien',
            'meetup.country' => 'AT',
        ]),
    ];
}

it('lists the upcoming events of the current month from today on', function () {
    withoutPortalToken();
    MockClient::global([
        GetMeetupEventsRequest::class => MockResponse::make(upcomingEventFixtures()),
    ]);

    Livewire::test('pages::events.index')
        ->assertSeeInOrder(['Einundzwanzig Franken', 'Einundzwanzig Wien'])
        ->assertSee('19:00')
        ->assertSee('20:30');

    MockClient::global()->assertSent(fn (Request $request, Response $response): bool => str_ends_with(
        (string) $response->getPendingRequest()->getUri(),
        '/api/meetup-events/'.CarbonImmutable::today()->toDateString(),
    ));
});

it('shows an empty state when no events are returned', function () {
    withoutPortalToken();
    MockClient::global([
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);

    Livewire::test('pages::events.index')
        ->assertSee('Keine Termine');
});

it('navigates to the next month and queries its first day', function () {
    withoutPortalToken();
    MockClient::global([
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);

    $nextMonth = CarbonImmutable::today()->startOfMonth()->addMonth();

    Livewire::test('pages::events.index')
        ->call('nextMonth')
        ->assertSet('month', $nextMonth->format('Y-m'))
        ->call('previousMonth')
        ->assertSet('month', '');

    MockClient::global()->assertSent(fn (Request $request, Response $response): bool => str_ends_with(
        (string) $response->getPendingRequest()->getUri(),
        '/api/meetup-events/'.$nextMonth->toDateString(),
    ));
});

it('opens the event details in a modal', function () {
    withoutPortalToken();
    MockClient::global([
        GetMeetupEventsRequest::class => MockResponse::make(upcomingEventFixtures()),
    ]);

    Livewire::test('pages::events.index')
        ->call('select', 1)
        ->assertSet('selected', 1)
        ->assertSet('showEvent', true)
        ->assertSee('Stammtisch im Kaffeehaus')
        ->assertSee(route('meetups.show', 'wien'));
});

it('ignores selecting an event index that does not exist', function () {
    withoutPortalToken();
    MockClient::global([
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);

    Livewire::test('pages::events.index')
        ->call('select', 5)
        ->assertSet('selected', null)
        ->assertSet('showEvent', false);
});

it('shares the selected event via the native share sheet', function () {
    withoutPortalToken();
    MockClient::global([
        GetMeetupEventsRequest::class => MockResponse::make(upcomingEventFixtures()),
    ]);

    Share::shouldReceive('url')->once()->withArgs(
        fn (string $title, string $text, string $url): bool => $title === 'Einundzwanzig Franken'
            && $url === 'https://t.me/Einundzwanzig_FRANKEN',
    );

    Livewire::test('pages::events.index')
        ->call('select', 0)
        ->call('share');
});

it('renders the events page over http', function () {
    withoutPortalToken();
    MockClient::global([
        GetMeetupEventsRequest::class => MockResponse::make(upcomingEventFixtures()),
    ]);

    $this->get(route('events'))
        ->assertOk()
        ->assertSee('Einundzwanzig Franken');
});
