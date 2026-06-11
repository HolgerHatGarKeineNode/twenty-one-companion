<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\SecureStorage;

/**
 * Manages the Sanctum personal access token issued by the
 * Einundzwanzig portal and stored in the device keystore.
 */
final class PortalAuth
{
    private const TOKEN_KEY = 'portal_api_token';

    public function baseUrl(): string
    {
        return rtrim((string) config('services.portal.url'), '/');
    }

    /**
     * URL of the portal's mobile login page. The portal redirects back via
     * the einundzwanzig://auth deep link carrying the token.
     */
    public function loginUrl(): string
    {
        return $this->baseUrl().'/auth/mobile?'.http_build_query([
            'redirect_uri' => 'einundzwanzig://auth',
            'device_name' => $this->deviceName(),
        ]);
    }

    public function deviceName(): string
    {
        return 'Einundzwanzig App (Android)';
    }

    /**
     * URL of the portal's headless Nostr launcher. Opened in the in-app
     * browser, it fires the NIP-55 signer (e.g. Amber) via window.location
     * so the intent carries category.BROWSABLE (required for Amber's
     * web-signing flow). The signer signs locally — no relay round-trip —
     * and the token is handed back via the einundzwanzig:// App Link.
     */
    public function nostrLoginUrl(): string
    {
        return $this->baseUrl().'/auth/mobile/nostr?'.http_build_query([
            'device_name' => $this->deviceName(),
        ]);
    }

    public function storeToken(string $token): bool
    {
        return SecureStorage::set(self::TOKEN_KEY, $token);
    }

    public function token(): ?string
    {
        return SecureStorage::get(self::TOKEN_KEY);
    }

    public function hasToken(): bool
    {
        return $this->token() !== null;
    }

    public function forgetToken(): bool
    {
        return SecureStorage::delete(self::TOKEN_KEY);
    }

    /**
     * Fetch the token owner's profile from the portal. Returns null when
     * offline or not authenticated; a 401 discards the stored token.
     *
     * @return array{id: int, name: string, email: string|null, nostr: string|null, is_lecturer: bool, is_leader: bool, avatar: string|null}|null
     */
    public function profile(): ?array
    {
        $token = $this->token();

        if ($token === null) {
            return null;
        }

        try {
            $response = Http::timeout(10)
                ->withToken($token)
                ->acceptJson()
                ->get($this->baseUrl().'/api/user');
        } catch (ConnectionException) {
            return null;
        }

        if ($response->unauthorized()) {
            $this->forgetToken();

            return null;
        }

        return $response->successful() ? $response->json() : null;
    }
}
