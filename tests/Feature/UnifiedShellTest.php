<?php

use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMeetupEventsRequest;
use Illuminate\Support\Str;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/*
 * P3 (App-Shell-Verschmelzung §3.2): der Feature-Flag UNIFIED_SHELL schaltet die
 * verschmolzene 4-Tab-Shell. Diese Suite prüft den EIN-Zustand; die Legacy-Nav
 * (Flag aus = Default) deckt MobileShellTest ab.
 */

afterEach(fn () => MockClient::destroyGlobal());

/**
 * Die 4-Tab-Registry (§3.2) explizit setzen — wie config/group.php sie im
 * Unified-Modus aus env('UNIFIED_SHELL') ableitet. Damit die Render-Tests nicht
 * am env-Cache hängen, wird sie hier direkt in die Runtime-Config geschrieben.
 */
function enableUnifiedShell(): void
{
    config()->set('group.unified_shell', true);
    config()->set('group.exit', null);
    config()->set('group.nav', [
        ['key' => 'chat', 'route' => 'group.spaces', 'match' => 'group.spaces,group.directory,group.room,group.space.settings,group.join', 'icon' => 'chat-bubble-left-right', 'label' => 'Chat', 'gate' => 'nostr'],
        ['key' => 'wallet', 'route' => 'group.wallet', 'match' => 'group.wallet', 'icon' => 'bolt', 'label' => 'Wallet', 'gate' => 'nostr'],
        ['key' => 'meetups', 'route' => 'meetups', 'match' => 'meetups,meetups.show', 'icon' => 'calendar', 'label' => 'Meetups', 'gate' => 'guest'],
        ['key' => 'more', 'route' => 'more', 'match' => 'more,events,map,courses,courses.show,lecturers.show,mine,mine.places,mine.teaching,profile', 'icon' => 'squares-2x2', 'label' => 'Mehr', 'gate' => 'guest'],
    ]);
}

it('derives the 4-tab nav and drops the exit link when UNIFIED_SHELL is on', function () {
    $_SERVER['UNIFIED_SHELL'] = $_ENV['UNIFIED_SHELL'] = 'true';
    putenv('UNIFIED_SHELL=true');

    $config = require config_path('group.php');

    expect($config['unified_shell'])->toBeTrue()
        ->and($config['exit'])->toBeNull()
        ->and($config['nav'])->toHaveCount(4)
        ->and(collect($config['nav'])->pluck('key')->all())->toBe(['chat', 'wallet', 'meetups', 'more']);

    unset($_SERVER['UNIFIED_SHELL'], $_ENV['UNIFIED_SHELL']);
    putenv('UNIFIED_SHELL');
});

it('keeps the exit link and the package-default nav when the flag is off', function () {
    $_SERVER['UNIFIED_SHELL'] = $_ENV['UNIFIED_SHELL'] = 'false';
    putenv('UNIFIED_SHELL=false');

    $config = require config_path('group.php');

    expect($config['unified_shell'])->toBeFalse()
        ->and($config['exit'])->toBe(['route' => 'meetups', 'label' => 'Meetups'])
        ->and($config)->not->toHaveKey('nav');

    unset($_SERVER['UNIFIED_SHELL'], $_ENV['UNIFIED_SHELL']);
    putenv('UNIFIED_SHELL');
});

it('renders the unified 4-tab shell instead of the 5-tab nav on a page', function () {
    enableUnifiedShell();
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
    ]);

    $this->get(route('meetups'))
        ->assertOk()
        // Die 4 Shell-Tabs (Chat · Wallet · Meetups · Mehr).
        ->assertSee(route('group.spaces'))
        ->assertSee(route('group.wallet'))
        ->assertSee(route('more'))
        ->assertSee('Chat')
        ->assertSee('Wallet')
        ->assertSee('Mehr')
        // Aktiv-State des eigenen Tabs (Meetups hat Multi-Route-`match`
        // "meetups,meetups.show" → routeIs muss die Kommas splitten, sonst nie aktiv).
        ->assertSee('aria-current="page"', false)
        // Die aus der Bottom-Nav verdrängten Tabs + der Hamburger sind weg —
        // sie leben jetzt im „Mehr"-Hub (§3.4).
        ->assertDontSee(__('Menü'))
        ->assertDontSee(__('Karte'));
});

