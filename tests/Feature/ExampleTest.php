<?php

/**
 * `/` leitet NICHT mehr server-seitig um: der Chat-Login lebt auf Mobile nur
 * client-seitig (localStorage['pubkey']), daher entscheidet launch.blade.php
 * erst im Browser zwischen Chat und Meetups.
 *
 * Der Test hing noch am Server-302 aus 27169bf („redirect / to Meetups") und war
 * seit Einführung der Launch-Weiche (592e36c) rot — hier auf das tatsächliche
 * Verhalten nachgezogen.
 */
it('responds to the start route with the client-side launch switch', function () {
    // Nicht auf route('meetups') prüfen: @js() rendert JSON-escaped
    // ("http:\/\/…"), die rohe URL steht so nie im Markup.
    $this->get(route('home'))
        ->assertOk()
        ->assertSee("localStorage.getItem('pubkey')", escape: false)
        ->assertSee('window.location.replace(target)', escape: false);
});
