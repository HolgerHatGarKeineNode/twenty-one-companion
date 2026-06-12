@props([
    'title' => null,
    'heading' => null,
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
            <header class="pt-safe px-safe sticky top-0 z-20 border-b border-zinc-200 bg-zinc-50/90 backdrop-blur-md dark:border-zinc-800 dark:bg-zinc-950/90">
                <div class="flex h-14 items-center gap-3 px-4">
                    <x-brand-logo aria-label="EINUNDZWANZIG" class="size-8 text-zinc-900 dark:text-zinc-100"/>
                    <flux:heading size="lg" class="!leading-none tracking-wide">{{ $heading ?? 'EINUNDZWANZIG' }}</flux:heading>
                    <flux:spacer/>
                    {{ $actions ?? '' }}
                </div>
            </header>

            <main class="px-safe flex-1 overflow-y-auto">
                <div class="p-4 pb-8">
                    {{ $slot }}
                </div>
            </main>

            <nav class="pb-safe px-safe sticky bottom-0 z-20 border-t border-zinc-200 bg-zinc-50/90 backdrop-blur-md dark:border-zinc-800 dark:bg-zinc-950/90">
                <div class="grid grid-cols-4">
                    <x-bottom-nav-item route="home" icon="home" :label="__('Start')"/>
                    <x-bottom-nav-item route="meetups" match="meetups,meetups.show" icon="map-pin" :label="__('Meetups')"/>
                    <x-bottom-nav-item route="events" icon="calendar-days" :label="__('Termine')"/>
                    <x-bottom-nav-item route="settings" match="settings,profile.edit,appearance.edit,security.edit" icon="cog-6-tooth" :label="__('Einstellungen')"/>
                </div>
            </nav>
        </div>

        @fluxScripts
    </body>
</html>
