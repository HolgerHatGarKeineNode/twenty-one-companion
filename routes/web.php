<?php

use App\Http\Controllers\PortalAuthCallbackController;
use App\Http\Controllers\PortalNostrHandoffController;
use App\Http\Controllers\PortalSignedEventController;
use App\Http\Middleware\EnsureOnboarded;
use App\Services\AppPreferences;
use Einundzwanzig\Group\Http\Controllers\LegacyRedirect;
use Einundzwanzig\Push\Push;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;

// Deep-link receiver: einundzwanzig://auth?token=… (custom scheme) and the
// verified App Link https://portal…/app/auth?token=… both land here. These
// routes must stay outside the onboarding gate — a redirect would swallow
// the token callback.
Route::get('auth', PortalAuthCallbackController::class)->name('portal.callback');
Route::get('app/auth', PortalAuthCallbackController::class)->name('portal.handoff');

// NIP-55 signer callback via custom scheme: einundzwanzig://signed/{k1}/{event}.
// Amber opens this directly after signing; the app exchanges it for a token.
Route::get('signed/{payload}', PortalSignedEventController::class)
    ->where('payload', '.*')
    ->name('portal.signed');

// Local bridge for the single Nostr login: the embedded chat layer
// (einundzwanzig/group) signs a portal challenge with the welshman signer and
// hands it here; we proxy to the portal's replay-protected /api/mobile/nostr/*
// endpoints and persist the token. Outside the onboarding gate — the handoff
// runs alongside the chat login, before onboarding may be complete.
Route::get('portal/nostr-challenge', [PortalNostrHandoffController::class, 'challenge'])
    ->name('portal.nostr.challenge');
Route::post('portal/nostr-handoff', [PortalNostrHandoffController::class, 'store'])
    ->name('portal.nostr.handoff');

// Diagnose: löst EINEN Poll-Lauf aus, statt 15 Minuten auf den Takt zu warten —
// sonst ist der Worker am Gerät kaum zu beobachten (die offenen Punkte in
// plans/PUSH-NOTIFICATIONS.md brauchen ihn). Nur wenn PUSH_DEBUG=true gesetzt
// ist; die Route gehört nie in einen Release.
//
// Aufruf auf dem Gerät: einundzwanzig://debug/push-poll
// Der Zustand (Pubkey, Relay, Räume, Session) kommt aus dem letzten push/sync,
// nicht aus der URL.
if (config('push_debug.enabled')) {
    Route::get('debug/push-poll', function (Push $push) {
        $scheduled = $push->pollNow();

        return response(implode("\n", [
            'scheduled: '.($scheduled ? 'JA' : 'NEIN'),
            '',
            $scheduled
                ? "JETZT HOME drücken.\nErgebnis in 30s:  adb logcat -s PushPoll"
                : 'Bridge hat den Job NICHT eingeplant — siehe logcat.',
        ]))->header('Content-Type', 'text/plain; charset=utf-8');
    })->name('debug.push-poll');
}

