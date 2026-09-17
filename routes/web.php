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

    Route::livewire('meetups', 'pages::meetups.index')->name('meetups');
    Route::livewire('meetups/{slug}', 'pages::meetups.show')->name('meetups.show');
    Route::livewire('events', 'pages::events.index')->name('events');
    Route::livewire('map', 'pages::map.index')->name('map');
    Route::livewire('courses', 'pages::courses.index')->name('courses');
    Route::livewire('courses/{id}', 'pages::courses.show')->whereNumber('id')->name('courses.show');
    Route::livewire('lecturers/{id}', 'pages::lecturers.show')->whereNumber('id')->name('lecturers.show');

    /*
     * ── „Meine Inhalte" lives under „Ich" (P2) ───────────────────────────────────
     *
     * These three pages were `/mine`, `/mine/places` and `/mine/teaching`, reached through
     * the hamburger flyout and later through the "Mehr" hub. Both are gone; what is mine
     * belongs under „Ich", next to bookmarks, wallet and the association — and the entry is
     * a `view:` row of `config('group.ich')`.
     *
     * Host routes and not package routes, because creating and editing Portal content needs
     * a Portal token and the package knows nothing about one (the plan's app-only surfaces).
     */
    Route::livewire('ich/inhalte', 'pages::mine.index')->name('ich.inhalte');
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
     * are NOT here: those pages still live in this app and only move into the package with
     * P4 (D9). Redirecting them now would point at routes that do not exist.
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
});
