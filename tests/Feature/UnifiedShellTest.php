<?php

use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMeetupEventsRequest;
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
