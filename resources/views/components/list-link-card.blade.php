{{-- Navigations-Karte für Listen: Inhalt im Slot, Chevron rechts. --}}
<a
    {{ $attributes->class('flex items-center gap-4 rounded-2xl border border-zinc-200 bg-white p-4 transition-colors active:bg-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:active:bg-zinc-800') }}
    wire:navigate
>
    {{ $slot }}
    <flux:icon name="chevron-right" class="ms-auto size-5 shrink-0 text-zinc-400"/>
</a>
