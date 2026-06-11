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

it('exchanges a signed event for a token when the signer callback opens the app', function () {
    $k1 = str_repeat('a', 64);
    $event = ['id' => 'x', 'sig' => 'y', 'kind' => 22242];

    Http::fake([
        'portal.einundzwanzig.space/api/mobile/token' => Http::response(['token' => '13|exchanged']),
    ]);
    SecureStorage::shouldReceive('set')
        ->once()
        ->with('portal_api_token', '13|exchanged')
        ->andReturnTrue();

    $this->get('/auth/mobile/signed/'.$k1.'/'.rawurlencode(json_encode($event)))
        ->assertRedirect(route('home'));

    Http::assertSent(fn ($request) => $request->url() === 'https://portal.einundzwanzig.space/api/mobile/token'
        && $request['k1'] === $k1
        && $request['event']['kind'] === 22242);
});

it('redirects home with an error flag when the token exchange fails', function () {
    $k1 = str_repeat('b', 64);

    Http::fake([
        'portal.einundzwanzig.space/api/mobile/token' => Http::response(['status' => 'ERROR'], 400),
    ]);
    SecureStorage::shouldReceive('set')->never();

    $this->get('/auth/mobile/signed/'.$k1.'/'.rawurlencode(json_encode(['kind' => 22242])))
        ->assertRedirect(route('home'))
        ->assertSessionHas('portal-connect-failed');
});

it('opens unknown portal paths in the system browser via the fallback route', function () {
    Browser::shouldReceive('open')
        ->once()
        ->with('https://portal.einundzwanzig.space/de/meetup/some-meetup');

    $this->get('/de/meetup/some-meetup')->assertRedirect(route('home'));
});

it('shows the connect button on home when no token is stored', function () {
    // Without the native bridge SecureStorage::get() returns null,
    // so the component renders the guest state.
    $this->get('/')
        ->assertOk()
        ->assertSee(__('Mit Einundzwanzig Portal anmelden'));
});

it('opens the portal mobile login in the in-app browser when connecting', function () {
    Browser::shouldReceive('inApp')
        ->once()
        ->with(app(PortalAuth::class)->loginUrl());

    Livewire\Livewire::test('portal.connect')->call('connect');
});

it('builds the login url with the whitelisted redirect uri and device name', function () {
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
