<?php

namespace App\Http\Controllers;

use App\Services\AppPreferences;
use App\Services\PortalAuth;
use Illuminate\Http\RedirectResponse;

/**
 * Receives the NIP-55 signer callback via the custom scheme
 * einundzwanzig://signed/{k1}/{event}. The signer (e.g. Amber) opens this
 * deep link directly after signing, so the token exchange happens entirely
 * in the app — no browser handoff page, no Custom Tab. Trades the signed
 * event for a Sanctum token at the portal and stores it.
 */
final class PortalSignedEventController extends Controller
{
    public function __invoke(string $payload, PortalAuth $portalAuth, AppPreferences $preferences): RedirectResponse
    {
        // Während eines laufenden Onboardings zurück in den Pager (Portal-
        // Step), sonst auf die Profil-Seite (Phase 3.4).
        $target = $preferences->targetAfterPortalAuth();

        $k1 = substr($payload, 0, 64);

        if (strlen($payload) > 64 && ctype_xdigit($k1)) {
            $signedEvent = json_decode(ltrim(substr($payload, 64), '/'), true);

            if (is_array($signedEvent) && $portalAuth->exchangeSignedEvent($k1, $signedEvent)) {
                return redirect()->route($target)->with('portal-connected', true);
            }
        }

        return redirect()->route($target)->with('portal-connect-failed', true);
    }
}
