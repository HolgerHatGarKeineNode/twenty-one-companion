<?php

use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\SecureStorage;

/**
 * A minimal kind-22242 login event as the welshman signer would return it.
 * The portal verifies the signature; here the remote portal is faked, so the
 * exact bytes don't matter — only that the event array is forwarded verbatim.
 *
 * @return array<string, mixed>
 */
function fakeSignedNostrEvent(string $k1): array
{
    return [
        'id' => str_repeat('a', 64),
        'pubkey' => str_repeat('b', 64),
        'created_at' => now()->timestamp,
        'kind' => 22242,
        'tags' => [['challenge', $k1]],
        'content' => '',
        'sig' => str_repeat('c', 128),
    ];
}

it('proxies a fresh challenge from the portal', function () {
    Http::fake([
        'portal.einundzwanzig.space/api/mobile/nostr/challenge' => Http::response(['k1' => str_repeat('a', 64), 'ttl' => 300]),
    ]);

    $this->getJson('/portal/nostr-challenge')
        ->assertOk()
        ->assertJsonPath('k1', str_repeat('a', 64));

    Http::assertSent(fn ($request) => $request->url() === 'https://portal.einundzwanzig.space/api/mobile/nostr/challenge');
});

it('returns a gateway error when the portal challenge is unavailable', function () {
    Http::fake([
        'portal.einundzwanzig.space/api/mobile/nostr/challenge' => Http::response(['status' => 'ERROR'], 500),
    ]);

    $this->getJson('/portal/nostr-challenge')->assertStatus(502);
});

it('exchanges a signed event for a portal token and stores it in the keystore', function () {
    $k1 = str_repeat('a', 64);

    SecureStorage::shouldReceive('set')
        ->once()
        ->with('portal_api_token', '42|nostr-issued')
        ->andReturnTrue();

    Http::fake([
        'portal.einundzwanzig.space/api/mobile/nostr/token' => Http::response(['token' => '42|nostr-issued', 'user' => ['id' => 7, 'name' => 'Satoshi']]),
    ]);

    $this->postJson('/portal/nostr-handoff', [
        'k1' => $k1,
        'event' => fakeSignedNostrEvent($k1),
    ])->assertOk()->assertJsonPath('status', 'OK');

    Http::assertSent(fn ($request) => $request->url() === 'https://portal.einundzwanzig.space/api/mobile/nostr/token'
        && $request['k1'] === $k1
        && $request['event']['kind'] === 22242);
});

it('reports a gateway error and stores nothing when the portal rejects the exchange', function () {
    $k1 = str_repeat('a', 64);

    SecureStorage::shouldReceive('set')->never();

    Http::fake([
        'portal.einundzwanzig.space/api/mobile/nostr/token' => Http::response(['status' => 'ERROR', 'reason' => 'Unknown or expired challenge'], 400),
    ]);

    $this->postJson('/portal/nostr-handoff', [
        'k1' => $k1,
        'event' => fakeSignedNostrEvent($k1),
    ])->assertStatus(502)->assertJsonPath('status', 'ERROR');
});

it('validates the handoff payload', function () {
    $response = $this->postJson('/portal/nostr-handoff', ['k1' => 'too-short']);

    expect($response->status())->toBe(422)
        ->and($response->json('errors'))->toHaveKeys(['k1', 'event']);
});
