@props([
    'title' => null,
    'heading' => null,
    'chrome' => true,
    'back' => null,
])

@php
    use App\Services\BrandResolver;
    use App\Services\PortalAuth;

    // Verbindungsstatus + gecachtes Profil für den Flyout-Header (Phase 2.5).
    // cachedProfile() ist netzwerkfrei — kein API-Call pro Seitenaufruf.
    $portalAuth = app(PortalAuth::class);
    $connected = $portalAuth->hasToken();
    $profile = $connected ? $portalAuth->cachedProfile() : null;

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

        {{-- Vollbild-Zelebrierung beim Regionswechsel (Marke wechselt). --}}
        <x-brand-switch-overlay/>

        <div
            @class(['flex min-h-dvh flex-col', 'density-compact' => $density === 'compact'])
            @if ($chrome)
                x-data="appRefresh"
                @portal-refreshed.window="onDone()"
            @endif
        >
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
                        {{-- Manueller Refresh (Phase A2): löst den globalen portal-refresh-
                             Event aus (Layout-Chrome liegt ausserhalb der Seiten-Komponente,
                             wire:click bände hier nicht). Icon dreht, bis portal-refreshed
                             zurückkommt. --}}
                        <flux:button
                            x-on:click="trigger()"
                            x-bind:disabled="refreshing"
                            x-bind:class="refreshing && '[&_svg]:animate-spin'"
                            variant="ghost"
                            icon="arrow-path"
                            :aria-label="__('Aktualisieren')"
                            class="cursor-pointer"
                        />
                        <flux:modal.trigger name="global-search">
                            <flux:button variant="ghost" icon="magnifying-glass" :aria-label="__('Suche')" class="cursor-pointer"/>
                        </flux:modal.trigger>
                        <flux:modal.trigger name="main-menu">
                            <flux:button variant="ghost" icon="bars-3" :aria-label="__('Menü')" class="-me-2 cursor-pointer"/>
                        </flux:modal.trigger>
                    </div>
                </header>
            @endif

            <main
                class="px-safe flex-1 overflow-y-auto"
                @if ($chrome)
                    @touchstart.passive="onStart($event)"
                    @touchmove.passive="onMove($event)"
                    @touchend="onEnd()"
                @endif
            >
                @if ($chrome)
                    {{-- Pull-to-Refresh-Indikator (Phase A3): wächst beim Ziehen, das Icon
                         dreht proportional und spinnt während des Aktualisierens. --}}
                    <div
                        class="flex items-center justify-center overflow-hidden"
                        x-bind:class="!dragging && 'transition-[height] duration-300 ease-out'"
                        x-bind:style="`height:${pull}px`"
                        aria-hidden="true"
                    >
                        <flux:icon
                            name="arrow-path"
                            class="size-6 text-zinc-400"
                            x-bind:class="refreshing && 'animate-spin'"
                            x-bind:style="!refreshing && `transform: rotate(${pull * 3}deg)`"
                        />
                    </div>
                @endif
                <div @class(['page-enter', 'p-4 pb-8' => $chrome])>
                    {{ $slot }}
                </div>
            </main>

            @if ($chrome)
                {{-- Kontextsensitiver Create-FAB (Phase 2.1). --}}
                <x-create-fab/>

                <nav class="pb-safe px-safe sticky bottom-0 z-20 border-t border-zinc-200 bg-zinc-50/90 backdrop-blur-md dark:border-zinc-800 dark:bg-zinc-950/90">
                    <div class="grid grid-cols-4">
                        <x-bottom-nav-item route="meetups" match="meetups,meetups.show" icon="user-group" :label="__('Meetups')"/>
                        <x-bottom-nav-item route="events" icon="calendar-days" :label="__('Termine')"/>
                        <x-bottom-nav-item route="map" icon="map" :label="__('Karte')"/>
                        <x-bottom-nav-item route="profile" match="profile,mine" icon="user-circle" :label="__('Profil')"/>
                    </div>
                </nav>

                {{-- Globale Suche (Phase 2.3), per Header-Lupe geöffnet. --}}
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
                @endif

                <flux:modal name="main-menu" variant="flyout" class="menu-flyout !p-0">
                    <div class="pt-safe flex h-dvh flex-col">
                        {{-- Profil-Header mit Avatar + Verbindungsstatus (Phase 2.5). --}}
                        <a href="{{ route('profile') }}" wire:navigate x-on:click="$haptic('light')" class="pressable flex items-center gap-3 border-b border-zinc-200 p-4 active:bg-zinc-50 dark:border-zinc-800 dark:active:bg-zinc-900">
                            @if ($connected && ($profile['avatar'] ?? null))
                                <flux:avatar src="{{ $profile['avatar'] }}" size="lg"/>
                            @elseif ($connected)
                                <flux:avatar size="lg" name="{{ $profile['name'] ?? $brand->label() }}"/>
                            @else
                                <flux:avatar size="lg" icon="user"/>
                            @endif
                            <div class="min-w-0 leading-tight">
                                <flux:heading size="md" class="truncate">
                                    {{ $connected ? ($profile['name'] ?? __('Verbunden')) : __('Gast') }}
                                </flux:heading>
                                <span class="mt-0.5 flex items-center gap-1.5">
                                    <span @class(['size-2 rounded-full', 'bg-green-500' => $connected, 'bg-zinc-400' => ! $connected])></span>
                                    <flux:text class="text-xs">{{ $connected ? __('Mit Portal verbunden') : __('Nicht verbunden') }}</flux:text>
                                </span>
                            </div>
                        </a>

                        <div class="flex-1 overflow-y-auto">
                            {{-- Entdecken --}}
                            <flux:navlist class="p-2">
                                <flux:navlist.group :heading="__('Entdecken')">
                                    {{-- Kurse & Referenten teilen die /courses-Route (Tab via ?tab).
                                         Flux erkennt „current" sonst nur am Pfad → beide Items leuchten
                                         gleichzeitig. Daher explizites :current am Query-Param. --}}
                                    @php($onCourses = request()->routeIs('courses'))
                                    <flux:navlist.item href="{{ route('courses') }}" :current="$onCourses && request('tab') !== 'referenten'" wire:navigate icon="academic-cap">
                                        {{ __('Kurse') }}
                                    </flux:navlist.item>
                                    <flux:navlist.item href="{{ route('courses', ['tab' => 'referenten']) }}" :current="$onCourses && request('tab') === 'referenten'" wire:navigate icon="user">
                                        {{ __('Referenten') }}
                                    </flux:navlist.item>
                                    <flux:navlist.item href="{{ route('map', ['tab' => 'staedte']) }}" wire:navigate icon="building-office-2">
                                        {{ __('Städte & Orte') }}
                                    </flux:navlist.item>
                                </flux:navlist.group>

                                <flux:navlist.group :heading="__('Meine Inhalte')" class="mt-2">
                                    <flux:navlist.item href="{{ route('mine') }}" wire:navigate icon="square-2-stack">
                                        {{ __('Meine Inhalte') }}
                                    </flux:navlist.item>
                                </flux:navlist.group>

                                <flux:navlist.group :heading="__('Einstellungen')" class="mt-2">
                                    <flux:navlist.item href="{{ route('profile') }}" wire:navigate icon="cog-6-tooth">
                                        {{ __('Einstellungen') }}
                                    </flux:navlist.item>
                                </flux:navlist.group>
                            </flux:navlist>
                        </div>

                        <div class="pb-safe border-t border-zinc-200 p-4 dark:border-zinc-800">
                            <flux:text class="text-xs">
                                {{ $brand->appName() }} · {{ __('Version :version', ['version' => config('nativephp.version')]) }}
                            </flux:text>
                        </div>
                    </div>
                </flux:modal>
            @endif
        </div>

        @fluxScripts
    </body>
</html>
