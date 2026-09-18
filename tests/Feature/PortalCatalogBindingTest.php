<?php

use App\Http\Integrations\Portal\Requests\GetCourseRequest;
use App\Http\Integrations\Portal\Requests\GetCoursesRequest;
use App\Http\Integrations\Portal\Requests\GetLecturerRequest;
use App\Http\Integrations\Portal\Requests\GetLecturersRequest;
use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMeetupEventsRequest;
use App\Http\Integrations\Portal\Requests\GetMobileMeetupsRequest;
use App\Portal\NativePortalAffordances;
use App\Portal\PortalApiCatalog;
use App\Services\PortalApi;
use Einundzwanzig\Group\Portal\PortalAffordances;
use Einundzwanzig\Group\Portal\PortalCatalog;
use Einundzwanzig\Group\Portal\PortalStatus;
use Illuminate\Support\Facades\Cache;
use Livewire\Component;
use Livewire\Livewire;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Share;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * The app's binding of the Portal seam (P4, D9) — `PortalApiCatalog` and
 * `NativePortalAffordances`.
 *
 * What is measured is exactly what DISTINGUISHES this binding from the web one, because that
 * is the only reason it exists:
 *
 *  1. **It overrides the package default.** A `bindIf` in the package means: whoever
 *     registers nothing here gets HTTP without a stale copy — on a device in a tunnel that
 *     would be an empty page.
 *  2. **It carries `PortalApi`'s offline state through.** Stale copy → `stale`, no data at
 *     all → `offline`. Without that translation the package page would not know whether
 *     "nothing found" or "nothing loaded" applies — and those two sentences are opposites.
 *  3. **Share/links/calendar run NATIVELY.** A Portal link strands in a WebView tab, and a
 *     messenger link has to reach its installed app.
 */
afterEach(fn () => MockClient::destroyGlobal());

beforeEach(function (): void {
    // NO `withoutPortalToken()` here: it mocks `SecureStorage::get`, and a second, later
    // expectation with the same arguments does NOT win in Mockery — a test that wants to
    // measure the connected case would silently get `null` back and be green for the wrong
    // state (exactly what happened before this line became a comment).
    Cache::flush();
});

it('overrides the package HTTP default', function () {
    expect(app(PortalCatalog::class))->toBeInstanceOf(PortalApiCatalog::class);
    expect(app(PortalAffordances::class))->toBeInstanceOf(NativePortalAffordances::class);
    // The app shares natively — the surface labels its button accordingly ("Teilen" instead
    // of "Link teilen").
    expect(app(PortalAffordances::class)->canShareNatively())->toBeTrue();
});

it('maps the lean meetup list into the package DTOs', function () {
    withoutPortalToken();
    MockClient::global([
        GetMobileMeetupsRequest::class => MockResponse::make([
            mobileMeetupFixture(),
            mobileMeetupFixture(['name' => 'Einundzwanzig Wien', 'slug' => 'wien', 'city' => 'Wien', 'country' => 'AT', 'next_event_start' => null]),
        ]),
    ]);

    $meetups = app(PortalCatalog::class)->meetups();

    expect($meetups)->toHaveCount(2);
    expect($meetups[0]->slug)->toBe('aschaffenburg');
    expect($meetups[0]->country)->toBe('DE');
    expect($meetups[0]->nextEventStart?->format('Y-m-d H:i'))->toBe('2026-06-19 16:30');
    expect($meetups[1]->nextEventStart)->toBeNull();
    // The deep link is derivable and is NOT carried from the payload — the same rule as in
    // the web binding (measured 2026-07-19 over all 304 meetups).
    expect($meetups[1]->portalLink('https://portal.test'))->toBe('https://portal.test/at/meetup/wien');
});

it('builds the meetup detail including the room derivation and the links', function () {
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture(['id' => 42, 'has_room' => true])]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);

    $detail = app(PortalCatalog::class)->meetup('aschaffenburg');

    expect($detail)->not->toBeNull();
    expect($detail->meetup->name)->toBe('Einundzwanzig Aschaffenburg');
    // `h = "m" + sha256(id)[:12]` — identical to the production creation and to the web binding.
    expect($detail->roomH())->toBe('m'.substr(hash('sha256', '42'), 0, 12));
    expect($detail->links)->toHaveKey(__('Telegram'));
    // An unknown slug is `null` and not an exception: every calling surface has to be able to
    // render something.
    expect(app(PortalCatalog::class)->meetup('gibt-es-nicht'))->toBeNull();
});

