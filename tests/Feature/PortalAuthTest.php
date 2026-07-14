<?php

use App\Http\Integrations\Portal\Requests\GetMobileMeetupsRequest;
use App\Http\Integrations\Portal\Requests\UpdateUserProfileRequest;
use App\Services\PortalAuth;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\SecureStorage;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

afterEach(fn () => MockClient::destroyGlobal());

it('stores the token from the deep link callback and redirects to the profile page', function () {
    SecureStorage::shouldReceive('set')
        ->once()
        ->with('portal_api_token', '12|secrettoken')
        ->andReturnTrue();

    $this->get('/auth?token='.urlencode('12|secrettoken'))
        ->assertRedirect(route('profile'));
});

it('redirects to the profile page without storing anything when the token is missing', function () {
    SecureStorage::shouldReceive('set')->never();

    $this->get('/auth')->assertRedirect(route('profile'));
});

it('stores the token from the app-link handoff and redirects to the profile page', function () {
    SecureStorage::shouldReceive('set')
        ->once()
        ->with('portal_api_token', '12|secrettoken')
        ->andReturnTrue();

    $this->get('/app/auth?token='.urlencode('12|secrettoken'))
        ->assertRedirect(route('profile'));
});

it('exchanges the signed event for a token when the signer callback opens the app', function () {
    $k1 = str_repeat('a', 64);
    $event = ['id' => 'x', 'sig' => 'y', 'kind' => 22242];

    Http::fake([
        'portal.einundzwanzig.space/api/mobile/token' => Http::response(['token' => '13|exchanged']),
    ]);
    SecureStorage::shouldReceive('set')
        ->once()
        ->with('portal_api_token', '13|exchanged')
        ->andReturnTrue();

    $this->get('/signed/'.$k1.'/'.rawurlencode(json_encode($event)))
        ->assertRedirect(route('profile'));

    Http::assertSent(fn ($request) => $request->url() === 'https://portal.einundzwanzig.space/api/mobile/token'
        && $request['k1'] === $k1
        && $request['event']['kind'] === 22242);
});

it('redirects to the profile page with an error flag when the token exchange fails', function () {
    $k1 = str_repeat('b', 64);

    Http::fake([
        'portal.einundzwanzig.space/api/mobile/token' => Http::response(['status' => 'ERROR'], 400),
    ]);
    SecureStorage::shouldReceive('set')->never();

    $this->get('/signed/'.$k1.'/'.rawurlencode(json_encode(['kind' => 22242])))
        ->assertRedirect(route('profile'))
        ->assertSessionHas('portal-connect-failed');
});

