@php($portalApi = app(\App\Services\PortalApi::class))

{{-- Verbindungs-Banner: als LETZTES Kind des Seiten-Roots einbinden (flex-col),
     damit der Offline-/Stale-Status NACH allen API-Zugriffen des Renders
     feststeht; `order-first` schiebt es optisch nach oben. --}}
{{-- Fehlen die Daten komplett, übernimmt der Fehler-State der Seite — dann kein Banner. --}}
@if ($portalApi->servedStaleData() || ($portalApi->isOffline() && ! $portalApi->hasMissingData()))
    <div
        {{ $attributes->class('portal-status order-first flex items-center gap-2.5 rounded-xl border border-amber-500/25 bg-amber-500/10 px-3 py-2 text-amber-700 dark:text-amber-300') }}
        role="status"
    >
        <span class="relative flex size-2 shrink-0" aria-hidden="true">
            <span class="absolute inline-flex size-full animate-ping rounded-full bg-amber-500/60"></span>
            <span class="relative inline-flex size-2 rounded-full bg-amber-500"></span>
        </span>
        <span class="text-sm leading-tight">
            {{ $portalApi->isOffline()
                ? __('Offline — du siehst zuletzt geladene Daten.')
                : __('Verbindungsproblem — Daten sind möglicherweise nicht aktuell.') }}
        </span>
    </div>
@endif
