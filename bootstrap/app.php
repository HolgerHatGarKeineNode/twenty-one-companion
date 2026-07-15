<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Der push-sync-Partial läuft als nacktes Script in beiden Layouts und
        // hat kein CSRF-Token zur Hand — im Chat-Layout scheiterte genau daran
        // schon eine Livewire-Variante mit 419 (plans/PUSH-NOTIFICATIONS.md §4).
        // Ungefährlich: die Route liest keine Session, sondern schiebt einen vom
        // Client mitgebrachten Zustand ins Gerät zurück.
        $middleware->preventRequestForgery(except: ['push/sync']);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*'),
        );
    })->create();