// Gleicht den Hintergrund-Worker für Chat-Benachrichtigungen mit dem
// Einstellungs-Schalter ab; aufgerufen vom push-sync-Partial bei jedem
// App-Start. Der komplette Zustand (Pubkey, aktiver Relay, Räume, Session)
// kommt aus dem Client — PHP kennt ihn nicht, er lebt in localStorage.
//
// POST mit Body statt GET mit Query-Param, weil die Session später den
// NIP-46-Signing-Key trägt: NativePHP loggt die URL („persistent_dispatch: GET
// /push/sync?…"), der Key stünde damit im logcat. Eigene Route mit
// CSRF-Ausnahme (bootstrap/app.php) — /_native/api/call liefert aus dem
// Layout-Script reproduzierbar MISSING_METHOD (plans/PUSH-NOTIFICATIONS.md §4).
//
// Ausserhalb des Onboarding-Gates: EnsureOnboarded würde den Aufruf sonst
// umleiten, und im Chat (der eigenen Shell) läuft die Middleware ohnehin nicht.
Route::post('push/sync', function (Request $request, AppPreferences $preferences, Push $push) {
    // Der Zustand kommt aus dem Client und wandert in einen Hintergrund-Job, der
    // damit ein Auth-Event signieren lässt und einen Socket öffnet — hier ist die
    // Trust-Grenze.
    $validator = Validator::make($request->all(), [
        'pubkey' => ['required', 'regex:/^[0-9a-f]{64}$/i'],
        'relay' => ['required', 'regex:#^wss?://#'],
        'rooms' => ['required', 'array', 'min:1', 'max:100'],
        'rooms.*' => ['string', 'max:100'],
        // Raum-ID → Anzeigename, nur für den Notification-Titel. Der Wert kommt
        // aus dem 39000-Event eines Relays, landet also in einer Notification —
        // deshalb gedeckelt. Fehlt er, nimmt der Worker die Raum-ID.
        'names' => ['nullable', 'array', 'max:100'],
        'names.*' => ['string', 'max:200'],
        // Übersetzte Wörter für die Notification (der Worker hat keinen Katalog).
        // Gedeckelt wie die Namen: der Wert landet im Meldungstext.
        'labels' => ['nullable', 'array'],
        'labels.quote' => ['nullable', 'string', 'max:40'],
        'session' => ['nullable', 'array'],
    ]);

    // Ausgeloggt, kein Raum, Schalter aus, Müll im Body → leerer Zustand, und
    // der stoppt den Worker. Kein 422: der Client kann nichts nachbessern, und
    // „nichts zu tun" ist hier das erwartete Ergebnis, kein Fehler.
    //
    // KEIN Bridge-Aufruf hier (etwa `notificationPermissionGranted()`), so
    // naheliegend er wäre: Diese Route wird aus dem Layout-Script WÄHREND des
    // Seitenaufbaus gerufen, und Bridge-Aufrufe sind zu diesem Zeitpunkt
    // unzuverlässig (§4, dort als `MISSING_METHOD` beschrieben). Am Gerät
    // gemessen: derselbe Body liefert `scheduled:true`, wenn man die Route nach
    // dem Seitenaufbau ruft — und `cancelled (kein Zustand)`, wenn das Partial
    // sie beim Laden ruft. Ein solches Gate hätte den Worker bei JEDEM
    // Seitenaufruf abbestellt. Der Worker prüft die Berechtigung ohnehin selbst,
    // bevor er eine Notification postet.
    $state = $validator->fails() || ! $preferences->pushEnabled()
        ? []
        : $validator->validated();

    return response()->json(['scheduled' => $push->sync($state)]);
})->name('push.sync');

// Räumt die Chat-Benachrichtigungen, wenn die App in den Vordergrund kommt.
//
// Der Poll-Worker postet, hat aber keinen Gegenweg: eine gemeldete Nachricht
// blieb in der Leiste stehen, auch nachdem der Nutzer sie gelesen hatte —
// Ungelesen-Badge 0, Leiste behauptet weiter „neu". Seit v1.8.0 widerspricht
// dem ein sichtbarer Zähler, vorher fiel es nur niemandem auf.
//
// Kein Zustand im Body, daher POST ohne Validierung — die Route liest nichts
// und schreibt nichts, sie räumt nur die eigene Statusleiste. CSRF-Ausnahme wie
// bei push.sync (bootstrap/app.php): der Aufrufer ist dasselbe nackte Script.
//
// NICHT an den Push-Schalter gekoppelt: wer ihn gerade ausgeschaltet hat, soll
// die zuletzt gemeldeten Nachrichten trotzdem loswerden.
Route::post('push/seen', function (Push $push) {
    return response()->json(['cleared' => $push->clearNotifications()]);
})->name('push.seen');

Route::livewire('onboarding', 'pages::onboarding.index')->name('onboarding');

