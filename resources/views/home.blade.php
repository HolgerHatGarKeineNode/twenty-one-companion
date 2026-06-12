<x-layouts::mobile :title="__('Start')" :heading="__('EINUNDZWANZIG')">
    <div class="flex flex-col gap-6">
        <section class="relative overflow-hidden rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="pointer-events-none absolute -end-10 -top-10 size-40 rounded-full bg-brand-500/10 blur-2xl"></div>
            <x-brand-logo aria-hidden="true" class="mb-4 size-14 text-zinc-900 dark:text-zinc-100"/>
            <flux:heading size="xl" level="1">{{ __('Willkommen bei EINUNDZWANZIG') }}</flux:heading>
            <flux:text class="mt-2">
                {{ __('Die Bitcoin-Community im deutschsprachigen Raum — Meetups, Termine und mehr, direkt in deiner Tasche.') }}
            </flux:text>
            <flux:badge color="orange" size="sm" class="mt-4">{{ __('Frühe Vorschau') }}</flux:badge>
        </section>

        <livewire:portal.connect/>

        <section class="flex flex-col gap-3">
            <flux:heading size="lg" level="2">{{ __('Entdecken') }}</flux:heading>

            <x-list-link-card href="{{ route('meetups') }}">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-500/15 text-brand-600 dark:text-brand-400">
                    <flux:icon name="map-pin" class="size-6"/>
                </span>
                <span class="flex min-w-0 flex-col">
                    <span class="font-semibold">{{ __('Meetups') }}</span>
                    <flux:text class="truncate text-sm">{{ __('Finde Bitcoiner in deiner Nähe') }}</flux:text>
                </span>
            </x-list-link-card>

            <x-list-link-card href="{{ route('events') }}">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-500/15 text-brand-600 dark:text-brand-400">
                    <flux:icon name="calendar-days" class="size-6"/>
                </span>
                <span class="flex min-w-0 flex-col">
                    <span class="font-semibold">{{ __('Termine') }}</span>
                    <flux:text class="truncate text-sm">{{ __('Kommende Meetup-Termine im Überblick') }}</flux:text>
                </span>
            </x-list-link-card>

            <x-list-link-card href="{{ route('settings') }}">
                <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-500/15 text-brand-600 dark:text-brand-400">
                    <flux:icon name="cog-6-tooth" class="size-6"/>
                </span>
                <span class="flex min-w-0 flex-col">
                    <span class="font-semibold">{{ __('Einstellungen') }}</span>
                    <flux:text class="truncate text-sm">{{ __('Profil, Darstellung und Sicherheit') }}</flux:text>
                </span>
            </x-list-link-card>
        </section>
    </div>
</x-layouts::mobile>
