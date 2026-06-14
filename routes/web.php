<?php

use App\Http\Controllers\PortalAuthCallbackController;
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

Route::livewire('onboarding', 'pages::onboarding.index')->name('onboarding');

Route::middleware(EnsureOnboarded::class)->group(function () {
    Route::redirect('/', '/meetups')->name('home');

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

Route::middleware(['auth', 'verified'])->group(function () {
    Route::view('dashboard', 'dashboard')->name('dashboard');
});

require __DIR__.'/settings.php';
