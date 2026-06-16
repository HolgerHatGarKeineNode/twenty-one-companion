<?php

namespace App\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Native\Mobile\Facades\SecureStorage;

/**
 * Manages the Sanctum personal access token issued by the
 * EINUNDZWANZIG portal and stored in the device keystore.
 */
final class PortalAuth
{
    private const TOKEN_KEY = 'portal_api_token';

    /** Public, damit Tests den Profil-Cache ohne HTTP-Call befüllen können. */
    public const PROFILE_CACHE_KEY = 'portal_profile';

    private const PROFILE_CACHE_TTL_DAYS = 30;

    /**
     * Memoised keystore value: every SecureStorage call is a bridge hop
     * into the native layer, and callers (connector auth, my*-guards)
     * read the token several times per request.
     */
    private ?string $memoizedToken = null;

    private bool $tokenLoaded = false;

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
        return 'TWENTY ONE Companion (Android)';
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
        $stored = SecureStorage::set(self::TOKEN_KEY, $token);

        if ($stored) {
            $this->memoizedToken = $token;
            $this->tokenLoaded = true;
        }

        return $stored;
    }

    public function token(): ?string
    {
        if (! $this->tokenLoaded) {
            $this->memoizedToken = SecureStorage::get(self::TOKEN_KEY);
            $this->tokenLoaded = true;
        }

        return $this->memoizedToken;
    }

    public function hasToken(): bool
    {
        return $this->token() !== null;
    }

    public function forgetToken(): bool
    {
        Cache::forget(self::PROFILE_CACHE_KEY);

        $this->memoizedToken = null;
        $this->tokenLoaded = true;

        return SecureStorage::delete(self::TOKEN_KEY);
    }

    /**
     * Log out: revoke the token at the portal (best effort — offline logout
     * must still work), then delete it from the keystore and drop the
     * cached profile.
     */
    public function logout(): void
    {
        $token = $this->token();

        if ($token !== null) {
            try {
                Http::timeout(10)
                    ->withToken($token)
                    ->acceptJson()
                    ->delete($this->baseUrl().'/api/mobile/token');
            } catch (ConnectionException) {
                // The token is gone locally either way; it expires server-side.
            }
        }

        $this->forgetToken();
    }

    /**
     * Trade a NIP-55-signed login event (received via the einundzwanzig://
     * signer callback) for a Sanctum token and store it.
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
     * Fetch the token owner's profile from the portal and cache it locally,
     * so the last known profile is still shown while offline. A 401
     * discards the stored token and the cached profile.
     *
     * Keys: id, name, email, nostr, is_lecturer, is_leader, avatar.
     *
     * @return array<string, mixed>|null
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
            return $this->cachedProfile();
        }

        if ($response->unauthorized()) {
            $this->forgetToken();

            return null;
        }

        if (! $response->successful()) {
            return $this->cachedProfile();
        }

        $profile = $response->json();

        if (is_array($profile)) {
            $this->cacheProfile($profile);
        }

        return $profile;
    }

    /**
     * Persists a profile array in the local cache (same TTL as a live fetch),
     * so a profile change written through the API is reflected offline too.
     *
     * @param  array<string, mixed>  $profile
     */
    public function cacheProfile(array $profile): void
    {
        Cache::put(self::PROFILE_CACHE_KEY, $profile, now()->addDays(self::PROFILE_CACHE_TTL_DAYS));
    }

    /**
     * The locally cached profile from the last successful fetch.
     *
     * @return array<string, mixed>|null
     */
    public function cachedProfile(): ?array
    {
        $profile = Cache::get(self::PROFILE_CACHE_KEY);

        return is_array($profile) ? $profile : null;
    }
}
