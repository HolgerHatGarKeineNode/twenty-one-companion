<?php

use App\Http\Integrations\Portal\Requests\GetMeetupEventRsvpRequest;
use App\Http\Integrations\Portal\Requests\GetMobileMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupsRequest;
use App\Http\Integrations\Portal\Requests\RemoveMeetupFromMineRequest;
use App\Http\Integrations\Portal\Requests\RsvpMeetupEventRequest;
use Livewire\Livewire;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;
use Saloon\Http\Request;

/**
 * The meetup surfaces of this app — AFTER the move (P5).
 *
 * ══ What changed, and why this file no longer looks the way it did ═══════════
 *
 * Until P4 this app had Portal pages of its own: `/meetups` with the tabs „Alle" and
 * „Meine", `/meetups/{slug}` as the detail page. The READING surfaces have lived in
 * the package since P4 (D9 — one stock, not two) and are read-only there; P5 deleted
 * the copies here. What is NOT reading could not stay there, because the package holds
 * no Portal token, and moved:
 *
 *   „Meine" (picker, editor, remove)  →  `/ich/inhalte/meetups`
 *   a leader's date management        →  `/ich/inhalte/termine` (EventsPageTest)
 *   the REST RSVP of a date           →  `<livewire:rest-rsvp>`, included by the
 *                                        package through `portal_rsvp_view` (D12/D15)
 *
 * So this file measures four things instead of one page:
 *   1. the moved write surface at its new address,
 *   2. that the old addresses lead there (302, query preserved),
 *   3. that the app's region is still the default of the country filter — behaviour
 *      that would have vanished with the deleted page, and silently,
 *   4. the REST RSVP that D15 expressly keeps.
 *
 * What the PACKAGE pages make of this app's data is in `PortalCatalogBindingTest`
 * (binding, map, host block, native affordances) and in the package repo itself
 * (`PortalSeitenTest`). A third place for it would be three truths about one list.
 */
afterEach(fn () => MockClient::destroyGlobal());

// ── 1. „Meine Meetups" at its new address ───────────────────────────────────

it('shows the own meetups with badge, edit and remove affordances', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['is_active' => true])]]),
    ]);

    Livewire::test('pages::mine.meetups')
        ->assertSeeText('Einundzwanzig Aschaffenburg')
        ->assertSee('Aktiv')
        ->assertSee('Meetup bearbeiten')
        ->assertSee('Aus „Meine“ entfernen')
        // The card leads to the PACKAGE page and not onto the 302 row: a detour of our
        // own through the redirect would be one round trip for nothing.
        ->assertSee(route('group.bereich.meetups.show', 'aschaffenburg'));
});

it('shows the discovery-first call to action when the own list is empty', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => []]),
    ]);

    Livewire::test('pages::mine.meetups')
        ->assertSee('Noch keine eigenen Meetups')
        // „aussuchen" primary, „neu anlegen" as the fallback — against duplicates.
        ->assertSee('Meetup aussuchen')
        ->assertSee('Neues Meetup anlegen');
});

it('asks for the portal connection instead of showing an empty page', function () {
    withoutPortalToken();

    Livewire::test('pages::mine.meetups')
        ->assertSee('Mit Portal verbinden')
        ->assertDontSee('Meetup aussuchen');
});

it('removes a meetup from mine by slug', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['slug' => 'aschaffenburg'])]]),
        RemoveMeetupFromMineRequest::class => MockResponse::make(['data' => myMeetupFixture(['slug' => 'aschaffenburg'])], 200),
    ]);

    Livewire::test('pages::mine.meetups')->call('removeFromMine', 'aschaffenburg');

    MockClient::global()->assertSent(fn (Request $request): bool => $request instanceof RemoveMeetupFromMineRequest
        && $request->resolveEndpoint() === '/my-meetups/aschaffenburg');
});

it('removes from mine after the native confirm button', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['slug' => 'aschaffenburg'])]]),
        RemoveMeetupFromMineRequest::class => MockResponse::make(['data' => myMeetupFixture(['slug' => 'aschaffenburg'])], 200),
    ]);

    Livewire::test('pages::mine.meetups')
        ->set('confirmKey', 'remove-from-mine')
        ->set('confirmPayload', ['slug' => 'aschaffenburg'])
        ->call('handleConfirmButton', 1, 'Entfernen', 'remove-from-mine');

    MockClient::global()->assertSent(RemoveMeetupFromMineRequest::class);
});

it('keeps the meetup when the native confirm is cancelled', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['slug' => 'aschaffenburg'])]]),
    ]);

    Livewire::test('pages::mine.meetups')
        ->set('confirmKey', 'remove-from-mine')
        ->set('confirmPayload', ['slug' => 'aschaffenburg'])
        ->call('handleConfirmButton', 0, 'Abbrechen', 'remove-from-mine');

    MockClient::global()->assertNotSent(RemoveMeetupFromMineRequest::class);
});

it('refreshes the own meetups after a save event', function () {
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture()]]),
    ]);

    Livewire::test('pages::mine.meetups')
        ->dispatch('meetup-saved')
        ->assertSeeText('Einundzwanzig Aschaffenburg');
});

