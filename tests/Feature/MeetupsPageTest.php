<?php

use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMeetupEventsRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupsRequest;
use Livewire\Livewire;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Share;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

function viennaMeetupFixture(): array
{
    return mapMeetupFixture([
        'name' => 'Einundzwanzig Wien',
        'portalLink' => 'https://portal.einundzwanzig.space/at/meetup/wien',
        'country' => 'AT',
        'city' => 'Wien',
        'next_event' => null,
    ]);
}

it('lists the map meetups alphabetically with city and country', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([viennaMeetupFixture(), mapMeetupFixture()]),
    ]);

    Livewire::test('pages::meetups.index')
        ->assertSeeInOrder(['Einundzwanzig Aschaffenburg', 'Einundzwanzig Wien'])
        ->assertSee('Aschaffenburg · DE')
        ->assertSee('Wien · AT')
        ->assertSee(route('meetups.show', 'aschaffenburg'));
});

it('filters meetups by search term and country', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture(), viennaMeetupFixture()]),
    ]);

    Livewire::test('pages::meetups.index')
        ->set('search', 'wien')
        ->assertSee('Einundzwanzig Wien')
        ->assertDontSee('Einundzwanzig Aschaffenburg')
        ->set('search', '')
        ->set('country', 'DE')
        ->assertSee('Einundzwanzig Aschaffenburg')
        ->assertDontSee('Einundzwanzig Wien');
});

it('hides the my-meetups tab for guests', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
    ]);

    Livewire::test('pages::meetups.index')
        ->assertDontSee('Meine Meetups');

    MockClient::global()->assertSentCount(1);
});

it('shows the own meetups on the my-meetups tab when connected', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([viennaMeetupFixture()]),
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture()]]),
    ]);

    Livewire::test('pages::meetups.index')
        ->assertSee('Meine Meetups')
        ->set('tab', 'meine')
        ->assertSee('Einundzwanzig Aschaffenburg')
        ->assertSee(route('meetups.show', 'aschaffenburg'));
});

it('renders the meetups page over http', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
    ]);

    $this->get(route('meetups'))
        ->assertOk()
        ->assertSee('Einundzwanzig Aschaffenburg');
});

it('shows the meetup detail with next event, intro and links', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([
            mapMeetupFixture(['intro' => 'Wir treffen uns **jeden Monat**.', 'website' => 'https://aschaffenburg.example']),
        ]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);

    Livewire::test('pages::meetups.show', ['slug' => 'aschaffenburg'])
        ->assertSee('Einundzwanzig Aschaffenburg')
        ->assertSee('Nächster Termin')
        ->assertSee('Mainaschaff')
        ->assertSee('jeden Monat')
        ->assertSee('Telegram')
        ->assertSee('Website');
});

it('shows a friendly fallback for unknown meetup slugs', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
    ]);

    Livewire::test('pages::meetups.show', ['slug' => 'gibt-es-nicht'])
        ->assertSee('Meetup nicht gefunden');
});

it('shares the meetup link via the native share sheet', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);

    Share::shouldReceive('url')->once()->withArgs(
        fn (string $title, string $text, string $url): bool => $title === 'Einundzwanzig Aschaffenburg'
            && $url === 'https://portal.einundzwanzig.space/de/meetup/aschaffenburg',
    );

    Livewire::test('pages::meetups.show', ['slug' => 'aschaffenburg'])
        ->call('share');
});

it('opens external links in the system browser', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);

    Browser::shouldReceive('open')->once()->with('https://t.me/einundzwanzig_aschaffenburg');

    Livewire::test('pages::meetups.show', ['slug' => 'aschaffenburg'])
        ->call('openLink', 'https://t.me/einundzwanzig_aschaffenburg');
});

it('refuses to open links with non-http schemes', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);

    Browser::shouldReceive('open')->never();

    Livewire::test('pages::meetups.show', ['slug' => 'aschaffenburg'])
        ->call('openLink', 'nostrsigner:xyz');
});
