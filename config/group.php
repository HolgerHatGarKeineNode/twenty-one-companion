<?php

/*
 * P3 (App-Shell-Verschmelzung §3.2/§8.2): der Feature-Flag `UNIFIED_SHELL`
 * schaltet die verschmolzene 4-Tab-Shell (Chat · Wallet · Meetups · Mehr)
 * kohärent an EINER Stelle — er steuert sowohl die Nav-Registry + den
 * Chat-Rücksprung hier als auch `mobile.blade` (alte 5-Tab-Nav vs. geteilte
 * `<x-group::bottom-nav>`). Aus = heutiges Verhalten (Package-Default-Nav im
 * Chat, 5-Tab-Nav auf den Meetups-Seiten, „‹ Meetups"-Ausgang). An = Chat ist
 * Tab 1, kein Takeover, kein Exit-Link.
 */
$unifiedShell = (bool) env('UNIFIED_SHELL', false);

$config = [
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
     * Feature-Flag der verschmolzenen Shell (P3). Von `mobile.blade` gelesen, um
     * zwischen alter 5-Tab-Nav (+ Hamburger-Flyout) und der geteilten
     * `<x-group::bottom-nav>` (4 Tabs, Mehr-Hub statt Flyout) umzuschalten.
     */
    'unified_shell' => $unifiedShell,

    /*
     * Rücksprung aus dem Vollbild-Chat zurück in die App. Nur im Legacy-Modus:
     * dort läuft der Chat als eingebetteter Tab mit eigenem Vollbild-Layout, das
     * die App-Shell ersetzt — der App-Header zeigt oben links einen „‹ Meetups"-
     * Ausgang (bewusst NICHT über `home`: die Start-Weiche loopt chat-eingeloggte
     * Nutzer zurück in den Chat). Unified: Chat ist Tab 1 der einen Shell → kein
     * Exit-Link mehr (§3.3), darum `null` = Brand-Mark statt Ausgang.
     */
    'exit' => $unifiedShell ? null : ['route' => 'meetups', 'label' => 'Meetups'],
];

/*
 * Nav-Registry (§8.2) nur im Unified-Modus setzen. Legacy lässt den Key weg →
 * der Package-Default (3 Chat-Tabs) trägt das alte Vollbild-Layout unverändert,
 * die Meetups-Seiten behalten ihre 5-Tab-Nav in `mobile.blade`. Unified = die
 * 4 Tabs aus Plan §3.2, in beiden Shells identisch konsumiert.
 *
 * `match` listet alle Routen, unter denen der Tab aktiv leuchtet — für die
 * host-injizierten Tabs (Meetups/Mehr) über die Host-Route-Namen (§10.6). Der
 * Mehr-Tab bündelt die aus der Bottom-Nav verdrängten Bereiche (Termine/Karte/
 * Profil) plus Entdecken/Meine-Inhalte, damit er auf all deren Seiten aktiv ist.
 */
if ($unifiedShell) {
    $config['nav'] = [
        ['key' => 'chat', 'route' => 'group.spaces', 'match' => 'group.spaces,group.directory,group.room,group.space.settings,group.join', 'icon' => 'chat-bubble-left-right', 'label' => 'Chat', 'gate' => 'nostr'],
        ['key' => 'wallet', 'route' => 'group.wallet', 'match' => 'group.wallet', 'icon' => 'bolt', 'label' => 'Wallet', 'gate' => 'nostr'],
        ['key' => 'meetups', 'route' => 'meetups', 'match' => 'meetups,meetups.show', 'icon' => 'calendar', 'label' => 'Meetups', 'gate' => 'guest'],
        ['key' => 'more', 'route' => 'more', 'match' => 'more,events,map,courses,courses.show,lecturers.show,mine,mine.places,mine.teaching,profile', 'icon' => 'squares-2x2', 'label' => 'Mehr', 'gate' => 'guest'],
    ];
}

return $config;
