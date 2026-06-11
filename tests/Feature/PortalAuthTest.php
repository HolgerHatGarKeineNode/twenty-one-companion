<?php

use App\Services\PortalAuth;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\SecureStorage;

it('stores the token from the deep link callback and redirects home', function () {
    SecureStorage::shouldReceive('set')
        ->once()
        ->with('portal_api_token', '12|secrettoken')
        ->andReturnTrue();

    $this->get('/auth?token='.urlencode('12|secrettoken'))
        ->assertRedirect(route('home'));
});

it('redirects home without storing anything when the token is missing', function () {
    SecureStorage::shouldReceive('set')->never();

    $this->get('/auth')->assertRedirect(route('home'));
});

it('stores the token from the app-link handoff and redirects home', function () {
    SecureStorage::shouldReceive('set')
        ->once()
        ->with('portal_api_token', '12|secrettoken')
        ->andReturnTrue();

    $this->get('/app/auth?token='.urlencode('12|secrettoken'))
        ->assertRedirect(route('home'));
});

it('shows both login buttons on home when no token is stored', function () {
    // Without the native bridge SecureStorage::get() returns null,
    // so the component renders the guest state.
    $this->get('/')
        ->assertOk()
        ->assertSee(__('Mit Nostr anmelden'))
        ->assertSee(__('Mit Lightning anmelden'));
});

it('opens the portal nostr launcher in the in-app browser when logging in with Nostr', function () {
    Browser::shouldReceive('inApp')
        ->once()
        ->with(app(PortalAuth::class)->nostrLoginUrl());

    Livewire\Livewire::test('portal.connect')->call('loginWithNostr');
});

it('opens the portal lightning login in the in-app browser', function () {
    Browser::shouldReceive('inApp')
        ->once()
        ->with(app(PortalAuth::class)->loginUrl());

    Livewire\Livewire::test('portal.connect')->call('loginWithLightning');
});

it('builds the nostr launcher url with the device name', function () {
    $url = app(PortalAuth::class)->nostrLoginUrl();

    expect($url)->toStartWith('https://portal.einundzwanzig.space/auth/mobile/nostr?')
        ->and($url)->toContain('device_name=');
});

it('builds the lightning login url with the whitelisted redirect uri and device name', function () {
    $url = app(PortalAuth::class)->loginUrl();

    expect($url)->toStartWith('https://portal.einundzwanzig.space/auth/mobile?')
        ->and($url)->toContain('redirect_uri=einundzwanzig%3A%2F%2Fauth')
        ->and($url)->toContain('device_name=');
});

it('fetches the profile with the stored token', function () {
    SecureStorage::shouldReceive('get')->with('portal_api_token')->andReturn('12|secrettoken');
    Http::fake([
        'portal.einundzwanzig.space/api/user' => Http::response(['id' => 7, 'name' => 'Satoshi']),
    ]);

    $profile = app(PortalAuth::class)->profile();

    expect($profile)->toMatchArray(['id' => 7, 'name' => 'Satoshi']);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer 12|secrettoken'));
});

it('discards the stored token when the portal rejects it', function () {
    SecureStorage::shouldReceive('get')->with('portal_api_token')->andReturn('12|revoked');
    SecureStorage::shouldReceive('delete')->once()->with('portal_api_token')->andReturnTrue();
    Http::fake([
        'portal.einundzwanzig.space/api/user' => Http::response(null, 401),
    ]);

    expect(app(PortalAuth::class)->profile())->toBeNull();
});
