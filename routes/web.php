<?php

use App\Http\Controllers\PortalAuthCallbackController;
use App\Http\Controllers\PortalSignedEventController;
use Illuminate\Support\Facades\Route;

Route::view('/', 'home')->name('home');

// Deep-link receiver: einundzwanzig://auth?token=… (custom scheme) and the
// verified App Link https://portal…/app/auth?token=… both land here.
Route::get('auth', PortalAuthCallbackController::class)->name('portal.callback');
Route::get('app/auth', PortalAuthCallbackController::class)->name('portal.handoff');

// NIP-55 signer callback via custom scheme: einundzwanzig://signed/{k1}/{event}.
// Amber opens this directly after signing; the app exchanges it for a token.
Route::get('signed/{payload}', PortalSignedEventController::class)
    ->where('payload', '.*')
    ->name('portal.signed');
Route::livewire('meetups', 'pages::meetups.index')->name('meetups');
Route::livewire('meetups/{slug}', 'pages::meetups.show')->name('meetups.show');
Route::livewire('events', 'pages::events.index')->name('events');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
