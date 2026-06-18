<?php

use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMeetupEventRsvpRequest;
use App\Http\Integrations\Portal\Requests\GetMeetupEventsRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupEventsRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupsRequest;
use App\Http\Integrations\Portal\Requests\RemoveMeetupFromMineRequest;
use App\Http\Integrations\Portal\Requests\RsvpMeetupEventRequest;
use Livewire\Livewire;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Share;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

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

it('lists the map meetups with the soonest upcoming event first, then by name', function () {
    withoutPortalToken();
    // Wien: späterer Termin, Berlin: kein Termin, Aschaffenburg (Default): frühester Termin (2026-06-19).
    $wien = mapMeetupFixture([
        'name' => 'Einundzwanzig Wien',
        'city' => 'Wien',
        'country' => 'AT',
        'next_event' => ['id' => 1, 'start' => '2026-08-01T18:00:00.000000Z', 'portalLink' => 'x', 'location' => null, 'description' => null, 'link' => null, 'attendees' => 0, 'might_attendees' => 0, 'nostr_note' => ''],
    ]);
    $berlin = mapMeetupFixture(['name' => 'Einundzwanzig Berlin', 'city' => 'Berlin', 'next_event' => null]);

    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([$wien, $berlin, mapMeetupFixture()]),
    ]);

    Livewire::test('pages::meetups.index')
        ->assertSeeTextInOrder(['Einundzwanzig Aschaffenburg', 'Einundzwanzig Wien', 'Einundzwanzig Berlin'])
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
        ->assertSeeText('Einundzwanzig Wien')
        ->assertDontSeeText('Einundzwanzig Aschaffenburg')
        ->set('search', '')
        ->set('country', 'DE')
        ->assertSeeText('Einundzwanzig Aschaffenburg')
        ->assertDontSeeText('Einundzwanzig Wien');
});

it('applies the onboarding region as default country filter', function () {
    completeOnboarding(country: 'at');
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture(), viennaMeetupFixture()]),
    ]);

    Livewire::test('pages::meetups.index')
        ->assertSet('country', 'at')
        ->assertSeeText('Einundzwanzig Wien')
        ->assertDontSeeText('Einundzwanzig Aschaffenburg');
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
        ->assertSeeText('Einundzwanzig Aschaffenburg')
        ->assertSee(route('meetups.show', 'aschaffenburg'));
});

it('shows an edit affordance and status badge on own meetups', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([viennaMeetupFixture()]),
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['is_active' => true])]]),
    ]);

    Livewire::test('pages::meetups.index')
        ->set('tab', 'meine')
        ->assertSeeText('Einundzwanzig Aschaffenburg')
        ->assertSee('Aktiv')
        ->assertSee('Meetup bearbeiten');
});

it('shows a create call-to-action when the own meetups list is empty', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([viennaMeetupFixture()]),
        GetMyMeetupsRequest::class => MockResponse::make(['data' => []]),
    ]);

    Livewire::test('pages::meetups.index')
        ->set('tab', 'meine')
        ->assertSee('Noch keine eigenen Meetups')
        // Discovery-First (Phase 4.3): „aussuchen“ primär, „neu anlegen“ als Fallback.
        ->assertSee('Meetup aussuchen')
        ->assertSee('Neues Meetup anlegen');
});

it('shows a remove-from-mine affordance on own meetups', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([viennaMeetupFixture()]),
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture()]]),
    ]);

    Livewire::test('pages::meetups.index')
        ->set('tab', 'meine')
        ->assertSee('Aus „Meine“ entfernen');
});

it('removes a meetup from mine by slug', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([viennaMeetupFixture()]),
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['slug' => 'aschaffenburg'])]]),
        RemoveMeetupFromMineRequest::class => MockResponse::make(['data' => myMeetupFixture(['slug' => 'aschaffenburg'])], 200),
    ]);

    Livewire::test('pages::meetups.index')
        ->set('tab', 'meine')
        ->call('removeFromMine', 'aschaffenburg');

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof RemoveMeetupFromMineRequest
        && $request->resolveEndpoint() === '/my-meetups/aschaffenburg');
});

it('does not remove from mine without a portal token', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
    ]);

    Livewire::test('pages::meetups.index')
        ->call('removeFromMine', 'aschaffenburg');

    MockClient::global()->assertNotSent(RemoveMeetupFromMineRequest::class);
});

it('refreshes the own meetups after a save event', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([viennaMeetupFixture()]),
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture()]]),
    ]);

    Livewire::test('pages::meetups.index')
        ->set('tab', 'meine')
        ->dispatch('meetup-saved')
        ->assertSeeText('Einundzwanzig Aschaffenburg');
});

