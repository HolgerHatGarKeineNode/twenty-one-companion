<?php

return [
    /*
     * Fixierter Default-Space (§12): die Relay-URL, die die Web-Client-Insel
     * VOR dem welshman-Boot als `window.__nostrSpace` gesetzt bekommt. Leer =
     * Code-Default (lokaler Test-Relay). Prod setzt die echte Vereins-Relay-URL.
     */
    'space_url' => env('NOSTR_SPACE_URL'),

    /*
     * Head-Partial des Chat-Vollbild-Layouts. Der Web-Client nutzt seine eigene
     * `partials.head` (mit OG/Favicons). Ein Fremdhost (Portal) setzt hier
     * `group::partials.head` — die lädt nur __nostrSpace + die `chat.vite`-Entries.
     */
    'head_partial' => 'group::partials.head',

    /*
     * Vite-Entries, die `group::partials.head` lädt (nur relevant, wenn
     * head_partial = group::partials.head). Der Fremdhost zeigt hier auf seinen
     * Insel-Entry + das Chat-Theme-CSS.
     */
    'vite' => ['resources/css/group.css', 'resources/js/group.js'],

    /*
     * Rücksprung aus dem Vollbild-Chat zurück in die App. Der Chat läuft als
     * eingebetteter Tab (mobile.blade: 5-Tab-Nav) mit eigenem Vollbild-Layout,
     * das die App-Shell ersetzt — der App-Header zeigt darum oben links einen
     * „‹ Meetups"-Ausgang direkt zur Meetups-Route. Bewusst NICHT über `home`:
     * die dortige Start-Weiche (launch.blade) loopt chat-eingeloggte Nutzer
     * zurück in den Chat.
     */
    'exit' => ['route' => 'meetups', 'label' => 'Meetups'],
];