it('delivers dates ascending with their meetup flattened in', function () {
    withoutPortalToken();
    MockClient::global([
        GetMeetupEventsRequest::class => MockResponse::make([
            meetupEventFixture(['id' => 2, 'start' => '2026-07-05 19:00']),
            meetupEventFixture(['id' => 1, 'start' => '2026-07-01 18:00']),
        ]),
    ]);

    $events = app(PortalCatalog::class)->events('2026-07-01', '2026-07-31');

    expect(array_map(fn ($e) => $e->id, $events))->toBe([1, 2]);
    // The slug comes out of `meetup.portalLink` — the dates endpoint carries no slug field,
    // and without it the row could not point at its meetup.
    expect($events[0]->meetupSlug)->toBe('einundzwanzig-franken');
    expect($events[0]->meetupName)->toBe('Einundzwanzig Franken');
});

it('treats the Portal placeholder image as NO image', function () {
    withoutPortalToken();
    // For courses without an own image the Portal answers `/img/einundzwanzig.png` — a file
    // that does not exist (404). Passed through as an image, every such row would show a
    // broken picture instead of its initial.
    MockClient::global([
        GetCoursesRequest::class => MockResponse::make([
            detailedCourseFixture(['id' => 11, 'image' => 'https://portal.einundzwanzig.space/img/einundzwanzig.png']),
        ]),
    ]);

    expect(app(PortalCatalog::class)->courses()[0]->image)->toBeNull();
});

it('maps course and lecturer detail including the venue of a course date', function () {
    withoutPortalToken();
    MockClient::global([
        GetCourseRequest::class => MockResponse::make(courseDetailFixture()),
        GetLecturerRequest::class => MockResponse::make(lecturerDetailFixture()),
    ]);

    $kurs = app(PortalCatalog::class)->course(5);
    expect($kurs?->name)->toBe('Bitcoin, Blockchain und Geld');
    expect($kurs->lecturer?->name)->toBe('Toni Stack');
    expect($kurs->events[0]->location)->toBe('Volkshochschule · Regensburg');

    $referent = app(PortalCatalog::class)->lecturer(3);
    expect($referent?->lecturer->name)->toBe('Toni Stack');
    expect($referent->links)->not->toBeEmpty();
});

it('projects all four lists into the palette index', function () {
    withoutPortalToken();
    MockClient::global([
        GetMobileMeetupsRequest::class => MockResponse::make([mobileMeetupFixture()]),
        GetMeetupEventsRequest::class => MockResponse::make([meetupEventFixture(['start' => now()->addDays(3)->format('Y-m-d H:i')])]),
        GetCoursesRequest::class => MockResponse::make([detailedCourseFixture()]),
        GetLecturersRequest::class => MockResponse::make([detailedLecturerFixture()]),
    ]);

    $typen = collect(app(PortalCatalog::class)->search())->groupBy('type');

    expect($typen->keys()->all())->toBe(['meetup', 'event', 'course', 'lecturer']);
    // A date row points at its MEETUP: there is no page per date (D9).
    expect($typen['event']->first()->ref)->toBe('einundzwanzig-franken');
    expect(array_keys($typen['meetup']->first()->toIndexRow()))->toBe(['t', 'r', 'n', 's', 'd']);
});

it('reports the stale copy as stale and missing data as offline', function () {
    withoutPortalToken();
    // This is why this binding exists at all: in a tunnel the app shows the last copy AND
    // says so. First load …
    MockClient::global([
        GetMobileMeetupsRequest::class => MockResponse::make([mobileMeetupFixture()]),
    ]);
    expect(app(PortalCatalog::class)->meetups())->toHaveCount(1);
    expect(app(PortalCatalog::class)->status())->toBe(PortalStatus::Fresh);

    // … then go offline: the fresh entry has expired, the permanent stale copy answers, and
    // the status says "not up to date".
    MockClient::destroyGlobal();
    MockClient::global([
        GetMobileMeetupsRequest::class => MockResponse::make(['message' => 'down'], 500),
    ]);
    $this->travel(PortalApi::TTL_STATIC_SECONDS + 60)->seconds();
    app()->forgetScopedInstances();

    $stale = app(PortalCatalog::class);
    expect($stale->meetups())->toHaveCount(1);
    expect($stale->status())->toBe(PortalStatus::Stale);

    // Without any copy (fresh cache, dead Portal) it is `offline` — and the list is empty
    // rather than absent, so the page can show its error state.
    Cache::flush();
    app()->forgetScopedInstances();
    $offline = app(PortalCatalog::class);
    expect($offline->meetups())->toBe([]);
    expect($offline->status())->toBe(PortalStatus::Offline);
});

