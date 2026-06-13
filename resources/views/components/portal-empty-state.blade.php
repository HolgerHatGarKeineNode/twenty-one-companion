@props([
    'icon',
    'heading',
    'errorHeading' => null,
    'minHeight' => 'min-h-[40dvh]',
])

{{-- Leeres Ergebnis einer Portal-Seite: unterscheidet selbst zwischen
     Verbindungsfehler (Daten fehlen komplett → Retry) und „wirklich leer“.
     Immer NACH dem Datenzugriff der Seite rendern, sonst steht der Status
     noch nicht fest. --}}
@if (app(\App\Services\PortalApi::class)->hasMissingData())
    <x-error-state :heading="$errorHeading" :min-height="$minHeight"/>
@else
    <x-empty-state :icon="$icon" :heading="$heading" :min-height="$minHeight">
        {{ $slot }}
    </x-empty-state>
@endif
