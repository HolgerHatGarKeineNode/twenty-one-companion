<?php

use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMeetupEventsRequest;
use App\Models\User;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

it('redirects the start route to the meetups page', function () {
    $this->get(route('home'))->assertRedirect(route('meetups'));
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

it('redirects guests from settings to the login page', function () {
    $response = $this->get(route('settings'));

    $response->assertRedirect(route('login'));
});

it('redirects authenticated users from settings to the profile page', function () {
    $this->actingAs(User::factory()->create());

    $response = $this->get(route('settings'));

    $response->assertRedirect(route('profile.edit'));
});