it('shows the single Nostr login CTA and no portal web-login on the profile page when no token is stored', function () {
    // Without the native bridge SecureStorage::get() returns null,
    // so the component renders the guest state. Single-Login: nur noch der
    // Nostr-Login (via Chat-Handoff), kein Portal-Web-Login (Lightning) mehr.
    MockClient::global([
        GetMobileMeetupsRequest::class => MockResponse::make([]),
    ]);

    $this->get(route('profile'))
        ->assertOk()
        ->assertSee(__('Mit Nostr anmelden'))
        ->assertSee(route('group.nostr-login'), escape: false)
        ->assertDontSee(__('Mit Lightning anmelden'));
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

it('keeps the stored token and serves the cached profile on a transient 401', function () {
    // Ein 401 darf NICHT abmelden: profile() läuft app-weit bei jedem
    // Seitenaufbau, ein transienter Portal-401 würde den Nutzer sonst
    // dauerhaft aus der Session werfen. Token bleibt, gecachtes Profil kommt.
    SecureStorage::shouldReceive('get')->with('portal_api_token')->andReturn('12|token');
    SecureStorage::shouldReceive('delete')->never();
    Cache::put('portal_profile', ['id' => 7, 'name' => 'Satoshi']);
    Http::fake([
        'portal.einundzwanzig.space/api/user' => Http::response(null, 401),
    ]);

    expect(app(PortalAuth::class)->profile())->toMatchArray(['id' => 7, 'name' => 'Satoshi'])
        ->and(app(PortalAuth::class)->hasToken())->toBeTrue()
        ->and(Cache::has('portal_profile'))->toBeTrue();
});

it('caches the profile and serves it while the portal is unreachable', function () {
    SecureStorage::shouldReceive('get')->with('portal_api_token')->andReturn('12|secrettoken');
    Http::fake([
        'portal.einundzwanzig.space/api/user' => Http::response(['id' => 7, 'name' => 'Satoshi']),
    ]);

    app(PortalAuth::class)->profile();

    // Offline: the connection fails, but the cached profile is returned.
    Http::fake(fn () => throw new ConnectionException('offline'));

    expect(app(PortalAuth::class)->profile())->toMatchArray(['id' => 7, 'name' => 'Satoshi']);
});

it('revokes the token at the portal and deletes it locally on logout', function () {
    SecureStorage::shouldReceive('get')->with('portal_api_token')->andReturn('12|secrettoken');
    SecureStorage::shouldReceive('delete')->once()->with('portal_api_token')->andReturnTrue();
    Cache::put('portal_profile', ['id' => 7]);
    Http::fake([
        'portal.einundzwanzig.space/api/mobile/token' => Http::response(['status' => 'OK']),
    ]);

    app(PortalAuth::class)->logout();

    Http::assertSent(fn ($request) => $request->method() === 'DELETE'
        && $request->url() === 'https://portal.einundzwanzig.space/api/mobile/token'
        && $request->hasHeader('Authorization', 'Bearer 12|secrettoken'));
    expect(Cache::has('portal_profile'))->toBeFalse();
});

it('still logs out locally when the portal is unreachable', function () {
    SecureStorage::shouldReceive('get')->with('portal_api_token')->andReturn('12|secrettoken');
    SecureStorage::shouldReceive('delete')->once()->with('portal_api_token')->andReturnTrue();
    Http::fake(fn () => throw new ConnectionException('offline'));

    app(PortalAuth::class)->logout();
});

it('skips the revoke call when no token is stored', function () {
    SecureStorage::shouldReceive('get')->with('portal_api_token')->andReturnNull();
    SecureStorage::shouldReceive('delete')->once()->with('portal_api_token')->andReturnTrue();
    Http::fake();

    app(PortalAuth::class)->logout();

    Http::assertNothingSent();
});

it('shows the name, role badges, nostr key and own-content link when connected', function () {
    withPortalToken();
    Http::fake([
        'portal.einundzwanzig.space/api/user' => Http::response(
            userProfileFixture(['name' => 'Satoshi', 'is_lecturer' => true, 'is_leader' => true]),
        ),
    ]);

    Livewire\Livewire::test('portal.connect')
        ->assertSee('Satoshi')
        ->assertSee(__('Referent'))
        ->assertSee(__('Leiter'))
        ->assertSee('npub1xyz')
        ->assertSee(__('Meine Inhalte'));
});

it('saves a changed display name and refreshes the cached profile', function () {
    withPortalToken();
    Http::fake([
        'portal.einundzwanzig.space/api/user' => Http::response(userProfileFixture(['name' => 'Satoshi'])),
    ]);
    MockClient::global([
        UpdateUserProfileRequest::class => MockResponse::make(userProfileFixture(['name' => 'Hal Finney'])),
    ]);

    Livewire\Livewire::test('portal.connect')
        ->call('startEditingName')
        ->set('name', 'Hal Finney')
        ->call('saveName')
        ->assertHasNoErrors()
        ->assertSet('editingName', false)
        ->assertSet('profile.name', 'Hal Finney');

    expect(app(PortalAuth::class)->cachedProfile())->toMatchArray(['name' => 'Hal Finney']);
});

it('rejects an empty display name without calling the portal', function () {
    withPortalToken();
    Http::fake([
        'portal.einundzwanzig.space/api/user' => Http::response(userProfileFixture()),
    ]);

    Livewire\Livewire::test('portal.connect')
        ->call('startEditingName')
        ->set('name', '')
        ->call('saveName')
        ->assertHasErrors(['name'])
        ->assertSet('editingName', true);
});

it('freshProfile fetches live so a server-side role change reaches the app', function () {
    withPortalToken();
    Http::fake([
        'portal.einundzwanzig.space/api/user' => Http::response(userProfileFixture(['is_leader' => true])),
    ]);

    $profile = app(PortalAuth::class)->freshProfile();

    expect($profile['is_leader'])->toBeTrue();
    Http::assertSentCount(1);
});

it('freshProfile throttles the live fetch to once per window and then serves the cache', function () {
    withPortalToken();
    Http::fake([
        'portal.einundzwanzig.space/api/user' => Http::response(userProfileFixture(['is_leader' => true])),
    ]);
    $auth = app(PortalAuth::class);

    $auth->freshProfile();          // erster Aufruf: live
    $again = $auth->freshProfile(); // innerhalb des Fensters: throttled → Cache

    expect($again['is_leader'])->toBeTrue();
    Http::assertSentCount(1);
});

it('freshProfile returns null without a token and never calls the portal', function () {
    withoutPortalToken();
    Http::fake();

    expect(app(PortalAuth::class)->freshProfile())->toBeNull();
    Http::assertNothingSent();
});

it('opens the own nostr profile on njump in the system browser', function () {
    withPortalToken();
    Http::fake([
        'portal.einundzwanzig.space/api/user' => Http::response(userProfileFixture(['nostr' => 'npub1xyz'])),
    ]);
    Browser::shouldReceive('open')->once()->with('https://njump.me/npub1xyz');

    Livewire\Livewire::test('portal.connect')->call('openNostr');
});
