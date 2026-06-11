<?php

use App\Http\Controllers\PortalAuthCallbackController;
use App\Http\Controllers\PortalSignedEventController;
use Illuminate\Support\Facades\Route;
use Native\Mobile\Facades\Browser;

Route::view('/', 'home')->name('home');

// Deep-link receiver: einundzwanzig://auth?token=… arrives here.
Route::get('auth', PortalAuthCallbackController::class)->name('portal.callback');

// App-Link receiver: https://portal…/app/auth?token=… opens the app here.
Route::get('app/auth', PortalAuthCallbackController::class)->name('portal.handoff');

// App-Link receiver for the NIP-55 signer callback (Amber → app directly).
Route::get('auth/mobile/signed/{payload}', PortalSignedEventController::class)
    ->where('payload', '.*')
    ->name('portal.signed');

// Any other portal URL that opens the app via the verified App Link is
// not ours to render — hand it to the system browser.
Route::fallback(function () {
    Browser::open(
        rtrim((string) config('services.portal.url'), '/').request()->getRequestUri(),
    );

    return redirect()->route('home');
});
Route::view('meetups', 'meetups')->name('meetups');

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
