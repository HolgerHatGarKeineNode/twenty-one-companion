@props([
    'title' => null,
    'heading' => null,
    'chrome' => true,
    'back' => null,
])

@php
    use App\Services\BrandResolver;
    use App\Services\PortalAuth;

    // Verbindungsstatus + Profil für den Flyout-Header (Phase 2.5).
    // freshProfile() liefert den Cache, stößt aber höchstens alle 15 Min einen
    // Live-Refresh an, damit serverseitige Rollenänderungen (Leader → Orga-
    // Button) app-weit ankommen, ohne pro Seitenaufruf zu netzwerken.
    $portalAuth = app(PortalAuth::class);
    $connected = $portalAuth->hasToken();
    $profile = $connected ? $portalAuth->freshProfile() : null;

    // Marke aus der gewählten Region (vom UI-Sprach-Locale entkoppelt).
    $brand = app(BrandResolver::class)->current();

    // Listendichte (Phase C2): „compact“ verdichtet die Browse-Listen.
    $density = app(\App\Services\AppPreferences::class)->density();
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-zinc-50 font-sans text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <flux:toast.group>
            <flux:toast position="top center"/>
        </flux:toast.group>

        <div @class(['flex min-h-dvh flex-col', 'density-compact' => $density === 'compact'])>
            @if ($chrome)
                <header class="pt-safe px-safe sticky top-0 z-20 border-b border-zinc-200 bg-zinc-50/90 backdrop-blur-md dark:border-zinc-800 dark:bg-zinc-950/90">
                    <div class="flex h-14 items-center gap-3 px-4">
                        @if ($back)
                            {{-- Detailseite: Chevron + Seitentitel (Phase 2.4). --}}
                            <flux:button
                                :href="$back"
                                wire:navigate
                                variant="ghost"
                                icon="chevron-left"
                                :aria-label="__('Zurück')"
                                x-on:click="$haptic('light')"
                                class="-ms-2 cursor-pointer"
                            />
                            <flux:heading size="lg" class="min-w-0 truncate !leading-none tracking-wide">{{ $heading ? __($heading) : $brand->label() }}</flux:heading>
                        @else
                            {{-- Top-Level: markenspezifische Wortmarke als Branding (Seitenkontext
                                 liefert die Bottom-Nav). Wechselt live auf Regionswechsel. --}}
                            <x-brand-wordmark-live class="h-6 w-auto shrink-0 text-zinc-900 dark:text-zinc-100"/>
                        @endif
                        <flux:spacer/>
                        {{ $actions ?? '' }}
                        {{-- ── Neither a magnifier nor a hamburger up here any more (P2) ──
                             The magnifier opened `global-search`, the hamburger the flyout.
                             Both are gone: search is the CENTRE slot of the shared bottom bar
                             (Concept C, D6), and what the flyout listed lives on Start („Alle
                             Bereiche") and under „Ich".

                             Two search affordances on one screen were the drift this phase
                             removes — the magnifier here and the search slot down there would
                             have been the same question asked twice, twelve pixels apart. --}}
                    </div>
                </header>
            @endif

            {{-- Kein Pull-to-Refresh mehr (zu aggressiv beim Scrollen). Aktualisiert
                 wird bewusst nur über den Refresh-Button oben rechts im Header. --}}
            <main class="px-safe flex-1 overflow-y-auto">
                @if ($chrome && $connected)
                    {{-- Einmaliger Hinweis für Meetup-Leader auf die neuen
                         Anmeldungs-/Sichtbarkeits-Einstellungen. --}}
                    <livewire:meetup-privacy-hint-banner/>
                @endif
                {{-- The shared bottom nav is `fixed` (not sticky) → the content needs floor
                     clearance so the last row does not disappear behind the bar (pb-28, same
                     as `app-shell`). The `pb-8` literal of the old sticky legacy bar is gone
                     with that bar. --}}
                <div @class(['page-enter', 'p-4 pb-28' => $chrome])>
                    {{ $slot }}
                </div>
            </main>

            @if ($chrome)
                {{-- Kontextsensitiver Create-FAB (Phase 2.1). --}}
                <x-create-fab/>

                {{-- The ONE shell nav (Concept C): the same three slots the package renders
                     in the chat — Start · Search · Postfach. The `config('group.nav')`
                     registry this used to iterate is gone from every host with P2, and so is
                     the legacy 5-tab bar that stood in the other branch of this `@if`. --}}
                <x-group::bottom-nav/>

                {{-- ── The bridge from the search slot to this app's search ───────────────
                     `<x-group::bottom-nav>` dispatches `open-command-palette`, and the
                     command palette listening for it hangs in the group package's layout
                     (`<x-group::command-palette/>` in `group::einundzwanzig`). That layout
                     does not run on these Folio pages, so without this listener the slot
                     would do nothing here.

                     **Why the bridge survives P2 even though the plan lists it for deletion.**
                     Its deletion in the plan's approach hangs on the Portal pages having moved
                     onto the package layout — and that move is P4 (D9), not P2. Deleting the
                     listener now would ship a dead button on the app's most used screens for
                     one phase. It goes with `global-search` in P4, in the same edit that gives
                     these pages the real palette.

                     No longer behind a feature flag: there is only one shell. --}}
                <div
                    x-data
                    x-on:open-command-palette.window="$flux.modal('global-search').show()"
                    hidden
                ></div>

                {{-- The app's own search (Phase 2.3). Until P4 it is what the search slot
                     opens; on these pages it is also the fitting one — it finds meetups,
                     courses and lecturers, which is what these pages are about. --}}
                <livewire:global-search/>

                {{-- Editor-Sheets (Phase 4/5/6): Meetup-Editor besitzt `create-meetup`,
                     Termin-Editor `create-event`, Venue-Editor `create-venue`,
                     City-Editor `create-city`. Geöffnet vom FAB, den „Meine“-Listen,
                     der Termin-Verwaltung und den inline-Stadt-Flows. Nur für
                     verbundene Nutzer — Schreiben braucht ein Token. --}}
                @if ($connected)
                    {{-- Discovery-First: „Meetup aussuchen“ (Phase 4.3) öffnet den
                         Picker, der bestehende Meetups zu „Meine“ hinzufügt, statt
                         Duplikate anzulegen. --}}
                    <livewire:meetup-picker/>
                    <livewire:meetup-editor/>
                    {{-- Leader-Delegation: Sheet hinter dem „Leader verwalten“-Button
                         im Meetup-Editor (öffnet via open-meetup-leaders). --}}
                    <livewire:meetup-leaders/>
                    <livewire:event-editor/>
                    <livewire:venue-editor/>
                    <livewire:city-editor/>
                    {{-- Kurse & Referenten (Phase 7): Referenten-Editor besitzt
                         `create-lecturer`, Kurs-Editor `create-course`, Kurs-Event-
                         Editor `create-course-event`. Geöffnet aus /mine/teaching,
                         den Detail-Seiten und den inline-Referenten-/Ort-Flows. --}}
                    <livewire:lecturer-editor/>
                    <livewire:course-editor/>
                    <livewire:course-event-editor/>

                    {{-- Geteiltes Bild-Crop-Overlay: die Editoren mit Logo/Avatar
                         (Meetup/Kurs/Referent) schneiden ihr native gewähltes Bild
                         hier per cropperjs zu (siehe HandlesImageUpload). --}}
                    <x-image-cropper-overlay/>
                @endif

                {{-- ── The hamburger flyout is gone (P2, D2) ──────────────────────────
                     A `flux:modal` with a profile header, three grouped navlists (Entdecken ·
                     Meine Inhalte · Einstellungen) and a version footer stood here, opened by
                     the hamburger in the header. It was the app's answer to a navigation with
                     no level above the tabs.

                     That level exists now and it is a PAGE, not a drawer: Start carries „Alle
                     Bereiche", and „Ich" carries the identity, „Meine Inhalte" and the
                     settings. A drawer is something you open; a page is something you can
                     link to, come back to and share. --}}
            @endif
        </div>

        @fluxScripts

        {{-- Sync the background worker with the push switch (it needs the pubkey from
             `localStorage`, which only the client knows). It also hangs in the chat layout
             (`group::einundzwanzig`) — until P2 signed-in users were sent there by the launch
             page and would never have seen this layout; since P2 both layouts are reachable
             from Start, which makes the second mount point necessary rather than defensive. --}}
        @include('partials.push-sync')
    </body>
</html>
