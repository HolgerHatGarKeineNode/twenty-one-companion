<?php

namespace App\Http\Middleware;

use App\Services\AppPreferences;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The language chosen during onboarding (or in the settings) — for EVERY route of this
 * app, not only for its own.
 *
 * ── Why this is needed (found in P5, introduced in P2) ───────────────────────
 *
 * This app has two kinds of pages: its own (`/ich/inhalte*`, onboarding) and the
 * package's (`/start`, `/postfach`, `/bereich/*`, `/ich*` — since P2 the whole shell,
 * since P4 the Portal pages too). Until P5 the locale was set exclusively in
 * {@see EnsureOnboarded}, and that middleware hangs on this app's own route group only.
 * The package pages therefore ran in their own default — German.
 *
 * Measured 2026-09-18: `completeOnboarding(locale: 'en')` followed by
 * `GET /bereich/meetups` produced `<html lang="de">` with a German interface, while
 * `/ich/inhalte` in the same session was English. For a user that means: the choice of
 * language works on two screens and not on the rest of the app.
 *
 * ── Why not the package's own middleware ────────────────────────────────────
 *
 * The package resolves the locale from a cookie or the session, set by its
 * `LocaleController`. For the web client that is right (there is no other store there);
 * for this app it would be a SECOND truth: the choice lives in the app preferences here,
 * survives a restart and is made during onboarding, long before a cookie exists. So the
 * host's store decides, and it decides everywhere.
 *
 * Appended to the `web` group (`bootstrap/app.php`) so that the package routes pass
 * through it as well. `EnsureOnboarded` still sets the locale itself — that is not a
 * second place but the same source, and a gate that redirects into onboarding has to show
 * its message in the right language before this middleware ever runs.
 */
class SetAppLocale
{
    public function __construct(private readonly AppPreferences $preferences) {}

    public function handle(Request $request, Closure $next): Response
    {
        app()->setLocale($this->preferences->locale());

        return $next($request);
    }
}
