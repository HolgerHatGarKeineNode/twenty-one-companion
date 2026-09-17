<?php

use App\Data\Portal\CityData;
use App\Data\Portal\CountryData;
use App\Data\Portal\MyCityData;
use App\Data\Portal\MyVenueData;
use App\Livewire\PortalPage;
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
 */
new #[Layout('layouts::mobile', ['title' => 'Meine Orte & Städte', 'heading' => 'Orte & Städte', 'back' => '/ich/inhalte'])] class extends PortalPage
{
    #[Url]
    public string $tab = 'staedte';

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
        unset($this->myCities, $this->myVenues, $this->countryNames, $this->cityNames);
    }
};
?>

<x-portal-page>
    <x-requires-portal :heading="__('Mit Portal verbinden')" :text="__('Verbinde dein Konto, um deine eigenen Orte und Städte zu verwalten.')">
        <flux:tabs wire:model.live="tab" variant="segmented" class="w-full">
            <flux:tab name="staedte">{{ __('Städte') }}</flux:tab>
            <flux:tab name="orte">{{ __('Orte') }}</flux:tab>
        </flux:tabs>

        @if ($tab === 'staedte')
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
