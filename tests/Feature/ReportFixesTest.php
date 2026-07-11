<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Blade;

/**
 * Regressionen aus dem E2E-Emulator-Report (plans/REPORT.md), Mobile-Seite.
 * Der authGate-Store (app.js) + die geteilten Package-Fixes prüft das Haupt-Repo
 * bzw. Playwright; hier: die Blade-/i18n-/Branding-testbaren Mobile-Fixes.
 */
afterEach(fn () => app()->setLocale('de'));

// Unified-4-Tab-Registry in die Runtime-Config schreiben (wie config/group.php sie
// aus env('UNIFIED_SHELL') ableitet) — nötig, damit die Bottom-Nav in den /more-
// Render-Tests die Tabs zeigt. Inline statt des file-lokalen enableUnifiedShell()
// aus UnifiedShellTest.
beforeEach(function () {
    config()->set('group.unified_shell', true);
    config()->set('group.exit', null);
    config()->set('group.nav', [
        ['key' => 'chat', 'route' => 'group.spaces', 'match' => 'group.spaces', 'icon' => 'chat-bubble-left-right', 'label' => 'Chat', 'gate' => 'nostr'],
        ['key' => 'wallet', 'route' => 'group.wallet', 'match' => 'group.wallet', 'icon' => 'bolt', 'label' => 'Wallet', 'gate' => 'nostr'],
        ['key' => 'meetups', 'route' => 'meetups', 'match' => 'meetups,meetups.show', 'icon' => 'calendar', 'label' => 'Meetups', 'gate' => 'guest'],
        ['key' => 'more', 'route' => 'more', 'match' => 'more,events,map,courses,mine,profile', 'icon' => 'squares-2x2', 'label' => 'Mehr', 'gate' => 'guest'],
    ]);
});

test('🔴 list-link-card mit navigate=false rendert einen harten Link (kein wire:navigate)', function () {
    // Cross-Bundle-Links (ins Chat-group.js) müssen hart laden, sonst bootet
    // group.js nicht via alpine:init (wire:navigate trägt den <head> mit).
    $hard = Blade::render('<x-list-link-card href="/x" :navigate="false">y</x-list-link-card>');
    expect($hard)->toContain('href="/x"')->and($hard)->not->toContain('wire:navigate');

    // Default bleibt SPA (wire:navigate) für In-Bundle-Navigation.
    $spa = Blade::render('<x-list-link-card href="/x">y</x-list-link-card>');
    expect($spa)->toContain('wire:navigate');
});

test('🔴 Mehr-Hub Sign-in-Karte führt als harter Load auf /nostr-login', function () {
    withoutPortalToken();

    $html = $this->get(route('more'))->assertOk()->getContent();

    // Den Anchor um den Login-Link isolieren und prüfen, dass er NICHT SPA-navigiert.
    preg_match('/<a\b[^>]*'.preg_quote(route('group.nostr-login'), '/').'[^>]*>/', $html, $m);
    expect($m)->not->toBeEmpty('Sign-in-Anchor nicht gefunden');
    expect($m[0])->not->toContain('wire:navigate');

    // Kontrolle: eine normale Entdecken-Karte navigiert weiterhin per SPA.
    preg_match('/<a\b[^>]*'.preg_quote(route('events'), '/').'[^>]*>/', $html, $ev);
    expect($ev)->not->toBeEmpty()
        ->and($ev[0])->toContain('wire:navigate');
});

test('🟠 Bottom-Nav-Label „Mehr" wird bei en-Locale zu „More" übersetzt', function () {
    withoutPortalToken();
    completeOnboarding(locale: 'en');

    // Vorbedingung: Key existiert (Locale hier explizit — die Middleware setzt sie
    // erst im HTTP-Request, nicht im Test-Body).
    app()->setLocale('en');
    expect(__('Mehr'))->toBe('More');

    // nav-tab rendert {{ __($label) }} → der Mehr-Tab zeigt im Request „More".
    $this->get(route('more'))->assertOk()->assertSee('More');
});

test('🟠 en.json trägt keine „TWENTY ONE"-Marke mehr (EINUNDZWANZIG im UI)', function () {
    $en = json_decode((string) file_get_contents(base_path('lang/en.json')), true);

    foreach ($en as $key => $value) {
        expect($value)->not->toContain('TWENTY ONE', "en.json-Wert für Key {$key} enthält noch TWENTY ONE");
    }
});
