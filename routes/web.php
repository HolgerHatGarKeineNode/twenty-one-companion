<?php

use App\Http\Controllers\PortalAuthCallbackController;
use App\Http\Controllers\PortalNostrHandoffController;
use App\Http\Controllers\PortalSignedEventController;
use App\Http\Middleware\EnsureOnboarded;
use App\Services\AppPreferences;
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

Route::livewire('onboarding', 'pages::onboarding.index')->name('onboarding');

Route::middleware(EnsureOnboarded::class)->group(function () {
    // Start-Weiche: in den Chat, wenn dort eingeloggt, sonst Meetups. Der
    // Chat-Login liegt auf Mobile nur client-seitig (localStorage), daher
    // entscheidet die Launch-Seite per JS statt eines Server-Redirects.
    // (Ein server-seitiger 302-Fastpath scheiterte an der NativePHP-Bridge:
    // sie persistiert keine fetch-Response-Cookies — siehe OPTIMIZE.md Phase 8.)
    Route::view('/', 'launch')->name('home');

    // „Mehr"-Hub (P3, §3.4): der vierte Tab der verschmolzenen Shell. Gast-lesbar
    // (Entdecken), geschützte Einträge (Meine Inhalte/Konto) gaten client-seitig.
    Route::livewire('more', 'pages::more.index')->name('more');

    Route::livewire('meetups', 'pages::meetups.index')->name('meetups');
    Route::livewire('meetups/{slug}', 'pages::meetups.show')->name('meetups.show');
    Route::livewire('events', 'pages::events.index')->name('events');
    Route::livewire('map', 'pages::map.index')->name('map');
    Route::livewire('courses', 'pages::courses.index')->name('courses');
    Route::livewire('courses/{id}', 'pages::courses.show')->whereNumber('id')->name('courses.show');
    Route::livewire('lecturers/{id}', 'pages::lecturers.show')->whereNumber('id')->name('lecturers.show');
    Route::livewire('profile', 'pages::profile.index')->name('profile');
    Route::livewire('mine', 'pages::mine.index')->name('mine');
    Route::livewire('mine/places', 'pages::mine.places')->name('mine.places');
    Route::livewire('mine/teaching', 'pages::mine.teaching')->name('mine.teaching');
});
