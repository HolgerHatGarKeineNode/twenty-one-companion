{{-- `config('group.meetup_map_view')` — the map view of `/bereich/meetups?ansicht=karte`
     (P4, D9). Included by the package with `$meetups` (list<PortalMeetup>) in scope.

     ── Why Leaflet is loaded LAZILY ─────────────────────────────────────────────────
     Leaflet + MarkerCluster are ~150 kB and they are needed on exactly ONE view of this
     app. Up to P3 they sat in the main entry (`resources/js/app.js`, a top-level import) and
     were therefore parsed on EVERY page — including onboarding and „Meine Inhalte". Since
     the two Vite entries became one (P4), `window.loadLeaflet()` fetches them here, on the
     first render of this view.

     `wire:ignore` stays mandatory: Leaflet writes into the same node, and a Livewire
     re-render would clear away its tiles and markers. New markers therefore reach the map
     through a browser event, not through a Blade re-render. --}}

@php
    /*
     * The markers are built SERVER-side, popup HTML included: a meetup's name comes from
     * Portal data, and `e()` is the boundary here. A popup assembled in the browser from raw
     * data would be an HTML sink in a place nobody looks at any more.
     */
    $marker = collect($meetups)
        ->filter(fn ($meetup) => $meetup->latitude !== 0.0 || $meetup->longitude !== 0.0)
        ->map(fn ($meetup) => [
            'lat' => $meetup->latitude,
            'lng' => $meetup->longitude,
            'popup' => sprintf(
                '<div class="map-popup"><strong>%s</strong><span>%s</span><a href="%s">%s</a></div>',
                e($meetup->name),
                e(trim($meetup->city.' · '.$meetup->country, ' ·')),
                e(route('group.bereich.meetups.show', $meetup->slug)),
                e(__('Zum Meetup')),
            ),
        ])
        ->values()
        ->all();
@endphp

@if ($marker === [])
    <div class="surface-card empty-state flex flex-col items-center gap-3 p-8 text-center" data-portal-karte="leer">
        <flux:icon.map class="size-8 text-muted" aria-hidden="true" />
        <flux:heading size="lg">{{ __('Karte nicht verfügbar') }}</flux:heading>
        <flux:text class="max-w-xs text-sm text-muted">
            {{ __('Es liegen gerade keine Meetup-Orte vor.') }}
        </flux:text>
    </div>
@else
    <div wire:key="meetup-karte" wire:ignore data-portal-karte="{{ count($marker) }}"
         x-data="{
             map: null,
             layer: null,
             icon: null,
             async init() {
                 const L = await window.loadLeaflet()
                 this.map = L.map(this.$refs.karte).setView([50.9, 10.3], 5)
                 window.addBaseMap(L, this.map, @js(config('maps.tiles')))
                 this.icon = L.icon({
                     iconUrl: @js(asset('img/btc_marker.png')),
                     iconSize: [32, 32],
                     iconAnchor: [16, 32],
                     popupAnchor: [0, -32],
                 })
                 {{-- Clustering: dense marker clouds (all of DE) become counting clusters
                      that break open on zoom — instead of an illegible clump of pins. --}}
                 this.layer = L.markerClusterGroup({
                     maxClusterRadius: 50,
                     showCoverageOnHover: false,
                     chunkedLoading: true,
                 }).addTo(this.map)
                 this.zeichne(L, @js($marker))
             },
             zeichne(L, marker) {
                 this.layer.clearLayers()
                 if (! marker.length) {
                     return
                 }
                 this.layer.addLayers(marker.map((m) => L.marker([m.lat, m.lng], { icon: this.icon }).bindPopup(m.popup)))
                 {{-- `fitBounds` centres AND zooms onto the markers: a country filter needs
                      no coordinate table for that. The maxZoom cap prevents over-zooming on
                      a single marker. --}}
                 this.map.fitBounds(L.latLngBounds(marker.map((m) => [m.lat, m.lng])).pad(0.2), { maxZoom: 12 })
             },
         }">
        <div x-ref="karte"
             class="z-0 h-[calc(100dvh-20rem)] min-h-80 w-full rounded-card border border-zinc-200 dark:border-zinc-800"></div>
    </div>
@endif
