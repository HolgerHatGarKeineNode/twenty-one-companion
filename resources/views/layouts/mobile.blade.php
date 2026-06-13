@props([
    'title' => null,
    'heading' => null,
    'chrome' => true,
])

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="dark">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-dvh bg-zinc-50 font-sans text-zinc-900 antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <flux:toast.group>
            <flux:toast position="top center"/>
        </flux:toast.group>

        <div class="flex min-h-dvh flex-col">
            @if ($chrome)
                <header class="pt-safe px-safe sticky top-0 z-20 border-b border-zinc-200 bg-zinc-50/90 backdrop-blur-md dark:border-zinc-800 dark:bg-zinc-950/90">
                    <div class="flex h-14 items-center gap-3 px-4">
                        <x-brand-logo aria-label="EINUNDZWANZIG" class="size-8 text-zinc-900 dark:text-zinc-100"/>
                        <flux:heading size="lg" class="!leading-none tracking-wide">{{ $heading ? __($heading) : 'EINUNDZWANZIG' }}</flux:heading>
                        <flux:spacer/>
                        {{ $actions ?? '' }}
                        <flux:modal.trigger name="main-menu">
                            <flux:button variant="ghost" icon="bars-3" :aria-label="__('Menü')" class="-me-2 cursor-pointer"/>
                        </flux:modal.trigger>
                    </div>
                </header>
            @endif

            <main class="px-safe flex-1 overflow-y-auto">
                <div @class(['page-enter', 'p-4 pb-8' => $chrome])>
                    {{ $slot }}
                </div>
            </main>

            @if ($chrome)
                <nav class="pb-safe px-safe sticky bottom-0 z-20 border-t border-zinc-200 bg-zinc-50/90 backdrop-blur-md dark:border-zinc-800 dark:bg-zinc-950/90">
                    <div class="grid grid-cols-4">
                        <x-bottom-nav-item route="meetups" match="meetups,meetups.show" icon="user-group" :label="__('Meetups')"/>
                        <x-bottom-nav-item route="events" icon="calendar-days" :label="__('Termine')"/>
                        <x-bottom-nav-item route="map" icon="map" :label="__('Karte')"/>
                        <x-bottom-nav-item route="profile" icon="user-circle" :label="__('Profil')"/>
                    </div>
                </nav>

                <flux:modal name="main-menu" variant="flyout" class="!p-0">
                    <div class="pt-safe flex h-dvh flex-col">
                        <div class="flex items-center gap-3 border-b border-zinc-200 p-4 dark:border-zinc-800">
                            <x-brand-logo aria-hidden="true" class="size-9 text-zinc-900 dark:text-zinc-100"/>
                            <div class="leading-tight">
                                <flux:heading size="md" class="tracking-wide">EINUNDZWANZIG</flux:heading>
                                <flux:text class="text-xs">{{ __('Die Bitcoin-Community') }}</flux:text>
                            </div>
                        </div>

                        <flux:navlist class="p-2">
                            <flux:navlist.item href="{{ route('courses') }}" wire:navigate icon="academic-cap">
                                {{ __('Kurse') }}
                            </flux:navlist.item>
                            <flux:navlist.item href="{{ route('courses', ['tab' => 'referenten']) }}" wire:navigate icon="user">
                                {{ __('Referenten') }}
                            </flux:navlist.item>
                            <flux:navlist.item href="{{ route('map', ['tab' => 'staedte']) }}" wire:navigate icon="building-office-2">
                                {{ __('Städte & Orte') }}
                            </flux:navlist.item>

                            <flux:separator class="my-2"/>

                            <flux:navlist.item href="{{ route('profile') }}" wire:navigate icon="cog-6-tooth">
                                {{ __('Einstellungen') }}
                            </flux:navlist.item>
                        </flux:navlist>

                        <flux:spacer/>

                        <div class="pb-safe border-t border-zinc-200 p-4 dark:border-zinc-800">
                            <flux:text class="text-xs">
                                {{ __('EINUNDZWANZIG App') }} · {{ __('Version :version', ['version' => config('nativephp.version')]) }}
                            </flux:text>
                        </div>
                    </div>
                </flux:modal>
            @endif
        </div>

        @fluxScripts
    </body>
</html>
