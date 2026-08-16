@props([
    'name' => null,
    'heading' => null,
])

{{-- Wiederverwendbares Bottom-Sheet (Phase 1.8) für Quick-Actions, Filter und
     Create-Flows. Baut auf dem Flux-Bottom-Flyout auf (von unten einfahrend,
     Spring), ergänzt um einen Greifer und gerundete obere Ecken (siehe
     app.css). Steuerung wie ein flux:modal — per wire:model oder name +
     <flux:modal.trigger>. --}}
{{-- `closable="false"` schaltet NUR den Schließen-Knopf des Pakets ab (ESC und
     Klick daneben hängen an `dismissible` und bleiben) — wir zeichnen ihn gleich
     selbst, weil sein aria-label sonst in jeder Sprache „Close modal" hieße:
     der Modal-Stub ist `@blaze(fold: true)`, und Blaze backt das Ergebnis von
     `__()` beim Kompilieren ein. Hier im eigenen Template läuft `__()` wieder
     pro Request. Markup bewusst identisch zum Stub. --}}
<flux:modal
    :name="$name"
    variant="flyout"
    position="bottom"
    :closable="false"
    {{ $attributes->class('pb-safe !rounded-t-sheet') }}
>
    <div class="mx-auto -mt-4 mb-5 h-1.5 w-10 shrink-0 rounded-full bg-zinc-300 dark:bg-zinc-600" aria-hidden="true"></div>

    @if ($heading)
        <flux:heading size="lg" class="mb-4">{{ $heading }}</flux:heading>
    @endif

    {{ $slot }}

    <div class="absolute top-0 end-0 mt-4 me-4">
        <flux:modal.close>
            <flux:button
                variant="ghost"
                icon="x-mark"
                size="sm"
                :aria-label="__('Fenster schließen')"
                class="text-zinc-400! hover:text-zinc-800! dark:text-zinc-500! dark:hover:text-white!"
            />
        </flux:modal.close>
    </div>
</flux:modal>