it('renders the own meetups page over http', function () {
    completeOnboarding();
    withPortalToken();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture()]]),
    ]);

    $this->get(route('ich.inhalte.meetups'))
        ->assertOk()
        ->assertSee('Meine Meetups');
});

// ── 2. The old addresses (302, query preserved) ─────────────────────────────

it('forwards the old meetup list to the package page and keeps its filters', function () {
    completeOnboarding();

    // Without a query: the package's list.
    $this->get('/meetups')->assertRedirect('/bereich/meetups');

    // `country` is called `land` there — renamed, not dropped: the parameter stands in
    // shared links and in shipped app builds.
    // Order: `behalte` before `umbenenne`, the way the controller works through them.
    $this->get('/meetups?country=at&q=wien')
        ->assertRedirect('/bereich/meetups?q=wien&land=at');

    // `?tab=meine` was the WRITE surface and leaves the list entirely.
    $this->get('/meetups?tab=meine')->assertRedirect('/ich/inhalte/meetups');
});

it('forwards an old meetup detail link with its slug', function () {
    completeOnboarding();

    $this->get('/meetups/aschaffenburg')->assertRedirect('/bereich/meetups/aschaffenburg');
});

// ── 3. The app's region stays the default of the country filter ─────────────

it('opens the package meetup list on the app region', function () {
    // The behaviour of the deleted own list: whoever chose Austria during onboarding gets
    // Austria. Without the host default (`meetup_default_land`) it would have disappeared
    // with the page — silently.
    completeOnboarding(country: 'at');
    MockClient::global([
        GetMobileMeetupsRequest::class => MockResponse::make([
            mobileMeetupFixture(),
            mobileMeetupFixture(['name' => 'Einundzwanzig Wien', 'slug' => 'wien', 'city' => 'Wien', 'country' => 'AT']),
        ]),
    ]);

    Livewire::test('group::meetups')
        ->assertSet('land', 'at')
        ->assertSeeText('Einundzwanzig Wien')
        ->assertDontSeeText('Einundzwanzig Aschaffenburg');
});

it('lets a shared link and an emptied filter win over the app region', function () {
    completeOnboarding(country: 'at');
    MockClient::global([
        GetMobileMeetupsRequest::class => MockResponse::make([
            mobileMeetupFixture(),
            mobileMeetupFixture(['name' => 'Einundzwanzig Wien', 'slug' => 'wien', 'city' => 'Wien', 'country' => 'AT']),
        ]),
    ]);

    // A shared link carrying `?land=de`.
    Livewire::withQueryParams(['land' => 'de'])->test('group::meetups')
        ->assertSet('land', 'de')
        ->assertSeeText('Einundzwanzig Aschaffenburg');

    // And „Alle Länder" has to stay reachable: `?land=` is NOT a missing value.
    Livewire::withQueryParams(['land' => ''])->test('group::meetups')
        ->assertSet('land', '')
        ->assertSeeText('Einundzwanzig Wien')
        ->assertSeeText('Einundzwanzig Aschaffenburg');
});

// ── 4. The REST RSVP stays (D15) ────────────────────────────────────────────

it('hydrates the own REST rsvp status and offers the three answers', function () {
    withPortalToken();
    MockClient::global([
        GetMeetupEventRsvpRequest::class => MockResponse::make([
            'status' => 'maybe', 'attendees' => 3, 'might_attendees' => 2,
        ]),
    ]);

    Livewire::test('rest-rsvp', ['eventId' => 555])
        ->assertSet('rsvpStatus', 'maybe')
        ->assertSet('rsvpAttendees', 3)
        ->assertSet('rsvpMightAttendees', 2)
        ->assertSee(__('Ich komme'))
        ->assertSee(__('Vielleicht'))
        // „Kann nicht" only when the user currently answered yes or maybe.
        ->assertSee(__('Kann nicht'));
});

it('does not show the withdraw button when the user has not responded', function () {
    withPortalToken();
    MockClient::global([
        GetMeetupEventRsvpRequest::class => MockResponse::make([
            'status' => 'none', 'attendees' => 0, 'might_attendees' => 0,
        ]),
    ]);

    Livewire::test('rest-rsvp', ['eventId' => 555])
        ->assertSet('rsvpStatus', 'none')
        ->assertSee(__('Ich komme'))
        ->assertDontSee(__('Kann nicht'));
});

it('sends the REST rsvp and updates status and counts from the response', function () {
    withPortalToken();
    MockClient::global([
        GetMeetupEventRsvpRequest::class => MockResponse::make([
            'status' => 'none', 'attendees' => 1, 'might_attendees' => 0,
        ]),
        RsvpMeetupEventRequest::class => MockResponse::make([
            'status' => 'attending', 'attendees' => 2, 'might_attendees' => 0,
        ]),
    ]);

    Livewire::test('rest-rsvp', ['eventId' => 555])
        ->call('setRsvp', 'attending')
        ->assertSet('rsvpStatus', 'attending')
        ->assertSet('rsvpAttendees', 2)
        ->assertSee(__('Kann nicht'));
});

it('shows no REST rsvp buttons without a portal token', function () {
    withoutPortalToken();

    Livewire::test('rest-rsvp', ['eventId' => 555])
        ->assertSet('rsvpStatus', null)
        ->assertDontSee(__('Ich komme'));
});