it('renders the meetups page over http', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
    ]);

    $this->get(route('meetups'))
        ->assertOk()
        ->assertSeeText('Einundzwanzig Aschaffenburg');
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
        ->assertSeeText('Einundzwanzig Aschaffenburg')
        ->assertSee('Nächster Termin')
        ->assertSee('Mainaschaff')
        ->assertSee('jeden Monat')
        ->assertSee('Telegram')
        ->assertSee('Website');
});

it('shows the own-event management section on the detail of an own meetup', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['id' => 21, 'slug' => 'aschaffenburg'])]]),
        GetMyMeetupEventsRequest::class => MockResponse::make(['data' => [
            myMeetupEventFixture(['id' => 55, 'meetup_id' => 21, 'location' => 'Bitcoin-Bar Aschaffenburg']),
            myMeetupEventFixture(['id' => 40, 'meetup_id' => 21, 'start' => '2022-01-01T19:00:00.000000Z', 'location' => 'Altes Lokal']),
        ]]),
        GetMeetupEventRsvpRequest::class => MockResponse::make(['status' => 'none', 'attendees' => 1, 'might_attendees' => 0]),
    ]);

    Livewire::test('pages::meetups.show', ['slug' => 'aschaffenburg'])
        ->assertSee(__('Meine Termine'))
        ->assertSee(__('Termin anlegen'))
        ->assertSee('Bitcoin-Bar Aschaffenburg')
        ->assertSee(__('Vergangene Termine'))
        ->assertSee('Altes Lokal')
        // Edit-Affordance fürs eigene Meetup (Phase 4.2 auf der Detail-Seite).
        ->assertSee(__('Bearbeiten'))
        ->assertSeeHtml("Livewire.dispatch('open-meetup-editor', { id: 21 })");
});

it('hides the own-event management section for non-owners', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
        GetMyMeetupsRequest::class => MockResponse::make(['data' => []]),
        GetMeetupEventRsvpRequest::class => MockResponse::make(['status' => 'none', 'attendees' => 1, 'might_attendees' => 0]),
    ]);

    Livewire::test('pages::meetups.show', ['slug' => 'aschaffenburg'])
        ->assertSeeText('Einundzwanzig Aschaffenburg')
        ->assertDontSee(__('Meine Termine'))
        ->assertDontSee(__('Bearbeiten'));
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

it('hides the rsvp buttons for the next event when not connected', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);

    Livewire::test('pages::meetups.show', ['slug' => 'aschaffenburg'])
        ->assertSee('Nächster Termin')
        ->assertDontSee(__('Ich komme'));
});

it('shows the rsvp buttons and hydrates the own status when connected', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
        GetMyMeetupsRequest::class => MockResponse::make(['data' => []]),
        GetMeetupEventRsvpRequest::class => MockResponse::make([
            'status' => 'maybe', 'attendees' => 3, 'might_attendees' => 2,
        ]),
    ]);

    Livewire::test('pages::meetups.show', ['slug' => 'aschaffenburg'])
        ->assertSet('rsvpStatus', 'maybe')
        ->assertSet('rsvpAttendees', 3)
        ->assertSet('rsvpMightAttendees', 2)
        ->assertSee(__('Ich komme'))
        ->assertSee(__('Vielleicht'))
        // „Kann nicht" nur, wenn der Nutzer aktuell zu-/vielleicht-gesagt hat.
        ->assertSee(__('Kann nicht'));
});

it('does not show the withdraw button when the user has not responded', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
        GetMyMeetupsRequest::class => MockResponse::make(['data' => []]),
        GetMeetupEventRsvpRequest::class => MockResponse::make([
            'status' => 'none', 'attendees' => 0, 'might_attendees' => 0,
        ]),
    ]);

    Livewire::test('pages::meetups.show', ['slug' => 'aschaffenburg'])
        ->assertSet('rsvpStatus', 'none')
        ->assertSee(__('Ich komme'))
        ->assertDontSee(__('Kann nicht'));
});

it('sends the rsvp and updates status and counts from the response', function () {
    withPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
        GetMyMeetupsRequest::class => MockResponse::make(['data' => []]),
        GetMeetupEventRsvpRequest::class => MockResponse::make([
            'status' => 'none', 'attendees' => 1, 'might_attendees' => 0,
        ]),
        RsvpMeetupEventRequest::class => MockResponse::make([
            'status' => 'attending', 'attendees' => 2, 'might_attendees' => 0,
        ]),
    ]);

    Livewire::test('pages::meetups.show', ['slug' => 'aschaffenburg'])
        ->assertSet('rsvpStatus', 'none')
        ->call('setRsvp', 'attending')
        ->assertSet('rsvpStatus', 'attending')
        ->assertSet('rsvpAttendees', 2)
        ->assertSee(__('Kann nicht'));
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
