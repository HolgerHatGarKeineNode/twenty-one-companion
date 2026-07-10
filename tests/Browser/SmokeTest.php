<?php

/**
 * Browser-Smoke (Phase 8.1): besucht jede Kern-Route in einem echten Chromium
 * (Mobile-Viewport, Dark Mode = App-Default) und stellt sicher, dass keine
 * Seite einen JavaScript-Fehler wirft. Das Portal ist im Browser-Env
 * unerreichbar (phpunit.browser.xml → PORTAL_URL), die Seiten rendern also
 * ihre Offline-/Leer-Zustände — der Smoke prüft die JS-Fehlerfreiheit der
 * Render-Pfade, nicht die Portal-Daten (das macht die Integrationssuite 8.9 /
 * die Live-Abnahme 8.10).
 */
it('renders the core pages without javascript errors', function (string $route) {
    visit($route)
        ->on()->mobile()
        ->inDarkMode()
        ->assertNoJavaScriptErrors();
})->with([
    'meetups' => '/meetups',
    'more hub' => '/more',
    'events' => '/events',
    'map' => '/map',
    'courses' => '/courses',
    'courses (referenten tab)' => '/courses?tab=referenten',
    'profile' => '/profile',
    'mine hub' => '/mine',
    'mine places' => '/mine/places',
    'mine teaching' => '/mine/teaching',
]);
