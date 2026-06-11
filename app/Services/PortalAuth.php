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
     * Trade a NIP-55-signed login event (received via the verified App
     * Link callback) for a Sanctum token and store it.
     *
     * @param  array<string, mixed>  $signedEvent
     */
    public function exchangeSignedEvent(string $k1, array $signedEvent): bool
    {
        try {
            $response = Http::timeout(10)
                ->acceptJson()
                ->post($this->baseUrl().'/api/mobile/token', [
                    'k1' => $k1,
                    'event' => $signedEvent,
                    'device_name' => $this->deviceName(),
                ]);
        } catch (ConnectionException) {
            return false;
        }

        $token = $response->json('token');

        if (! $response->successful() || ! is_string($token) || $token === '') {
            return false;
        }

        return $this->storeToken($token);
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
