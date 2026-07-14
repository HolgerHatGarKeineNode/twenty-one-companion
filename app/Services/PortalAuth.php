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
     * Throttle-Marker für den app-weiten Profil-Refresh. Der 30-Tage-Cache ist
     * nur ein Offline-Fallback; Rollen (is_leader/is_lecturer) ändern sich
     * serverseitig und dürfen nicht tagelang veraltet weitergezeigt werden.
     */
    private const PROFILE_FRESH_KEY = 'portal_profile_refreshed';

    private const PROFILE_FRESH_TTL_MINUTES = 15;

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

    public function deviceName(): string
    {
        return 'TWENTY ONE Companion (Android)';
    }

    public function storeToken(string $token): bool
    {
        $stored = SecureStorage::set(self::TOKEN_KEY, $token);

        if ($stored) {
            $this->memoizedToken = $token;
            $this->tokenLoaded = true;
            // Frischer Login: den Refresh-Throttle einer Alt-Session verwerfen,
            // damit freshProfile() sofort das aktuelle Profil (inkl. Rollen) holt.
            Cache::forget(self::PROFILE_FRESH_KEY);
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
        Cache::forget(self::PROFILE_FRESH_KEY);

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

        // ponytail: Ein 401 löscht den Token NICHT mehr automatisch. profile()
        // läuft app-weit bei JEDEM Seitenaufbau (freshProfile() im mobile-Layout);
        // ein transienter/abgelaufener 401 (Portal-Hiccup, kurzer Server- oder
        // Netzfehler) warf den Nutzer sonst dauerhaft aus der Session, obwohl der
        // Token im Keystore steht. Wir behandeln 401 wie jeden anderen Fehlschlag:
        // gecachtes Profil zeigen, Token behalten. Entfernt wird er nur noch bei
        // explizitem logout() (bzw. serverseitig durch Ablauf).
        // Bekannte Grenze: ein serverseitig widerrufener Token bleibt lokal als
        // „verbunden" sichtbar, bis der Nutzer manuell abmeldet — akzeptabel
        // gegenüber einem Überraschungs-Logout bei jedem transienten 401.
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
     * App-weiter Profil-Zugriff für Screens außerhalb des Connect-Flows: gibt
     * den Cache zurück, stößt aber höchstens alle PROFILE_FRESH_TTL_MINUTES
     * einen Live-Fetch an, damit serverseitige Rollenänderungen (z. B. frisch
     * gesetzter Leader → Organisator-Button) zeitnah ankommen — ohne pro
     * Seitenaufruf zu netzwerken und ohne die Offline-Anzeige zu opfern.
     *
     * @return array<string, mixed>|null
     */
    public function freshProfile(): ?array
    {
        if (! $this->hasToken()) {
            return null;
        }

        if (Cache::get(self::PROFILE_FRESH_KEY) === true) {
            return $this->cachedProfile();
        }

        // Vor dem Fetch setzen: throttlet auch bei Offline-Fehlschlag, sonst
        // liefe jeder Seitenaufruf in den 10s-Timeout von profile().
        Cache::put(self::PROFILE_FRESH_KEY, true, now()->addMinutes(self::PROFILE_FRESH_TTL_MINUTES));

        return $this->profile();
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
