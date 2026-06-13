@props([
    'heading' => null,
    'minHeight' => 'min-h-[40dvh]',
])

{{-- Verbindungsfehler-Zustand: wie x-empty-state, aber mit „Erneut versuchen“
     (erwartet die retry-Action der PortalPage-Basisklasse). --}}
<x-empty-state icon="signal-slash" :heading="$heading ?? __('Keine Verbindung zum Portal')" :min-height="$minHeight">
    <flux:text class="max-w-xs">
        {{ __('Die Daten konnten nicht geladen werden. Prüfe deine Internetverbindung und versuche es erneut.') }}
    </flux:text>
    <flux:button wire:click="retry" size="sm" icon="arrow-path" class="mt-2 cursor-pointer">
        {{ __('Erneut versuchen') }}
    </flux:button>
</x-empty-state>
