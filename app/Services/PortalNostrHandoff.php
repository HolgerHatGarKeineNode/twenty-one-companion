<?php

declare(strict_types=1);

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Mobile host side of the single Nostr login → portal connection.
 *
 * The shared chat layer (einundzwanzig/group) owns the orchestration: after
 * the welshman login it signs a kind-22242 event over a portal challenge. This
 * host proxies the two HTTP hops to the remote portal (the WebView can't reach
 * it directly — CORS — nor write the native keystore) and persists the issued
 * Sanctum token via {@see PortalAuth}. Uses the portal's replay-protected
 * /api/mobile/nostr/* endpoints (server-issued single-use k1).
 *
 * Separate from PortalAuth's legacy exchangeSignedEvent()/deep-link flow on
 * purpose: that path stays for released app builds; this is the unified login.
 */
final class PortalNostrHandoff
{
    public function __construct(private readonly PortalAuth $portalAuth) {}

    /**
     * Fetch a fresh single-use login challenge (k1) from the portal, or null
     * when the portal is unreachable or answers without a usable k1.
     */
    public function challenge(): ?string
    {
        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->get($this->portalAuth->baseUrl().'/api/mobile/nostr/challenge');
        } catch (ConnectionException) {
            return null;
        }

        $k1 = $response->json('k1');

        return $response->successful() && is_string($k1) && $k1 !== '' ? $k1 : null;
    }

    /**
     * Trade a welshman-signed kind-22242 event for a Sanctum token and store
     * it in the keystore. Returns false on any failure — a failed portal
     * handoff must never break the chat login it piggybacks on.
     *
     * @param  array<string, mixed>  $signedEvent
     */
    public function exchange(string $k1, array $signedEvent): bool
    {
        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->post($this->portalAuth->baseUrl().'/api/mobile/nostr/token', [
                    'k1' => $k1,
                    'event' => $signedEvent,
                    'device_name' => $this->portalAuth->deviceName(),
                ]);
        } catch (ConnectionException) {
            return false;
        }

        $token = $response->json('token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            return false;
        }

        return $this->portalAuth->storeToken($token);
    }
}