it('wires the shell magnifier to the app search on folio pages', function () {
    // Die Lupe der geteilten Shell schickt `open-command-palette`; die Palette,
    // die darauf hört, hängt im Layout des group-Pakets und läuft auf diesen
    // Seiten nicht mit. Ohne Brücke wäre der Knopf hier tot.
    enableUnifiedShell();
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
    ]);

    $this->get(route('meetups'))
        ->assertOk()
        ->assertSee('open-command-palette', false)
        ->assertSee("\$flux.modal('global-search').show()", false);
});

it('leaves the bridge out of the legacy shell, which has no magnifier tab', function () {
    // .env dieser App hat UNIFIED_SHELL=true, der Testlauf erbt das — der
    // Legacy-Zustand muss hier ausdrücklich hergestellt werden.
    config()->set('group.unified_shell', false);
    withoutPortalToken();
    MockClient::global([
        GetMapMeetupsRequest::class => MockResponse::make([]),
    ]);

    $this->get(route('meetups'))
        ->assertOk()
        ->assertDontSee('open-command-palette', false);
});

it('activates a tab via multi-route match (Mehr on a discover sub-route)', function () {
    // /events ist kein eigener Tab, sondern Teil des Mehr-`match`. Vor dem
    // explode-Fix in nav-tab matchte der Komma-String nie → kein Aktiv-Tab.
    enableUnifiedShell();
    withoutPortalToken();
    MockClient::global([
        GetMeetupEventsRequest::class => MockResponse::make([]),
    ]);

    $this->get(route('events'))
        ->assertOk()
        ->assertSee('aria-current="page"', false);
});

it('renders the More hub with discover, my-content and settings sections', function () {
    enableUnifiedShell();
    withoutPortalToken();

    $this->get(route('more'))
        ->assertOk()
        ->assertSee(__('Entdecken'))
        ->assertSee(__('Meine Inhalte'))
        ->assertSee(__('Einstellungen'))
        // Entdecken verlinkt die Gast-lesbaren Bereiche.
        ->assertSee(route('events'))
        ->assertSee(route('map'))
        ->assertSee(route('courses'))
        ->assertSee(route('mine'))
        ->assertSee(route('profile'));
});

it('shows the login CTA in the More hub for guests', function () {
    enableUnifiedShell();
    withoutPortalToken();

    $this->get(route('more'))
        ->assertOk()
        ->assertSee(__('Anmelden'))
        ->assertSee(route('group.nostr-login'));
});

/*
 * Wächter gegen den Defekt, der P2–P7 begleitet hat: das Paket legt Screens an
 * (`/updates`, `/bookmarks`, `/articles`, `/forge`, `/rooms/{h}/thread/{nevent}`),
 * der Host führt sie aber in keinem Tab-`match` — dort leuchtet dann KEIN Tab
 * (`nav-tab.blade.php`: `routeIs(...explode(','))`, alle vier false, kein Fehler).
 * Am teuersten beim Thread-Deep-Link aus einer Push-Notification: Kaltstart auf
 * `group.room.thread`, und die Bar sagt nicht, wo man ist.
 *
 * Der Test liest die ECHTE `config/group.php` statt der Registry aus
 * `enableUnifiedShell()` — die ist eine Kopie und driftet (sie trägt noch
 * `group.space.settings`, das es als Screen nicht mehr gibt).
 */
