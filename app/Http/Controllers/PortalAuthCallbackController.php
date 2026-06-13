<?php

namespace App\Http\Controllers;

use App\Services\AppPreferences;
use App\Services\PortalAuth;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Receives the einundzwanzig://auth deep link from the portal login flow.
 * The native shell maps it to GET /auth?token=… on the embedded Laravel.
 */
final class PortalAuthCallbackController extends Controller
{
    public function __invoke(Request $request, PortalAuth $portalAuth, AppPreferences $preferences): RedirectResponse
    {
        $token = (string) $request->query('token', '');

        // Während eines laufenden Onboardings zurück in den Pager (Portal-
        // Step), sonst auf die Profil-Seite (Phase 3.4).
        $target = $preferences->targetAfterPortalAuth();

        if ($token !== '') {
            $portalAuth->storeToken($token);

            return redirect()->route($target)->with('portal-connected', true);
        }

        return redirect()->route($target);
    }
}