it('opens Portal links in the in-app browser and messenger links in the system browser', function () {
    // Mockery instead of a fake, as in `MeetupsPageTest` — the same promise moved into this
    // binding together with `PortalPage::openLink` and is measured on here.
    Browser::shouldReceive('inApp')->once()->with('https://portal.einundzwanzig.space/de/meetup/x');
    Browser::shouldReceive('open')->once()->with('https://t.me/einundzwanzig');

    $affordances = app(PortalAffordances::class);
    $seite = new class extends Component
    {
        public function render()
        {
            return '<div></div>';
        }
    };

    $affordances->openLink($seite, 'https://portal.einundzwanzig.space/de/meetup/x');
    $affordances->openLink($seite, 'https://t.me/einundzwanzig');
    // A foreign scheme: fails CLOSED, so NO further call. The URL comes from Portal data that
    // any meetup leader may edit — `intent:`/`nostrsigner:` would be intent injection. The
    // Mockery expectations above (`once`) are at the same time the check that nothing third
    // was called here.
    $affordances->openLink($seite, 'nostrsigner:sign?event=…');
});

it('shares through the native share sheet', function () {
    Share::shouldReceive('url')->once()->with(
        title: 'Einundzwanzig Aschaffenburg',
        text: 'Einundzwanzig Aschaffenburg am 19.06.2026',
        url: 'https://portal.einundzwanzig.space/de/meetup/aschaffenburg',
    );

    $seite = new class extends Component
    {
        public function render()
        {
            return '<div></div>';
        }
    };

    app(PortalAffordances::class)->share(
        $seite,
        'Einundzwanzig Aschaffenburg',
        'Einundzwanzig Aschaffenburg am 19.06.2026',
        'https://portal.einundzwanzig.space/de/meetup/aschaffenburg',
    );
});

it('renders the package meetup page WITH the app map', function () {
    withoutPortalToken();
    // The map is the one view only this chassis can bind
    // (`config('group.meetup_map_view')`): Leaflet + marker clustering are ~150 kB, and the
    // package is embedded by the association's web app for four chat views.
    MockClient::global([
        GetMobileMeetupsRequest::class => MockResponse::make([mobileMeetupFixture()]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);

    $html = (string) $this->get(route('group.bereich.meetups', ['ansicht' => 'karte']))->assertOk()->getContent();

    // One marker (the fixture has coordinates) and NO link-out into the Portal — that stands
    // only where no host binds a map (web).
    expect($html)->toContain('data-portal-karte="1"');
    expect($html)->not->toContain('data-meetups-karte-extern');
    // Leaflet arrives LAZILY: the view calls `window.loadLeaflet()` instead of carrying the
    // library in the main entry (where it sat up to P3 and was parsed on EVERY page).
    expect($html)->toContain('window.loadLeaflet()');
});

it('renders the host block on the meetup detail instead of a bare Portal link', function () {
    withoutPortalToken();
    // D9 knows exactly one exception to "read only": editing needs a Portal token, and only
    // this app has one. Without a connection a notice stands there and not a mute button.
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture(['id' => 42])]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);

    $html = (string) $this->get(route('group.bereich.meetups.show', 'aschaffenburg'))->assertOk()->getContent();

    expect($html)->toContain('data-portal-host-aktionen');
    expect($html)->toContain('data-portal-host-link');
    expect($html)->toContain('data-portal-host-hinweis');
    expect($html)->not->toContain('data-portal-host-editor');
});

it('shows a connected user the editors on the meetup detail', function () {
    withPortalToken();
    withCachedPortalProfile();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture(['id' => 42])]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);

    $html = (string) $this->get(route('group.bereich.meetups.show', 'aschaffenburg'))->assertOk()->getContent();

    expect($html)->toContain('data-portal-host-editor="meetup"');
    expect($html)->toContain('data-portal-host-editor="event"');
    // The sheets themselves are mounted here — otherwise the button would open a modal that
    // does not exist on this page (package layout) and would visibly do nothing.
    expect($html)->toContain('wire:snapshot');
    expect($html)->toContain('create-meetup');
});

it('the package page really USES the app affordances, not the web ones', function () {
    // The binding alone proves nothing: the page has to resolve it. A page that hard-wired
    // `WebPortalAffordances` would pass every test above and still open a Portal link in a
    // WebView tab instead of the in-app browser — measured as a mutation probe (P4), which is
    // why this case exists at all.
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture(['id' => 42])]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);
    Browser::shouldReceive('inApp')->once()->with('https://nordburgenland.test');

    Livewire::test('group::meetup', ['slug' => 'aschaffenburg'])
        ->call('openLink', 'https://nordburgenland.test')
        // The web arm would dispatch this event instead of calling the native browser.
        ->assertNotDispatched('group-open-link');
});

it('the package page labels its share button for the NATIVE sheet', function () {
    // Same seam, read from the other side: `nativeShare()` goes through the same binding, so
    // a hard-wired web arm would put „Link teilen" on a device that has a share sheet.
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([mapMeetupFixture(['id' => 42])]),
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);

    Livewire::test('group::meetup', ['slug' => 'aschaffenburg'])
        ->assertSee(__('Teilen'))
        ->assertDontSee(__('Link teilen'));
});
