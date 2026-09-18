{{-- Navigations-Karte für Listen: Inhalt im Slot, Chevron rechts.
     AAA-Niveau (Phase 1.6): weiche Elevation (shadow-card), Press-State mit
     Active-Scale (.pressable) und sofortiges haptisches Tap-Feedback.

     `navigate=false` forces a HARD page load instead of `wire:navigate`.

     ── The reason changed with P4, the rule did not ─────────────────────────────
     It used to be the second JS bundle: a link into the chat loaded `group.js`, which
     registers its components on `alpine:init`, and `wire:navigate` carries the OLD `<head>`
     along — so the island never booted (signer banner and `nostrAuth` stayed
     uninitialised). That file is gone; there is ONE entry since P4, and the components are
     registered on both layouts.

     What has NOT been measured is the other half of the head: the two layouts load
     different STYLESHEETS (`app.css` here, `group.css` there). Until somebody measures a
     soft navigation across that border, the hard load stays — a page that arrives without
     its theme is worse than a page that arrives a moment later. P7 sweeps every route and
     is the place to settle it. --}}
@props(['navigate' => true])
<a
    {{ $attributes->class('surface-card pressable group flex items-center gap-4 p-4 active:bg-zinc-50 dark:active:bg-zinc-800') }}
    @if ($navigate) wire:navigate @endif
    x-on:click="$haptic('light')"
>
    {{ $slot }}
    <flux:icon name="chevron-right" class="ms-auto size-5 shrink-0 text-zinc-400 transition-transform group-active:translate-x-0.5"/>
</a>
