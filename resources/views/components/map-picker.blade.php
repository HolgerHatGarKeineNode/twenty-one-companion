@props([
    'latitude' => null,
    'longitude' => null,
    // Livewire-Methode der umgebenden Komponente, die die gewählte Koordinate
    // entgegennimmt (lat, lng). Parametrisierbar, damit künftige Picker (z. B.
    // Kurs-Venues, Phase 7) denselben Component ohne Kopie wiederverwenden.
    'method' => 'setCoordinates',
])

{{-- Karten-Picker (Phase 6.3): Leaflet-Karte zum Setzen einer Koordinate per
     Tap/Drag. Reuse der Karten-Konfiguration der Karten-Seite (window.L via
     resources/js/app.js, OpenStreetMap-DE-Tiles, BTC-Marker). Meldet die
     gewählte Koordinate über $wire.setCoordinates(lat, lng) an die umgebende
     Livewire-Komponente zurück; die manuellen Lat/Lng-Inputs daneben bleiben
     als Fallback (und Testpfad, da Leaflet nicht serverseitig läuft).

     wire:ignore schützt den Leaflet-DOM vor Livewire-Morphs. invalidateSize
     nach dem Sheet-Übergang korrigiert die Größe im erst spät sichtbaren
     Bottom-Sheet. --}}
<div
    wire:ignore
    x-data="{
        map: null,
        marker: null,
        init() {
            const hasStart = {{ $latitude !== null && $longitude !== null ? 'true' : 'false' }};
            const start = hasStart ? [{{ $latitude }}, {{ $longitude }}] : [51.0, 10.0];

            this.map = L.map(this.$refs.picker).setView(start, hasStart ? 13 : 5);

            {{-- Dunkle Tiles, zentral aus config/maps.php (dark-only Chrome). --}}
            L.tileLayer(@js(config('maps.tiles.url')), @js(config('maps.tiles.options'))).addTo(this.map);

            const icon = L.icon({
                iconUrl: @js(asset('img/btc_marker.png')),
                iconSize: [32, 32],
                iconAnchor: [16, 32],
                popupAnchor: [0, -32],
            });

            if (hasStart) {
                this.place(start[0], start[1], icon);
            }

            this.map.on('click', (event) => {
                this.place(event.latlng.lat, event.latlng.lng, icon);
            });

            // Sheet fährt animiert ein → Größe nach dem Übergang neu berechnen.
            setTimeout(() => this.map.invalidateSize(), 350);
        },
        place(lat, lng, icon) {
            const rLat = Math.round(lat * 1e6) / 1e6;
            const rLng = Math.round(lng * 1e6) / 1e6;

            if (this.marker) {
                this.marker.setLatLng([rLat, rLng]);
            } else {
                this.marker = L.marker([rLat, rLng], { icon, draggable: true }).addTo(this.map);
                this.marker.on('dragend', () => {
                    const pos = this.marker.getLatLng();
                    this.place(pos.lat, pos.lng, icon);
                });
            }

            $wire.{{ $method }}(rLat, rLng);
        },
    }"
>
    <div
        x-ref="picker"
        class="z-0 h-56 w-full rounded-tile border border-zinc-200 dark:border-zinc-800"
    ></div>
</div>
