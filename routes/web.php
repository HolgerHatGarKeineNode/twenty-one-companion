<?php

use App\Http\Controllers\PortalAuthCallbackController;
use App\Http\Controllers\PortalNostrHandoffController;
use App\Http\Controllers\PortalSignedEventController;
use App\Http\Middleware\EnsureOnboarded;
use Illuminate\Support\Facades\Route;

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
