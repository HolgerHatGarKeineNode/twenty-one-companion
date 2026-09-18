<?php

use App\Services\AppPreferences;

/*
 * ══ The companion's view of the package shell (Concept C, P2) ═══════════════════════
 *
 * `unified_shell`, `exit` and `nav` are GONE from this file. They were the three keys of
 * the two-shell era: a flag that switched between a legacy 5-tab bar with a hamburger
 * flyout and a merged 4-tab bar, an exit link back out of the chat takeover, and a per-host
 * tab registry. Since Concept C there is ONE shell with three fixed slots
 * (Start · Search · Postfach), and what a host may still redirect are the flat keys below.
 *
 * `settings_route` is gone as well, and that is the more interesting deletion: it pointed at
 * this app's own `/profile` screen, because that screen carried the Portal preferences AND
 * the Nostr sections while the package hub carried a second, thinner version of the same
 * sections. Now the app's own sections are INJECTED into the package hub (`view:` entries in
 * `settings`), so there is one settings place and no config line is needed to say where it is.
 */
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
    'vite' => ['resources/css/group.css', 'resources/js/app.js'],

    /*
     * ── The area tiles on Start ───────────────────────────────────────────────────
     *
     * No override any more (P4). Up to P3 this file redirected `meetups` and `kurse` to
     * this app's own pages, because the package had none — D9 built them in P4, so both
     * tiles now lead to the package routes in every host. The `AreaRegistry::defaults()`
     * argument stays available for the next host that has a better page for one area.
     */

    /*
     * ── The „Ich" page ───────────────────────────────────────────────────────────
     *
     * Plus "Meine Inhalte" (`/ich/inhalte`), which only this app has: creating and editing
     * meetups, dates, venues and courses needs a Portal token, and the package knows
     * nothing about one. Injected with a `view:` prefix, the same mechanism as the settings
     * sections below.
     */
    'ich' => ['identitaet', 'wallet', 'view:partials.ich.inhalte', 'verein', 'lesezeichen', 'einstellungen'],

    /*
     * ── The settings hub — ONE place, and this app's sections live INSIDE it ──────
     *
     * Until P2 this app had its own settings screen (`pages/profile`) and pointed
     * `settings_route` at it, because the Portal preferences are Livewire server state and
     * the package hub has none. The package hub now understands `view:` entries: the entry
     * is included as a HOST view, and each of those views mounts a Livewire component of
     * its own — which is where the server state lives.
     *
     * Order follows the user's mental model: identity → the service bound to it → space →
     * region → appearance → notifications → the advanced relay/media block → about →
     * sign out.
     *
     * `session` is deliberately NOT in this list: the package's own partial signs out of
     * the NOSTR session only. In this app signing out also has to revoke the Portal token,
     * so `view:partials.settings.logout` replaces it — one sign-out, not two.
     *
     * @var list<string>
     */
    'settings' => [
        'account',
        'view:partials.settings.portal-connect',
        'space',
        'view:partials.settings.region',
        'appearance',
        'view:partials.settings.push',
        'relays',
        'blossom',
        'view:partials.settings.about',
        'view:partials.settings.logout',
    ],

    /*
     * The views `/bereich/meetups` offers. This app adds `karte`: the map needs Leaflet and
     * the device's location, so it is the one view only the app can bind.
     *
     * @var list<string>
     */
    'meetup_views' => ['liste', 'termine', 'karte'],

    /*
     * …and this is the view that renders it (P4). It lives HERE and not in the package for
     * one measured reason: Leaflet plus marker clustering is ~150 kB, and the package is
     * embedded by the association's web app for four chat views. A map in that embed would
     * be weight nobody there asked for. On the web the package therefore links to the
     * Portal's own map instead (`meetup_map_view => null` is the default).
     *
     * The view is included with `$meetups` (list<PortalMeetup>) in scope.
     */
    'meetup_map_view' => 'partials.portal.karte',

    /*
     * The block at the end of a Portal detail page. On the web this is a link INTO the
     * Portal („Im Portal bearbeiten"); in this app it is the editor sheet, because only the
     * app carries a Portal token — the one host-specific exception to D9's read-only rule.
     */
    'portal_detail_actions' => 'partials.portal.detail-aktionen',

    /*
     * The REST arm of the RSVP surface (D12/P5). The package binds it exactly where it
     * may not write a kind 31925 itself: at a date without a published kind 31923, and at a
     * meetup that keeps its attendance private (D12a). Only this app can send that answer —
     * it needs a Portal token, which the package does not have. On the web the key stays
     * `null` and the link into the Portal stands there instead.
     */
    'portal_rsvp_view' => 'partials.portal.rsvp',

    /*
     * The app's region as the default of the country filter on `/bereich/meetups` (P5).
     *
     * Until P5 this app had a meetup list of its own, and it opened on the region the user
     * chose during onboarding. The list has lived in the package since P4 (D9) and knows
     * nothing about an app region — without this line that behaviour would have disappeared
     * with the deleted page, and silently. A closure, because the value is decided per USER
     * (the stored region) while a config file is read once per boot.
     */
    'meetup_default_land' => fn (): string => app(AppPreferences::class)->country(),
];
