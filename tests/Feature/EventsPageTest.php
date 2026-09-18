<?php

use App\Http\Integrations\Portal\Requests\GetMeetupEventsRequest;
use App\Http\Integrations\Portal\Requests\GetMobileMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupEventsRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupsRequest;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * The date surfaces of this app — AFTER the move (P5).
 *
 * ══ What `/events` was, and where it lives now ═══════════════════════════════
 *
 * `/events` was two things on one page: the LIST of every meetup's dates (reading) and,
 * through the slide-in, the REST RSVP. The list has lived in the package since P4
 * (`/bereich/meetups?ansicht=termine`, D9); since P5 the RSVP is the `rest-rsvp`
 * component (D12/D15, measured in `MeetupsPageTest`).
 *
 * A leader's date MANAGEMENT hung on this app's meetup detail page and is now
 * `/ich/inhalte/termine` — and better there than before: across all of one's meetups
 * instead of one detail page per meetup.
 *
 * Calendar export and sharing are the package's business since then, through the native
 * seam (`NativePortalAffordances`, measured in `PortalCatalogBindingTest`) — not twice.
 */
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

// ── 1. The package's Termine list, out of THIS app's data ──────────────────

it('lists the upcoming dates through the package view', function () {
    completeOnboarding();
    withoutPortalToken();
    MockClient::global([
        GetMeetupEventsRequest::class => MockResponse::make(upcomingEventFixtures()),
        // The Termine view builds its country filter from the same slim list as the list
        // view — one stock, one cache entry.
        GetMobileMeetupsRequest::class => MockResponse::make([mobileMeetupFixture()]),
    ]);

    Livewire::withQueryParams(['ansicht' => 'termine'])->test('group::meetups')
        ->assertSet('ansicht', 'termine')
        ->assertSeeTextInOrder(['Einundzwanzig Franken', 'Einundzwanzig Wien'])
        ->assertSee('19:00')
        ->assertSee('20:30');
});

it('applies the app region to the dates as well', function () {
    // The same promise as on the list: the region chosen during onboarding is the default,
    // here through the host key `meetup_default_land` (P5).
    completeOnboarding(country: 'at');
    withoutPortalToken();
    MockClient::global([
        GetMeetupEventsRequest::class => MockResponse::make(upcomingEventFixtures()),
        GetMobileMeetupsRequest::class => MockResponse::make([mobileMeetupFixture()]),
    ]);

    Livewire::withQueryParams(['ansicht' => 'termine'])->test('group::meetups')
        ->assertSet('land', 'at')
        ->assertSeeText('Einundzwanzig Wien')
        ->assertDontSeeText('Einundzwanzig Franken');
});

// ── 2. „Meine Termine" at its new address ───────────────────────────────────

it('lists the own dates of every meetup I lead, upcoming and past apart', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [
            myMeetupFixture(['id' => 21, 'slug' => 'aschaffenburg', 'is_leader' => true]),
        ]]),
        GetMyMeetupEventsRequest::class => MockResponse::make(['data' => [
            myMeetupEventFixture(['id' => 55, 'meetup_id' => 21, 'location' => 'Bitcoin-Bar Aschaffenburg']),
            myMeetupEventFixture(['id' => 40, 'meetup_id' => 21, 'start' => '2022-01-01T19:00:00.000000Z', 'location' => 'Altes Lokal']),
        ]]),
    ]);

    Livewire::test('pages::mine.events')
        ->assertSee(__('Termin anlegen'))
        ->assertSee('Bitcoin-Bar Aschaffenburg')
        ->assertSee(__('Vergangene Termine'))
        ->assertSee('Altes Lokal')
        // Every row carries its meetup: the dates of SEVERAL meetups stand below one
        // another here, and a date without its meetup does not say which one it is.
        ->assertSee('Einundzwanzig Aschaffenburg')
        ->assertSee(__('Termin bearbeiten'));
});

it('says why there is nothing to manage when I lead no meetup', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [
            myMeetupFixture(['id' => 21, 'is_leader' => false]),
        ]]),
    ]);

    // A button the API answers with a 403 would be worse than the sentence.
    Livewire::test('pages::mine.events')
        ->assertSee(__('Keine Termin-Verwaltung'))
        ->assertDontSee(__('Termin anlegen'));
});

it('refreshes the own dates after a save event', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [
            myMeetupFixture(['id' => 21, 'is_leader' => true]),
        ]]),
        GetMyMeetupEventsRequest::class => MockResponse::make(['data' => [
            myMeetupEventFixture(['id' => 55, 'meetup_id' => 21, 'location' => 'Bitcoin-Bar Aschaffenburg']),
        ]]),
    ]);

    Livewire::test('pages::mine.events')
        ->dispatch('meetup-event-saved')
        ->assertSee('Bitcoin-Bar Aschaffenburg');
});

it('asks for the portal connection instead of showing an empty page', function () {
    withoutPortalToken();

    Livewire::test('pages::mine.events')
        ->assertSee('Mit Portal verbinden')
        ->assertDontSee(__('Termin anlegen'));
});

it('renders the own dates page over http', function () {
    completeOnboarding();
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [
            myMeetupFixture(['id' => 21, 'is_leader' => true]),
        ]]),
        GetMyMeetupEventsRequest::class => MockResponse::make(['data' => [myMeetupEventFixture()]]),
    ]);

    $this->get(route('ich.inhalte.termine'))
        ->assertOk()
        ->assertSee('Meine Termine');
});

// ── 3. The old address ──────────────────────────────────────────────────────

it('forwards /events to the package date list and keeps the region', function () {
    completeOnboarding();

    $this->get('/events')->assertRedirect('/bereich/meetups?ansicht=termine');
    // `country` is called `land` there; the destination's own parameter (`ansicht`) comes
    // first and wins against anything the old address brings along.
    $this->get('/events?country=at')->assertRedirect('/bereich/meetups?ansicht=termine&land=at');
});