Route::middleware(EnsureOnboarded::class)->group(function () {
    /*
     * The root leads to Start (Concept C, P2).
     *
     * `launch.blade.php` stood here: a bare document whose only job was to read
     * `localStorage['pubkey']` in the <head> and replace the location with either the chat
     * or the meetups. It existed because the chat login lives client-side only and the
     * server cannot see it — and because a server-side 302 fastpath failed on the NativePHP
     * bridge, which does not persist fetch response cookies (OPTIMIZE.md phase 8).
     *
     * Start answers the same question WITHOUT the detour: it renders for a guest and for a
     * member alike and decides the difference in its own island with a skeleton (D4). A
     * page whose entire content is a redirect is a frame the user pays for and never sees.
     *
     * The route NAME stays `home`: the onboarding pager and the deeplink handlers use it.
     */
    Route::get('/', LegacyRedirect::class)
        ->defaults('ziel', '/start')
        ->name('home');

    /*
     * ── „Meine Inhalte" lives under „Ich" (P2, extended in P5) ───────────────────
     *
     * The first three pages were `/mine`, `/mine/places` and `/mine/teaching`, reached
     * through the hamburger flyout and later through the "Mehr" hub. Both are gone; what is
     * mine belongs under „Ich", next to bookmarks, wallet and the association — and the entry
     * is a `view:` row of `config('group.ich')`.
     *
     * Host routes and not package routes, because creating and editing Portal content needs
     * a Portal token and the package knows nothing about one (the plan's app-only surfaces).
     *
     * P5 added the last two, and they are the write surfaces that had nowhere else to go once
     * this app's own Portal pages were deleted (D9 gave the reading to the package in P4):
     *
     *   `ich/inhalte/meetups`  the „Meine" tab of the old `/meetups` — picker, editor,
     *                          remove-from-mine.
     *   `ich/inhalte/termine`  the leader date management that hung under the old meetup
     *                          DETAIL, now over all own meetups at once instead of one page
     *                          per meetup.
     *
     * The old map's „Städte"/„Orte" lists went into `ich/inhalte/orte` as a second SCOPE
     * („Alle" next to „Meine") rather than into a route of their own — they are lists of
     * cities and venues, which is what that page is about.
     */
    Route::livewire('ich/inhalte', 'pages::mine.index')->name('ich.inhalte');
    Route::livewire('ich/inhalte/meetups', 'pages::mine.meetups')->name('ich.inhalte.meetups');
    Route::livewire('ich/inhalte/termine', 'pages::mine.events')->name('ich.inhalte.termine');
    Route::livewire('ich/inhalte/orte', 'pages::mine.places')->name('ich.inhalte.orte');
    Route::livewire('ich/inhalte/lehre', 'pages::mine.teaching')->name('ich.inhalte.lehre');

    /*
     * ══ The old paths of this app (R7) ══════════════════════════════════════════
     *
     * Same controller and same reasoning as the package rows (`routes/group.php` there):
     * a 302 that keeps the query string, and a controller rather than a closure because the
     * mobile build caches its routes. 302 until the sweep in P7, then 301.
     *
     * The Portal-page rows (`/meetups*`, `/events`, `/map`, `/courses*`, `/lecturers/*`)
     * arrived with P5 — P4 built the package pages (D9), P5 deleted this app's copies and
     * moved the app-only WRITE surfaces under `/ich/inhalte*`. Two of the rows do more than
     * change a path:
     *
     *   `/meetups?tab=meine` → `/ich/inhalte/meetups`, because that tab is the write surface
     *                          and the package list has no tabs. Without the query it is the
     *                          package's meetup list.
     *   `/events`            → `/bereich/meetups?ansicht=termine`, the read list. The leader
     *                          management that also lived there is `/ich/inhalte/termine`.
     *
     * `/map` keeps its promise: the app binds the map view, so the redirect lands on a real
     * map (`?ansicht=karte`) and not on the Portal link-out the web shows there.
     *
     * `/profile` is the interesting one. It was this app's settings screen; its sections are
     * injected into the package hub since P2 (`config/group.php`). It forwards to „Ich" and
     * not straight to the hub, because that is where a user who typed the old address is
     * looking for himself — the hub is one row further.
     */
    $legacy = static function (string $pfad, string $ziel, string $name): void {
        Route::get($pfad, LegacyRedirect::class)
            ->defaults('ziel', $ziel)
            ->name($name);
    };

    $legacy('more', '/start', 'legacy.more');
    $legacy('profile', '/ich', 'legacy.profile');
    $legacy('mine', '/ich/inhalte', 'legacy.mine');
    $legacy('mine/places', '/ich/inhalte/orte', 'legacy.mine.places');
    $legacy('mine/teaching', '/ich/inhalte/lehre', 'legacy.mine.teaching');

    /*
     * ── The Portal pages of this app (P5) ────────────────────────────────────────
     *
     * Same controller and same rules as the rows above: 301 since P7 (302 before), with the
     * query carried along, a
     * controller rather than a closure because the mobile build caches its routes. Three of
     * these rows do more than change a path, and each does it with the controller's own
     * vocabulary (`behalte`, `umbenenne`, `weiche` — `packages/…/LegacyRedirect.php`):
     *
     *   `?tab=meine`       leaves the list entirely. It was the WRITE surface, and the
     *                      package's read-only list has no tab for it — so it lands on
     *                      „Ich › Meine Inhalte", where that surface now lives.
     *   `?country=`        is called `land` in the package. Renamed, not dropped: it is in
     *                      links people have shared and in shipped app builds.
     *   `/map?tab=staedte` keeps its two lists: they moved into `/ich/inhalte/orte` as the
     *   `…?tab=orte`       scope „Alle", and the row carries the reader to exactly that.
     *
     * `/map` without a tab lands on a REAL map: this chassis binds the map view
     * (`meetup_map_view`), unlike the web, which offers the Portal's map there instead.
     */
    $legacyQuery = static function (string $pfad, string $ziel, string $name, array $defaults = []): void {
        Route::get($pfad, LegacyRedirect::class)
            ->defaults('ziel', $ziel)
            ->defaults('behalte', $defaults['behalte'] ?? [])
            ->defaults('umbenenne', $defaults['umbenenne'] ?? [])
            ->defaults('weiche', $defaults['weiche'] ?? null)
            ->name($name);
    };

    $legacyQuery('meetups', '/bereich/meetups', 'legacy.meetups', [
        'behalte' => ['q'],
        'umbenenne' => ['country' => 'land'],
        'weiche' => ['param' => 'tab', 'werte' => ['meine' => '/ich/inhalte/meetups']],
    ]);
    $legacy('meetups/{slug}', '/bereich/meetups/{slug}', 'legacy.meetups.show');
    $legacyQuery('events', '/bereich/meetups?ansicht=termine', 'legacy.events', [
        'umbenenne' => ['country' => 'land'],
    ]);
    $legacyQuery('map', '/bereich/meetups?ansicht=karte', 'legacy.map', [
        'umbenenne' => ['country' => 'land'],
        'weiche' => ['param' => 'tab', 'werte' => [
            'staedte' => '/ich/inhalte/orte?umfang=alle',
            // The venue list is gone with the portal's venue model (einundzwanzig-portal
            // 5aba6dc); the old link lands on the cities, like `?tab=orte` on the page.
            'orte' => '/ich/inhalte/orte?umfang=alle',
        ]],
    ]);
    $legacyQuery('courses', '/bereich/kurse', 'legacy.courses', [
        'behalte' => ['q'],
        'weiche' => ['param' => 'tab', 'werte' => [
            'meine' => '/ich/inhalte/lehre',
            'referenten' => '/bereich/kurse?ansicht=referenten',
        ]],
    ]);
    $legacy('courses/{id}', '/bereich/kurse/{id}', 'legacy.courses.show');
    $legacy('lecturers/{id}', '/bereich/kurse/referenten/{id}', 'legacy.lecturers.show');
});
