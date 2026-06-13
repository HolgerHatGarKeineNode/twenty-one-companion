@props([
    'name' => null,
    'heading' => null,
])

{{-- Wiederverwendbares Bottom-Sheet (Phase 1.8) für Quick-Actions, Filter und
     Create-Flows. Baut auf dem Flux-Bottom-Flyout auf (von unten einfahrend,
     Spring), ergänzt um einen Greifer und gerundete obere Ecken (siehe
     app.css). Steuerung wie ein flux:modal — per wire:model oder name +
     <flux:modal.trigger>. --}}
<flux:modal
    :name="$name"
    variant="flyout"
    position="bottom"
    {{ $attributes->class('pb-safe !rounded-t-sheet') }}
>
    <div class="mx-auto -mt-4 mb-5 h-1.5 w-10 shrink-0 rounded-full bg-zinc-300 dark:bg-zinc-600" aria-hidden="true"></div>

    @if ($heading)
        <flux:heading size="lg" class="mb-4">{{ $heading }}</flux:heading>
    @endif

    {{ $slot }}
</flux:modal>
