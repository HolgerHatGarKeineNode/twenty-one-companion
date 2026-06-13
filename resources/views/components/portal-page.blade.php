{{-- Wurzel der Portal-Modulseiten: Blade rendert den Slot VOR diesem Template,
     deshalb steht der Offline-/Stale-Status der API-Zugriffe fest, wenn das
     Banner als letztes Kind rendert (order-first zeigt es optisch oben). --}}
<div {{ $attributes->class('flex flex-col gap-4') }}>
    {{ $slot }}
    <x-portal-status/>
</div>
