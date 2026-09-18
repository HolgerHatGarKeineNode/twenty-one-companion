<?php

use App\Data\Portal\CityData;
use App\Data\Portal\CountryData;
use App\Data\Portal\MyCityData;
use App\Data\Portal\MyVenueData;
use App\Data\Portal\VenueData;
use App\Livewire\PortalPage;
use App\Services\CountryOptions;
use App\Services\PortalApi;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * „Meine Orte & Städte“ (Phase 6.4): Verwaltungsseite für die eigenen Städte und
 * Veranstaltungsorte. Auth-gated über <x-requires-portal>; Anlegen/Bearbeiten
 * laufen über die im Layout eingebetteten City-/Venue-Editoren (Sheets), die
 * nach dem Speichern `places-changed` melden → die Listen laden neu.
 *
 * ── P5: „Alle" arrives here from the deleted map page ────────────────────────
 *
 * `/map` had three tabs: map, cities, places. The MAP has lived in the package since P4
 * (`/bereich/meetups?ansicht=karte`, the one view only this chassis can bind) — after
 * that the two LISTS sat on a page that existed only because of them. So they move here,
 * as a second SCOPE next to „Meine": this is where they belong by subject (they are
 * cities and places), and whoever creates a place of their own first looks whether the
 * city already exists anyway.
 *
 * Two axes and not four tabs: „Städte | Orte" times „Meine | Alle" fits into two bars,
 * while four tabs with the German labels overflow at 390 px.
 */
