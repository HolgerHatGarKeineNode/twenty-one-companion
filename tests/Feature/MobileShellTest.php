<?php

use App\Http\Integrations\Portal\Requests\GetCoursesRequest;
use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMeetupEventsRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupEventsRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupsRequest;
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

    $html = (string) $this->get(route('ich.inhalte'))->assertOk()->getContent();

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

    // Since P5 the three old paths are 302 rows (this app's Portal pages moved into the
    // package, D9) — they must not be slots of the bar either.
    expect($nav)->not->toContain(url('/events'));
    expect($nav)->not->toContain(url('/map'));
    expect($nav)->not->toContain(url('/meetups'));
});

it('lost the magnifier and the hamburger from the header — one search, one place for "me"', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
    ]);

    $html = (string) $this->get(route('ich.inhalte'))->assertOk()->getContent();

    // Two search affordances on one screen were the drift this phase removes: the header
    // magnifier and the bar's centre slot asked the same question twelve pixels apart.
    expect($html)->not->toContain('aria-label="Menü"');
    expect(substr_count($html, 'data-modal="main-menu"'))->toBe(0);

    // The flyout's contents are not lost, they are pages: Start carries the areas, „Ich"
    // carries the identity, "Meine Inhalte" and the settings.
    expect($html)->toContain('href="'.route('group.start').'"');
});

it('opens the ONE palette from the search slot — no second search any more', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
    ]);

    // Until P3 a BRIDGE stood here: `<x-group::bottom-nav>` dispatches
    // `open-command-palette`, the palette hangs in the package layout, and that layout did
    // not run on these pages — so a listener caught the event and opened this app's own
    // `global-search` instead. Two searches, twelve pixels apart, was the drift Concept C
    // removes (D6).
    //
    // P4 mounts the real palette here and deletes both the bridge and `global-search`. The
    // condition for that is one Vite JS entry (`resources/js/app.js` now registers
    // `nostrPalette`), so this case also pins that the entry did not fall apart again.
    $html = (string) $this->get(route('ich.inhalte'))->assertOk()->getContent();

    expect($html)->toContain('x-data="nostrPalette(');
    expect($html)->toContain('data-palette-input');
    // The second search is gone — and this is the assertion that keeps it gone: a re-added
    // bridge would be invisible without it. NOT asserted on
    // `x-on:open-command-palette.window`: the palette's own root carries exactly that
    // listener, so the string is (correctly) still there.
    expect($html)->not->toContain('global-search');
});

it('hides the create FAB for guests', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
    ]);

    $this->get(route('ich.inhalte'))
        ->assertOk()
        ->assertDontSee(__('Meetup aussuchen'));
});

it('shows the context-sensitive create FAB for connected users', function () {
    withPortalToken();
    withCachedPortalProfile();
    MockClient::global([
        GetMyMeetupsRequest::class => MockResponse::make(['data' => [myMeetupFixture(['is_leader' => true])]]),
        // „Meine Kurse" is the same endpoint as the course list, only with `user_id` —
        // that is how the hub counts one's own courses.
        GetCoursesRequest::class => MockResponse::make([]),
        GetMyMeetupEventsRequest::class => MockResponse::make(['data' => []]),
    ]);

    // The two contexts are the app's OWN pages since P5 — the package's Portal pages are
    // read-only (D9) and deliberately carry no FAB; their write path is the host block at
    // the end of the page.
    //
    // On „Meine Inhalte": „Meetup aussuchen" (the discovery-first FAB), not „Termin anlegen".
    $this->get(route('ich.inhalte'))
        ->assertOk()
        ->assertSee(__('Meetup aussuchen'))
        ->assertDontSee(__('Termin anlegen'));

    // On „Meine Termine": „Termin anlegen".
    $this->get(route('ich.inhalte.termine'))
        ->assertOk()
        ->assertSee(__('Termin anlegen'));
});

it('renders a back link in the header on detail pages', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture()]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);

    // A detail page of this app's own — the Portal detail is the package's since P4, and its
    // back link points at the package list (asserted there).
    $this->get(route('ich.inhalte.orte'))
        ->assertOk()
        // Back navigation (phase 2.4): a chevron link to the hub above.
        ->assertSee(__('Zurück'))
        ->assertSee('/ich/inhalte');
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
