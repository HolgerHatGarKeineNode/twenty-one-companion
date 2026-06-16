<?php

use App\Data\Portal\CityData;
use App\Data\Portal\MapMeetupData;
use App\Data\Portal\VenueData;
use App\Livewire\PortalPage;
use App\Services\CountryOptions;
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

    /** Länder-/Regionsfilter (Code); leer = „Alle Länder“ (Welt-Modus). */
    #[Url]
    public string $country = '';

    public function mount(): void
    {
        $this->country = $this->defaultCountry();
    }

    /**
     * Region wechseln: Marke synchronisieren (Logo + Animation) und die
     * Karte neu einpassen. Die Map selbst ist wire:ignore, deshalb bekommt
     * Alpine die frisch gefilterten Marker per Browser-Event und ruft
     * fitBounds auf — das zentriert und zoomt automatisch aufs Land.
     */
    public function updatedCountry(): void
    {
        $this->syncBrand($this->country);
        $this->dispatch('map-country-changed', markers: $this->markers());
    }

    /**
     * Marker für die Leaflet-Karte: Koordinaten plus serverseitig
     * escaptes Popup-HTML (Name, Stadt, Link zum Meetup-Detail), gefiltert
     * nach Region. Nutzt denselben withIntro/withLogos-Call wie die
     * Meetup-Liste, damit beide Seiten einen gemeinsamen Cache-Eintrag teilen.
     *
     * @return list<array{lat: float, lng: float, popup: string}>
     */
    #[Computed]
    public function markers(): array
    {
        $country = $this->selectedCountry();

        return $this->allMeetups()
            ->filter(fn (MapMeetupData $meetup): bool => $country === '' || $meetup->countryCode() === $country)
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

    /** Ob das Portal überhaupt Karten-Meetups liefert (Gate für den Fehler-State). */
    #[Computed]
    public function hasMeetups(): bool
    {
        return $this->allMeetups()->isNotEmpty();
    }

    /**
     * Ländercodes aller Meetups für den Regionsfilter.
     *
     * @return list<string>
     */
    #[Computed]
    public function countries(): array
    {
        return CountryOptions::filterCodes(
            $this->allMeetups()->map(fn (MapMeetupData $meetup): string => $meetup->country),
            $this->country,
        );
    }

    /**
     * Städte (withDetails hebt das 10er-Limit des Portals auf), gefiltert
     * nach Region (Ländercode der verschachtelten country) und Suchbegriff.
     *
     * @return Collection<int, CityData>
     */
    #[Computed]
    public function cities(): Collection
    {
        $search = mb_strtolower(trim($this->search));
        $country = $this->selectedCountry();

        return app(PortalApi::class)
            ->cities(withDetails: true)
            ->filter(fn (CityData $city): bool => $country === '' || $city->countryCode() === $country)
            ->filter(fn (CityData $city): bool => $search === ''
                || str_contains(mb_strtolower($city->name), $search)
                || str_contains(mb_strtolower($city->country->name), $search))
            ->values();
    }

    /**
     * Veranstaltungsorte (withDetails hebt das 10er-Limit auf), gefiltert
     * nach Region (Ländercode der verschachtelten Stadt) und Suchbegriff.
     *
     * @return Collection<int, VenueData>
     */
    #[Computed]
    public function venues(): Collection
    {
        $search = mb_strtolower(trim($this->search));
        $country = $this->selectedCountry();

        return app(PortalApi::class)
            ->venues(withDetails: true)
            ->filter(fn (VenueData $venue): bool => $country === '' || $venue->countryCode() === $country)
            ->filter(fn (VenueData $venue): bool => $search === ''
                || str_contains(mb_strtolower($venue->name), $search)
                || (is_string($venue->description) && str_contains(mb_strtolower($venue->description), $search)))
            ->values();
    }

    /** Aktuell gewählte Region als Kleinbuchstaben-Code; leer = alle Länder (Welt-Modus). */
    private function selectedCountry(): string
    {
        return mb_strtolower($this->country);
    }

    /** @var Collection<int, MapMeetupData>|null */
    private ?Collection $memoizedMeetups = null;

    /**
     * Pro Request memoisiert: markers() und countries() lesen beide die
     * volle Karten-Antwort, das DTO-Mapping soll aber nur einmal laufen.
     *
     * @return Collection<int, MapMeetupData>
     */
    protected function allMeetups(): Collection
    {
        return $this->memoizedMeetups ??= app(PortalApi::class)->mapMeetups(withIntro: true, withLogos: true);
    }
};
?>

<x-portal-page>
    <flux:tabs wire:model.live="tab" variant="segmented" class="w-full">
        <flux:tab name="karte">{{ __('Karte') }}</flux:tab>
        <flux:tab name="staedte">{{ __('Städte') }}</flux:tab>
        <flux:tab name="orte">{{ __('Orte') }}</flux:tab>
    </flux:tabs>

    {{-- Regionsfilter steuert Karte, Städte und Orte. „Alle Länder“ = Welt-Modus. --}}
    <flux:select wire:model.live="country">
        <flux:select.option value="">🌍 {{ __('Alle Länder') }}</flux:select.option>
        @foreach ($this->countries as $code)
            <flux:select.option value="{{ $code }}">{{ \App\Services\CountryOptions::flagEmoji($code) }} {{ strtoupper($code) }}</flux:select.option>
        @endforeach
    </flux:select>

    @if ($tab === 'karte')
        @if (! $this->hasMeetups)
            <x-error-state :heading="__('Karte nicht verfügbar')"/>
        @else
            <div
                wire:key="meetup-map"
                wire:ignore
                x-data="{
                    map: null,
                    layer: null,
                    icon: null,
                    init() {
                        this.map = L.map(this.$refs.map).setView([50.9, 10.3], 5);

                        L.tileLayer('https://tile.openstreetmap.de/{z}/{x}/{y}.png', {
                            minZoom: 2,
                            maxZoom: 18,
                            attribution: @js('&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors'),
                        }).addTo(this.map);

                        this.icon = L.icon({
                            iconUrl: @js(asset('img/btc_marker.png')),
                            iconSize: [32, 32],
                            iconAnchor: [16, 32],
                            popupAnchor: [0, -32],
                        });

                        // Clustering: dichte Marker-Wolken (z. B. ganz DE) werden zu
                        // zählenden Clustern zusammengefasst, die beim Zoom/Klick
                        // aufbrechen — statt eines unleserlichen Pin-Klumpens.
                        this.layer = L.markerClusterGroup({
                            maxClusterRadius: 50,
                            showCoverageOnHover: false,
                            chunkedLoading: true,
                        }).addTo(this.map);
                        this.render(@js($this->markers));
                    },
                    render(markers) {
                        this.layer.clearLayers();

                        if (! markers.length) {
                            return;
                        }

                        this.layer.addLayers(markers.map((marker) =>
                            L.marker([marker.lat, marker.lng], { icon: this.icon })
                                .bindPopup(marker.popup)
                        ));

                        // fitBounds zentriert UND zoomt automatisch auf die Marker:
                        // dichte Länder (z. B. Ungarn) bekommen mehr Zoom als eine
                        // weltweite Verteilung — ganz ohne Koordinaten-Tabelle. Der
                        // maxZoom-Deckel verhindert Über-Zoom bei nur einem Marker.
                        const points = markers.map((marker) => [marker.lat, marker.lng]);
                        this.map.fitBounds(L.latLngBounds(points).pad(0.2), { maxZoom: 12 });
                    },
                }"
                @map-country-changed.window="render($event.detail.markers)"
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

</x-portal-page>