new #[Layout('layouts::mobile', ['title' => 'Meine Orte & Städte', 'heading' => 'Orte & Städte', 'back' => '/ich/inhalte'])] class extends PortalPage
{
    #[Url]
    public string $tab = 'staedte';

    /** `meine` | `alle` — one's own stock or the Portal's (P5). */
    #[Url]
    public string $umfang = 'meine';

    /** Search term, in the „Alle" scope only (one's own stock is short). */
    #[Url(as: 'q')]
    public string $search = '';

    /** Country/region filter in the „Alle" scope; empty = every country. */
    #[Url]
    public string $country = '';

    public function mount(): void
    {
        // An unknown scope falls back to one's own stock — that is the page this address
        // carries; „Alle" is the guest on it.
        if (! in_array($this->umfang, ['meine', 'alle'], true)) {
            $this->umfang = 'meine';
        }
        $this->country = $this->defaultCountry();
    }

    public function updatedUmfang(): void
    {
        $this->search = '';
    }

    /**
     * Every city of the Portal, filtered by region and search term (P5, from `/map`).
     *
     * `withDetails: true` lifts the Portal's limit of 10 — the same call and therefore the
     * same cache entry the package map and the venue editor use.
     *
     * @return Collection<int, CityData>
     */
    #[Computed]
    public function alleStaedte(): Collection
    {
        $search = mb_strtolower(trim($this->search));
        $country = mb_strtolower($this->country);

        return app(PortalApi::class)
            ->cities(withDetails: true)
            ->filter(fn (CityData $city): bool => $country === '' || $city->countryCode() === $country)
            ->filter(fn (CityData $city): bool => $search === ''
                || str_contains(mb_strtolower($city->name), $search)
                || str_contains(mb_strtolower($city->country->name), $search))
            ->values();
    }

    /**
     * Alle Veranstaltungsorte des Portals, gefiltert wie die Städte (P5, von `/map`).
     *
     * @return Collection<int, VenueData>
     */
    #[Computed]
    public function alleOrte(): Collection
    {
        $search = mb_strtolower(trim($this->search));
        $country = mb_strtolower($this->country);

        return app(PortalApi::class)
            ->venues(withDetails: true)
            ->filter(fn (VenueData $venue): bool => $country === '' || $venue->countryCode() === $country)
            ->filter(fn (VenueData $venue): bool => $search === ''
                || str_contains(mb_strtolower($venue->name), $search)
                || (is_string($venue->description) && str_contains(mb_strtolower($venue->description), $search)))
            ->values();
    }

    /**
     * Country codes for the region filter — taken from the cities, because every place lies
     * in a city and the city list is the more complete of the two.
     *
     * @return list<string>
     */
    #[Computed]
    public function countries(): array
    {
        return CountryOptions::filterCodes(
            app(PortalApi::class)->cities(withDetails: true)->map(fn (CityData $city): string => $city->countryCode()),
            $this->country,
        );
    }

    /**
     * @return Collection<int, MyCityData>
     */
    #[Computed]
    public function myCities(): Collection
    {
        return app(PortalApi::class)->myCities();
    }

    /**
     * @return Collection<int, MyVenueData>
     */
    #[Computed]
    public function myVenues(): Collection
    {
        return app(PortalApi::class)->myVenues();
    }

    /**
     * Landesnamen für die eigenen Städte (über die distinct country_ids; ein
     * Aufruf mit selected hebt das 10er-Limit für genau diese Länder auf).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function countryNames(): array
    {
        $ids = $this->myCities->pluck('country_id')->unique()->values();

        if ($ids->isEmpty()) {
            return [];
        }

        return app(PortalApi::class)
            ->countries(selected: $ids->all())
            ->mapWithKeys(fn (CountryData $country): array => [$country->id => $country->name])
            ->all();
    }

    /**
     * Stadtnamen für die eigenen Orte (aus der gecachten Städte-Liste, derselbe
     * withDetails-Call wie die Karten-Seite).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function cityNames(): array
    {
        if ($this->myVenues->isEmpty()) {
            return [];
        }

        return app(PortalApi::class)
            ->cities(withDetails: true)
            ->mapWithKeys(fn (CityData $city): array => [$city->id => $city->name])
            ->all();
    }

    #[On('places-changed')]
    public function refreshLists(): void
    {
        unset($this->myCities, $this->myVenues, $this->countryNames, $this->cityNames, $this->alleStaedte, $this->alleOrte);
    }
};
?>

<x-portal-page>
    <x-requires-portal :heading="__('Mit Portal verbinden')" :text="__('Verbinde dein Konto, um deine eigenen Orte und Städte zu verwalten.')">
        <flux:tabs wire:model.live="tab" variant="segmented" class="w-full">
            <flux:tab name="staedte">{{ __('Städte') }}</flux:tab>
            <flux:tab name="orte">{{ __('Orte') }}</flux:tab>
        </flux:tabs>

        {{-- The second axis: one's own stock or the Portal's (P5, from the deleted map
             page). Two bars instead of four tabs — four German labels overflow at
             390 px. --}}
        <flux:tabs wire:model.live="umfang" variant="segmented" class="w-full" data-orte-umfang>
            <flux:tab name="meine" data-orte-umfang-tab="meine">{{ __('Meine') }}</flux:tab>
            <flux:tab name="alle" data-orte-umfang-tab="alle">{{ __('Alle') }}</flux:tab>
        </flux:tabs>

        @if ($umfang === 'alle')
            {{-- Read-only: the Portal's cities and places to look something up before
                 creating one's own. No edit button — somebody else's master data is changed
                 in the Portal, not here (the one exception the Portal API allows are the
                 OSM fields of a city, and those belong in the city editor). --}}
            <flux:input
                wire:model.live.debounce.300ms="search"
                type="search"
                icon="magnifying-glass"
                data-orte-suche
                :aria-label="$tab === 'staedte' ? __('Stadt oder Land suchen') : __('Ort oder Stadt suchen')"
                :placeholder="$tab === 'staedte' ? __('Stadt oder Land suchen …') : __('Ort oder Stadt suchen …')"
                clearable
            />
            {{-- listbox statt nativem Select: der System-Dialog der Android-WebView
                 ignoriert das Dark-Theme (siehe x-locale-radio-group). --}}
            <flux:select variant="listbox" :prefix="__('Region')" wire:model.live="country">
                <flux:select.option value="">🌍 {{ __('Alle Länder') }}</flux:select.option>
                @foreach ($this->countries as $code)
                    <flux:select.option value="{{ $code }}">{{ \App\Services\CountryOptions::flagEmoji($code) }} {{ strtoupper($code) }}</flux:select.option>
                @endforeach
            </flux:select>

            @if ($tab === 'staedte')
                @if ($this->alleStaedte->isEmpty())
                    <x-portal-empty-state icon="building-office-2" :heading="__('Keine Städte gefunden')" :error-heading="__('Städte nicht verfügbar')">
                        <flux:text class="max-w-xs">{{ __('Versuche eine andere Suche.') }}</flux:text>
                    </x-portal-empty-state>
                @else
                    <div class="flex flex-col gap-3" data-orte-liste="staedte">
                        @foreach ($this->alleStaedte as $city)
                            <x-place-card
                                wire:key="alle-city-{{ $city->id }}"
                                :flag="$city->flag"
                                :name="$city->name"
                                :subtitle="$city->country->name"
                            />
                        @endforeach
                    </div>
                @endif
            @else
                @if ($this->alleOrte->isEmpty())
                    <x-portal-empty-state icon="building-storefront" :heading="__('Keine Orte gefunden')" :error-heading="__('Orte nicht verfügbar')">
                        <flux:text class="max-w-xs">{{ __('Versuche eine andere Suche.') }}</flux:text>
                    </x-portal-empty-state>
                @else
                    <div class="flex flex-col gap-3" data-orte-liste="orte">
                        @foreach ($this->alleOrte as $venue)
                            <x-place-card
                                wire:key="alle-venue-{{ $venue->id }}"
                                :flag="$venue->flag"
                                :name="$venue->name"
                                :subtitle="$venue->locationLabel()"
                            />
                        @endforeach
                    </div>
                @endif
            @endif
        @elseif ($tab === 'staedte')
            @if ($this->myCities->isEmpty())
                <x-portal-empty-state icon="building-office-2" :heading="__('Noch keine eigenen Städte')" :error-heading="__('Städte nicht verfügbar')">
                    <flux:text class="max-w-xs">{{ __('Lege eine Stadt an, damit Meetups und Orte ihr zugeordnet werden können.') }}</flux:text>
                    <flux:button
                        type="button"
                        variant="primary"
                        icon="plus"
                        x-on:click="$haptic('medium'); $flux.modal('create-city').show(); Livewire.dispatch('open-city-editor')"
                        class="cursor-pointer"
                    >
                        {{ __('Stadt anlegen') }}
                    </flux:button>
                </x-portal-empty-state>
            @else
                <div class="flex justify-end">
                    <flux:button
                        type="button"
                        size="sm"
                        variant="primary"
                        icon="plus"
                        x-on:click="$haptic('medium'); $flux.modal('create-city').show(); Livewire.dispatch('open-city-editor')"
                        class="cursor-pointer"
                    >
                        {{ __('Stadt anlegen') }}
                    </flux:button>
                </div>

                <div class="list-stagger flex flex-col gap-3">
                    @foreach ($this->myCities as $city)
                        <div
                            class="surface-card flex items-center gap-3 p-4"
                            wire:key="my-city-{{ $city->id }}"
                            style="--i: {{ $loop->index }}"
                        >
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-tile bg-brand-500/10 text-brand-600 dark:text-brand-400">
                                <flux:icon name="building-office-2" class="size-6"/>
                            </span>
                            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="truncate font-semibold">{{ $city->name }}</span>
                                <flux:text class="truncate text-sm">{{ $this->countryNames[$city->country_id] ?? __('Unbekanntes Land') }}</flux:text>
                            </span>
                            <flux:button
                                type="button"
                                variant="ghost"
                                icon="pencil-square"
                                :aria-label="__('Stadt bearbeiten')"
                                x-on:click="$haptic('light'); $flux.modal('create-city').show(); Livewire.dispatch('open-city-editor', { id: {{ $city->id }} })"
                                class="shrink-0 cursor-pointer"
                            />
                        </div>
                    @endforeach
                </div>
            @endif
        @else
            @if ($this->myVenues->isEmpty())
                <x-portal-empty-state icon="building-storefront" :heading="__('Noch keine eigenen Orte')" :error-heading="__('Orte nicht verfügbar')">
                    <flux:text class="max-w-xs">{{ __('Lege einen Veranstaltungsort an, an dem eure Termine stattfinden.') }}</flux:text>
                    <flux:button
                        type="button"
                        variant="primary"
                        icon="plus"
                        x-on:click="$haptic('medium'); $flux.modal('create-venue').show(); Livewire.dispatch('open-venue-editor')"
                        class="cursor-pointer"
                    >
                        {{ __('Ort anlegen') }}
                    </flux:button>
                </x-portal-empty-state>
            @else
                <div class="flex justify-end">
                    <flux:button
                        type="button"
                        size="sm"
                        variant="primary"
                        icon="plus"
                        x-on:click="$haptic('medium'); $flux.modal('create-venue').show(); Livewire.dispatch('open-venue-editor')"
                        class="cursor-pointer"
                    >
                        {{ __('Ort anlegen') }}
                    </flux:button>
                </div>

                <div class="list-stagger flex flex-col gap-3">
                    @foreach ($this->myVenues as $venue)
                        <div
                            class="surface-card flex items-center gap-3 p-4"
                            wire:key="my-venue-{{ $venue->id }}"
                            style="--i: {{ $loop->index }}"
                        >
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-tile bg-brand-500/10 text-brand-600 dark:text-brand-400">
                                <flux:icon name="building-storefront" class="size-6"/>
                            </span>
                            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="truncate font-semibold">{{ $venue->name }}</span>
                                <flux:text class="truncate text-sm">{{ trim(($this->cityNames[$venue->city_id] ?? '').' · '.$venue->street, ' ·') }}</flux:text>
                            </span>
                            <flux:button
                                type="button"
                                variant="ghost"
                                icon="pencil-square"
                                :aria-label="__('Ort bearbeiten')"
                                x-on:click="$haptic('light'); $flux.modal('create-venue').show(); Livewire.dispatch('open-venue-editor', { id: {{ $venue->id }} })"
                                class="shrink-0 cursor-pointer"
                            />
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </x-requires-portal>
</x-portal-page>