it('marks every chat-side package screen with a tab', function () {
    putenv('UNIFIED_SHELL=true');
    $_ENV['UNIFIED_SHELL'] = 'true';
    $_SERVER['UNIFIED_SHELL'] = 'true';

    $nav = (require config_path('group.php'))['nav'] ?? [];
    expect($nav)->not->toBeEmpty();

    $patterns = collect($nav)->flatMap(fn (array $tab) => explode(',', $tab['match'] ?? $tab['route']))->all();
    $matched = fn (string $name) => collect($patterns)->contains(fn (string $p) => Str::is($p, $name));

    /*
     * Jeder GET-Screen des Pakets, der hinter einem Tab liegt. Bewusst NICHT
     * dabei — mit Grund, damit ein künftiger Screen hier auffällt statt still
     * durchzufallen:
     *   group.nostr-login, group.verein.join   Interstitials ohne Bottom-Nav
     *   group.space.settings, group.verein.return  Redirects, keine Fläche
     *   group.nostr.*, group.locale            keine Screens (XHR/POST)
     */
    $chatSide = [
        'group.spaces', 'group.room', 'group.room.thread', 'group.directory',
        'group.updates', 'group.bookmarks', 'group.settings', 'group.join',
        'group.articles', 'group.article', 'group.articles.author',
        'group.forge', 'group.forge.repo', 'group.forge.issue', 'group.forge.pull',
    ];

    foreach ($chatSide as $name) {
        expect($matched($name))->toBeTrue("Screen [$name] wird von keinem Tab-`match` getroffen");
    }

    // Anwesenheits-Zusicherung neben der Abwesenheits-Zusicherung: das Muster
    // trifft nicht einfach alles. Der Wallet-Tab ist ein eigener Eintrag, und
    // eine Host-Route darf nicht am Chat-Tab hängen.
    expect($matched('group.wallet'))->toBeTrue()
        ->and(collect(explode(',', $nav[0]['match']))->contains(fn ($p) => Str::is($p, 'group.wallet')))->toBeFalse()
        ->and(collect(explode(',', $nav[0]['match']))->contains(fn ($p) => Str::is($p, 'meetups')))->toBeFalse();
});

/*
 * ── „Einstellungen" heißt in dieser App /profile, nicht /settings ────────────────
 *
 * P6 hat Portal-Prefs und Nostr-Sektionen auf EINEN Screen verschmolzen (`/profile`,
 * belegt in `ProfilePageTest`). Die package-eigene Route `group.settings` existiert
 * weiter und rendert eine ZWEITE, dünnere Fassung derselben Sektionen — die
 * Befehlspalette und der Profil-Chip auf `/spaces` führten genau dorthin, während der
 * „Mehr"-Hub auf `/profile` zeigt. Zwei Orte für eine Sache, je nach Weg.
 *
 * `group.settings_route` ist die Config-Zeile, die das entscheidet (gleiche Bauform wie
 * `group.exit`). Sie wird von zwei Lesern im Paket ausgewertet.
 */
it('names /profile as the settings destination, in both modes', function () {
    foreach (['true', 'false'] as $flag) {
        putenv("UNIFIED_SHELL=$flag");
        $_ENV['UNIFIED_SHELL'] = $_SERVER['UNIFIED_SHELL'] = $flag;

        $config = require config_path('group.php');

        // Nicht vom Flag abhängig: die verschmolzenen Einstellungen gibt es in beiden
        // Modi, und der Chat-Client rendert den Chip in beiden.
        expect($config['settings_route'] ?? null)->toBe('profile', "UNIFIED_SHELL=$flag");
    }

    unset($_ENV['UNIFIED_SHELL'], $_SERVER['UNIFIED_SHELL']);
    putenv('UNIFIED_SHELL');

    // Und die Route existiert wirklich. `route()` wirft sonst erst zur Render-Zeit,
    // also auf der Seite des Nutzers statt hier.
    expect(route(config('group.settings_route')))->toBe(route('profile'));
});

it('keeps /profile on the "Mehr" tab — the settings screen is not a chat screen', function () {
    putenv('UNIFIED_SHELL=true');
    $_ENV['UNIFIED_SHELL'] = $_SERVER['UNIFIED_SHELL'] = 'true';

    $nav = (require config_path('group.php'))['nav'] ?? [];
    $matchOf = fn (string $key) => collect($nav)->firstWhere('key', $key)['match'] ?? '';

    // Der Profil-Chip im Chat verlinkt jetzt auf `/profile`. Der Tab, der dort
    // leuchtet, muss „Mehr" bleiben und nicht „Chat" werden — sonst behauptete die
    // Nav, man sei noch im Chat, obwohl man den Bereich verlassen hat.
    expect(collect(explode(',', $matchOf('more')))->contains('profile'))->toBeTrue();
    expect(collect(explode(',', $matchOf('chat')))->contains('profile'))->toBeFalse();

    unset($_ENV['UNIFIED_SHELL'], $_SERVER['UNIFIED_SHELL']);
    putenv('UNIFIED_SHELL');
});
