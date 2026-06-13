{{-- Navigations-Karte für Listen: Inhalt im Slot, Chevron rechts.
     AAA-Niveau (Phase 1.6): weiche Elevation (shadow-card), Press-State mit
     Active-Scale (.pressable) und sofortiges haptisches Tap-Feedback. --}}
<a
    {{ $attributes->class('surface-card pressable group flex items-center gap-4 p-4 active:bg-zinc-50 dark:active:bg-zinc-800') }}
    wire:navigate
    x-on:click="$haptic('light')"
>
    {{ $slot }}
    <flux:icon name="chevron-right" class="ms-auto size-5 shrink-0 text-zinc-400 transition-transform group-active:translate-x-0.5"/>
</a>
