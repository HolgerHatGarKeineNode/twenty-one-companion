{{-- Navigations-Karte für Listen: Inhalt im Slot, Chevron rechts.
     AAA-Niveau (Phase 1.6): weiche Elevation (shadow-card), Press-State mit
     Active-Scale (.pressable) und sofortiges haptisches Tap-Feedback.

     `navigate=false` erzwingt einen harten Seiten-Load statt wire:navigate — nötig
     für Links, die ins Chat-Bundle (group.js) führen: wire:navigate trägt den
     <head> mit, sodass group.js dort nie via alpine:init bootet (Signer-Banner +
     nostrAuth blieben uninitialisiert). --}}
@props(['navigate' => true])
<a
    {{ $attributes->class('surface-card pressable group flex items-center gap-4 p-4 active:bg-zinc-50 dark:active:bg-zinc-800') }}
    @if ($navigate) wire:navigate @endif
    x-on:click="$haptic('light')"
>
    {{ $slot }}
    <flux:icon name="chevron-right" class="ms-auto size-5 shrink-0 text-zinc-400 transition-transform group-active:translate-x-0.5"/>
</a>
