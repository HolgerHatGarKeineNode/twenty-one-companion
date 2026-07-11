<?php

use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMeetupEventsRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

it('serves a launch page that decides chat-vs-meetups client-side', function () {
    // Der Chat-Login lebt auf Mobile nur in localStorage (welshman), daher kann
    // der Server nicht per Redirect entscheiden — die Launch-Seite liest
    // localStorage['pubkey'] und leitet per JS in den Chat bzw. die Meetups.
    // @js() escaped die Slashes in den URLs (\/spaces), daher auf robuste
    // Teilstrings prüfen statt auf die vollen route()-URLs.
    $this->get(route('home'))
        ->assertOk()
        ->assertSee("localStorage.getItem('pubkey')", false)
        ->assertSee('window.location.replace', false)
        ->assertSee('spaces', false)
        ->assertSee('meetups', false);
});

it('shows the bottom navigation and the hamburger menu on a page', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
    ]);

    $this->get(route('meetups'))
        ->assertOk()
        // Bottom-Nav: Meetups / Termine / Karte / Profil
        ->assertSee(route('events'))
        ->assertSee(route('map'))
        ->assertSee(route('profile'))
        ->assertSee(__('Termine'))
        ->assertSee(__('Karte'))
        ->assertSee(__('Profil'))
        // Header: globale Suche (Phase 2.3)
        ->assertSee(__('Suche'))
        // Flyout gruppiert (Phase 2.5): Entdecken / Meine Inhalte / Einstellungen
        ->assertSee(__('Entdecken'))
        ->assertSee(__('Meine Inhalte'))
        ->assertSee(route('mine'))
        ->assertSee(route('courses'))
        ->assertSee(__('Kurse'))
        ->assertSee(__('Referenten'))
        ->assertSee(__('Städte & Orte'))
        ->assertSee(__('Einstellungen'));
});

it('hides the create FAB for guests', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
    ]);

    $this->get(route('meetups'))
        ->assertOk()
        ->assertDontSee(__('Meetup aussuchen'));
});

it('shows the context-sensitive create FAB for connected users', function () {
    withPortalToken();
    withCachedPortalProfile();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);

    // Auf Meetups: „Meetup aussuchen“ (Discovery-First-FAB), nicht „Termin anlegen“.
    $this->get(route('meetups'))
        ->assertOk()
        ->assertSee(__('Meetup aussuchen'))
        ->assertDontSee(__('Termin anlegen'));

    // Auf Termine: „Termin anlegen“.
    $this->get(route('events'))
        ->assertOk()
        ->assertSee(__('Termin anlegen'));
});

it('shows the connection status in the flyout for connected users', function () {
    withPortalToken();
    withCachedPortalProfile();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
    ]);

    $this->get(route('meetups'))
        ->assertOk()
        ->assertSee('Satoshi')
        ->assertSee(__('Mit Portal verbunden'));
});

it('renders a back link in the header on detail pages', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);

    $this->get(route('meetups.show', 'aschaffenburg'))
        ->assertOk()
        // Zurück-Navigation (Phase 2.4): Chevron-Link auf den Index.
        ->assertSee(__('Zurück'))
        ->assertSee('/meetups');
});

it('resolves /settings to the group settings, not the Laravel starter-kit login', function () {
    // Der Starter-Kit /settings-Redirect (auth → Laravel-Login) wurde entfernt, weil
    // er mit group.settings (/settings) kollidierte — die „Nostr-Identität"-Karte
    // landete sonst auf dem Fortify-Login. Jetzt gehört /settings dem Package.
    expect(url('/settings'))->toBe(route('group.settings'));

    // Ohne Native-Runtime/Session gatet EnsureNostrAuth auf den Nostr-Login —
    // NICHT auf den Laravel-Login (der Kollisions-Bug).
    $this->get(route('group.settings'))->assertRedirect(route('group.nostr-login'));
});
