@props([
    'icon',
    'heading',
    'errorHeading' => null,
    'minHeight' => 'min-h-[40dvh]',
])

{{-- Leeres Ergebnis einer Portal-Seite: unterscheidet selbst zwischen
     abgelaufener Sitzung (HTTP 401 → Reconnect), Verbindungsfehler (Daten
     fehlen komplett → Retry) und „wirklich leer“. Immer NACH dem Datenzugriff
     der Seite rendern, sonst steht der Status noch nicht fest.
     Vorrang: 401 vor Netzfehler — ein 401 setzt zwar auch missingData (keine
     Stale-Kopie), soll aber ehrlich als „Sitzung abgelaufen“ erscheinen. --}}
@if (app(\App\Services\PortalApi::class)->hasAuthExpired())
    <x-session-expired-state :min-height="$minHeight"/>
@elseif (app(\App\Services\PortalApi::class)->hasMissingData())
    <x-error-state :heading="$errorHeading" :min-height="$minHeight"/>
@else
    <x-empty-state :icon="$icon" :heading="$heading" :min-height="$minHeight">
        {{ $slot }}
    </x-empty-state>
@endif
