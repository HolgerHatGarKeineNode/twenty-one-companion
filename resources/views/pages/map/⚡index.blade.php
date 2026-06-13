<?php

use App\Data\Portal\CityData;
use App\Data\Portal\MapMeetupData;
use App\Data\Portal\VenueData;
use App\Livewire\PortalPage;
use App\Services\PortalApi;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;

new #[Layout('layouts::mobile', ['title' => 'Karte', 'heading' => 'Orte & Karte'])] class extends PortalPage {
    #[Url]
    public string $tab = 'karte';

    #[Url(as: 'q')]
    public string $search = '';

    /**
     * Marker für die Leaflet-Karte: Koordinaten plus serverseitig
     * escaptes Popup-HTML (Name, Stadt, Link zum Meetup-Detail).
     * Nutzt denselben withIntro/withLogos-Call wie die Meetup-Liste,
     * damit beide Seiten einen gemeinsamen Cache-Eintrag teilen.
     *
     * @return list<array{lat: float, lng: float, popup: string}>
     */
    #[Computed]
    public function markers(): array
    {
        return app(PortalApi::class)
            ->mapMeetups(withIntro: true, withLogos: true)
            ->map(fn (MapMeetupData $meetup): array => [
                'lat' => $meetup->latitude,
                'lng' => $meetup->longitude,
                'popup' => sprintf(
                    '<div class="map-popup"><strong>%s</strong><span>%s</span><a href="%s">%s</a></div>',
                    e($meetup->name),
                    e($meetup->city.' · '.$meetup->country),
                    e(route('meetups.show', $meetup->slug())),
                    e(__('Zum Meetup')),
                ),
            ])
            ->values()
            ->all();
    }

    /**
     * Alle Städte (withDetails hebt das 10er-Limit des Portals auf),
     * gefiltert nach Suchbegriff in Stadt- oder Landesname.
     *
     * @return Collection<int, CityData>
     */
    #[Computed]
    public function cities(): Collection
    {
        $search = mb_strtolower(trim($this->search));

        return app(PortalApi::class)
            ->cities(withDetails: true)
            ->filter(fn (CityData $city): bool => $search === ''
                || str_contains(mb_strtolower($city->name), $search)
                || str_contains(mb_strtolower($city->country->name), $search))
            ->values();
    }

    /**
     * Alle Veranstaltungsorte (withDetails hebt das 10er-Limit auf),
     * gefiltert nach Suchbegriff in Name oder Beschreibung (Stadt, Straße).
     *
     * @return Collection<int, VenueData>
     */
    #[Computed]
    public function venues(): Collection
    {
        $search = mb_strtolower(trim($this->search));

        return app(PortalApi::class)
            ->venues(withDetails: true)
            ->filter(fn (VenueData $venue): bool => $search === ''
                || str_contains(mb_strtolower($venue->name), $search)
                || (is_string($venue->description) && str_contains(mb_strtolower($venue->description), $search)))
            ->values();
    }
};
?>

<div class="flex flex-col gap-4">
    <flux:tabs wire:model.live="tab" variant="segmented" class="w-full">
        <flux:tab name="karte">{{ __('Karte') }}</flux:tab>
        <flux:tab name="staedte">{{ __('Städte') }}</flux:tab>
        <flux:tab name="orte">{{ __('Orte') }}</flux:tab>
    </flux:tabs>

    @if ($tab === 'karte')
        @if ($this->markers === [])
            <x-error-state :heading="__('Karte nicht verfügbar')"/>
        @else
            <div
                wire:key="meetup-map"
                wire:ignore
                x-data="{
                    initializeMap() {
                        const map = L.map(this.$refs.map).setView([50.9, 10.3], 5);

                        L.tileLayer('https://tile.openstreetmap.de/{z}/{x}/{y}.png', {
                            minZoom: 2,
                            maxZoom: 18,
                            attribution: @js('&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'),
                        }).addTo(map);

                        const icon = L.icon({
                            iconUrl: @js(asset('img/btc_marker.png')),
                            iconSize: [32, 32],
                            iconAnchor: [16, 32],
                            popupAnchor: [0, -32],
                        });

                        @js($this->markers).forEach((marker) => {
                            L.marker([marker.lat, marker.lng], { icon })
                                .bindPopup(marker.popup)
                                .addTo(map);
                        });
                    },
                }"
                x-init="initializeMap()"
            >
                <div
                    x-ref="map"
                    class="z-0 h-[calc(100dvh-17rem)] min-h-96 w-full rounded-2xl border border-zinc-200 dark:border-zinc-800"
                ></div>
            </div>
        @endif
    @else
        <flux:input
            wire:model.live.debounce.300ms="search"
            type="search"
            icon="magnifying-glass"
            :placeholder="$tab === 'staedte' ? __('Stadt oder Land suchen …') : __('Ort oder Stadt suchen …')"
            clearable
        />

        @if ($tab === 'staedte')
            @if ($this->cities->isEmpty())
                <x-portal-empty-state icon="building-office-2" :heading="__('Keine Städte gefunden')" :error-heading="__('Städte nicht verfügbar')">
                    <flux:text class="max-w-xs">{{ __('Versuche eine andere Suche.') }}</flux:text>
                </x-portal-empty-state>
            @else
                <div class="flex flex-col gap-3">
                    @foreach ($this->cities as $city)
                        <x-place-card
                            wire:key="city-{{ $city->id }}"
                            :flag="$city->flag"
                            :name="$city->name"
                            :subtitle="$city->country->name"
                        />
                    @endforeach
                </div>
            @endif
        @else
            @if ($this->venues->isEmpty())
                <x-portal-empty-state icon="building-storefront" :heading="__('Keine Orte gefunden')" :error-heading="__('Orte nicht verfügbar')">
                    <flux:text class="max-w-xs">{{ __('Versuche eine andere Suche.') }}</flux:text>
                </x-portal-empty-state>
            @else
                <div class="flex flex-col gap-3">
                    @foreach ($this->venues as $venue)
                        <x-place-card
                            wire:key="venue-{{ $venue->id }}"
                            :flag="$venue->flag"
                            :name="$venue->name"
                            :subtitle="$venue->locationLabel()"
                        />
                    @endforeach
                </div>
            @endif
        @endif
    @endif

    {{-- Als letztes Kind, damit der Status NACH den API-Zugriffen feststeht (order-first zeigt es oben). --}}
    <x-portal-status/>
</div>
