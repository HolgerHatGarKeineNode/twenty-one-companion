<?php

use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMeetupEventsRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

/**
 * The ONE shell of this app (Concept C, P2).
 *
 * ── What this file measured until P2, and why it is a different file now ─────────────
 *
 * It measured the LEGACY shell: a five-tab bottom bar (Meetups · Termine · Karte · Profil
 * plus the chat takeover), a hamburger in the header opening a flyout with three grouped
 * navlists, and a launch page under `/` that read `localStorage['pubkey']` to decide between
 * chat and meetups. Its sibling `UnifiedShellTest` measured the OTHER state of the
 * `UNIFIED_SHELL` flag — two files for two shells, and a `beforeEach` in each of them
 * pinning the flag so the ambient `.env` could not flip the subject under the test.
 *
 * The flag is gone, `UnifiedShellTest` is deleted (pre-approved, D15), and there is one
 * shell: the package's three fixed slots, rendered by `<x-group::bottom-nav>` on these
 * Folio pages exactly as in the chat.
 *
 * ── What is checked here ────────────────────────────────────────────────────────────
 *
 * The things a server can decide: which slots the bar carries on an app page, that the
 * header lost its two buttons, that the search slot has something listening for it, and that
 * the FAB is still context-sensitive. The geometry is measured in the browser, not here.
 */
it('renders the three shared slots of the bottom bar on an app page', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
    ]);

    $html = (string) $this->get(route('meetups'))->assertOk()->getContent();

    // The bar is the PACKAGE's, not a copy: the same nav, the same anchor, the same labels.
    expect($html)->toContain('aria-label="Hauptnavigation"');
    expect($html)->toContain('data-bottom-nav');
    expect($html)->toContain('grid-cols-3');
    expect($html)->toContain('href="'.route('group.start').'"');
    expect($html)->toContain('href="'.route('group.postfach').'"');
    expect($html)->toContain('data-palette-open');

    // And the old five tabs are gone. Measured on the ROUTES and not on the labels: „Karte"
    // and „Termine" still exist as pages and as words on this screen — they are simply no
    // longer slots of the bar.
    $nav = mb_strstr($html, 'data-bottom-nav');
    expect($nav)->not->toBeFalse('marker data-bottom-nav missing — the narrowing would have no subject');
    $nav = (string) mb_strstr((string) $nav, '</nav>', true);

    expect($nav)->not->toContain(route('events'));
    expect($nav)->not->toContain(route('map'));
    expect($nav)->not->toContain(route('meetups'));
});

it('lost the magnifier and the hamburger from the header — one search, one place for "me"', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
    ]);

    $html = (string) $this->get(route('meetups'))->assertOk()->getContent();

    // Two search affordances on one screen were the drift this phase removes: the header
    // magnifier and the bar's centre slot asked the same question twelve pixels apart.
    expect($html)->not->toContain('aria-label="Menü"');
    expect(substr_count($html, 'data-modal="main-menu"'))->toBe(0);

    // The flyout's contents are not lost, they are pages: Start carries the areas, „Ich"
    // carries the identity, "Meine Inhalte" and the settings.
    expect($html)->toContain('href="'.route('group.start').'"');
});

it('has something listening for the search slot — the slot is not a dead button', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
    ]);

    // `<x-group::bottom-nav>` dispatches `open-command-palette`; the palette that listens for
    // it hangs in the package layout, which does not run on these Folio pages. The bridge in
    // `layouts/mobile.blade.php` opens this app's own search instead — and it survives P2 for
    // exactly that reason (see the note at the bridge). P4 replaces both with the real
    // palette.
    $html = (string) $this->get(route('meetups'))->assertOk()->getContent();

    expect($html)->toContain('x-on:open-command-palette.window');
    expect($html)->toContain('global-search');
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

    // On meetups: „Meetup aussuchen" (the discovery-first FAB), not „Termin anlegen".
    $this->get(route('meetups'))
        ->assertOk()
        ->assertSee(__('Meetup aussuchen'))
        ->assertDontSee(__('Termin anlegen'));

    // On dates: „Termin anlegen".
    $this->get(route('events'))
        ->assertOk()
        ->assertSee(__('Termin anlegen'));
});

it('renders a back link in the header on detail pages', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);

    $this->get(route('meetups.show', 'aschaffenburg'))
        ->assertOk()
        // Back navigation (phase 2.4): a chevron link to the index.
        ->assertSee(__('Zurück'))
        ->assertSee('/meetups');
});

it('sends the root to Start instead of serving a client-side launch switch', function () {
    // `launch.blade.php` stood here: a bare document that read `localStorage['pubkey']` in
    // its `<head>` and replaced the location with the chat or the meetups. Start answers the
    // same question without the detour — it renders for a guest and a member alike and
    // decides the difference in its own island (D4). A page whose whole content is a
    // redirect is a frame the user pays for and never sees.
    $this->get(route('home'))->assertRedirect(route('group.start'));
});

it('resolves /settings to the group settings hub, not the Laravel starter-kit login', function () {
    // The starter-kit `/settings` redirect (auth → Laravel login) was removed because it
    // collided with the package's settings route — the "Nostr identity" card used to land on
    // the Fortify login. `/settings` belongs to the package, and since P2 it forwards to the
    // hub's new address.
    $this->get('/settings')->assertRedirect('/ich/einstellungen');

    // Without a native runtime or a session the hub's SECTIONS gate client-side, so the hub
    // itself answers — and that is the point of D4: a guest may see what an account would
    // give him. The Laravel login (the collision bug) is nowhere near this path.
    $this->get(route('group.ich.einstellungen'))->assertOk();
});
