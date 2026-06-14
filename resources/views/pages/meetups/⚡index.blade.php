<?php

use App\Data\Portal\MapMeetupData;
use App\Data\Portal\MeetupData;
use App\Livewire\PortalPage;
use App\Services\CountryOptions;
use App\Services\PortalApi;
use App\Services\PortalAuth;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

new #[Layout('layouts::mobile', ['title' => 'Meetups', 'heading' => 'Meetups'])] class extends PortalPage
{
    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $country = '';

    #[Url]
    public string $tab = 'alle';

    public function mount(): void
    {
        $this->country = $this->defaultCountry();
    }

    #[Computed]
    public function connected(): bool
    {
        return app(PortalAuth::class)->hasToken();
    }

    /**
     * Alle Karten-Meetups, gefiltert nach Suchbegriff (Name/Stadt) und Land.
     *
     * @return Collection<int, MapMeetupData>
     */
    #[Computed]
    public function meetups(): Collection
    {
        $search = mb_strtolower(trim($this->search));

        $country = mb_strtolower($this->country);

        return $this->allMeetups()
            ->filter(fn (MapMeetupData $meetup): bool => $country === '' || mb_strtolower($meetup->country) === $country)
            ->filter(fn (MapMeetupData $meetup): bool => $search === ''
                || str_contains(mb_strtolower($meetup->name), $search)
                || str_contains(mb_strtolower($meetup->city), $search))
            ->sortBy(fn (MapMeetupData $meetup): string => mb_strtolower($meetup->name))
            ->values();
    }

    /**
     * Ländercodes aller Meetups für den Filter.
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
     * Die im Portal-Dashboard ausgewählten Meetups des Nutzers (Tab „Meine").
     *
     * @return Collection<int, MeetupData>
     */
    #[Computed]
    public function myMeetups(): Collection
    {
        return app(PortalApi::class)
            ->myMeetups()
            ->sortBy(fn (MeetupData $meetup): string => mb_strtolower($meetup->name))
            ->values();
    }

    /**
     * Nach einem Anlegen/Bearbeiten im Meetup-Editor (Phase 4) die eigene
     * Liste neu laden. Der PortalWriter hat den Cache bereits invalidiert,
     * das Verwerfen des memoisierten Computed erzwingt den frischen Refetch.
     */
    #[On('meetup-saved')]
    public function refreshMyMeetups(): void
    {
        unset($this->myMeetups);
    }

    /** @var Collection<int, MapMeetupData>|null */
    private ?Collection $memoizedMeetups = null;

    /**
     * Pro Request memoisiert: meetups() und countries() lesen beide die
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
    @if ($this->connected)
        <flux:tabs wire:model.live="tab" variant="segmented" class="w-full">
            <flux:tab name="alle">{{ __('Alle Meetups') }}</flux:tab>
            <flux:tab name="meine">{{ __('Meine Meetups') }}</flux:tab>
        </flux:tabs>
    @endif

    @if (! $this->connected || $tab === 'alle')
        <div class="flex flex-col gap-2">
            <flux:input
                wire:model.live.debounce.300ms="search"
                type="search"
                icon="magnifying-glass"
                :placeholder="__('Meetup oder Stadt suchen …')"
                clearable
            />
            <flux:select wire:model.live="country">
                <flux:select.option value="">🌍 {{ __('Alle Länder') }}</flux:select.option>
                @foreach ($this->countries as $code)
                    <flux:select.option value="{{ $code }}">{{ \App\Services\CountryOptions::flagEmoji($code) }} {{ strtoupper($code) }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        {{-- Skeleton beim Filtern/Suchen (Phase 1.4) statt eines springenden Layouts. --}}
        <x-skeleton-card :count="4" wire:loading.flex wire:target="search,country"/>

        <div wire:loading.remove wire:target="search,country">
            @if ($this->meetups->isEmpty())
                <x-portal-empty-state icon="map-pin" :heading="__('Keine Meetups gefunden')" :error-heading="__('Meetups nicht verfügbar')">
                    <flux:text class="max-w-xs">
                        {{ __('Versuche eine andere Suche oder einen anderen Länderfilter.') }}
                    </flux:text>
                </x-portal-empty-state>
            @else
                <div class="list-stagger flex flex-col gap-3">
                    @foreach ($this->meetups as $meetup)
                        <x-list-link-card
                            href="{{ route('meetups.show', $meetup->slug()) }}"
                            wire:key="meetup-{{ $meetup->slug() }}"
                            style="--i: {{ $loop->index }}"
                        >
                            <x-meetup-avatar :logo="$meetup->logo" :name="$meetup->name"/>
                            <span class="flex min-w-0 flex-col gap-0.5">
                                <span class="truncate font-semibold">{{ $meetup->name }}</span>
                                <flux:text class="truncate text-sm">{{ $meetup->city }} · {{ $meetup->country }}</flux:text>
                                @if ($meetup->next_event)
                                    <flux:badge color="orange" size="sm" class="mt-1 w-fit">
                                        {{ $meetup->next_event->start->forDisplay()->translatedFormat('D, d. M · H:i') }}
                                    </flux:badge>
                                @endif
                            </span>
                        </x-list-link-card>
                    @endforeach
                </div>
            @endif
        </div>
    @else
        @if ($this->myMeetups->isEmpty())
            <x-portal-empty-state icon="user-group" :heading="__('Noch keine eigenen Meetups')" :error-heading="__('Meetups nicht verfügbar')">
                <flux:text class="max-w-xs">
                    {{ __('Lege dein erstes Meetup an — es erscheint dann hier und auf der Karte.') }}
                </flux:text>
                <flux:button
                    type="button"
                    variant="primary"
                    icon="plus"
                    x-on:click="$haptic('medium'); $flux.modal('create-meetup').show(); Livewire.dispatch('open-meetup-editor')"
                    class="cursor-pointer"
                >
                    {{ __('Meetup anlegen') }}
                </flux:button>
            </x-portal-empty-state>
        @else
            <div class="list-stagger flex flex-col gap-3">
                @foreach ($this->myMeetups as $meetup)
                    {{-- Eigene Meetups (Phase 4.4): Karte verlinkt ins Detail, Edit-Button
                         öffnet den Editor; Status-Badges zeigen Aktiv/Inaktiv. --}}
                    <div
                        class="surface-card flex items-center gap-3 p-4"
                        wire:key="my-meetup-{{ $meetup->slug }}"
                        style="--i: {{ $loop->index }}"
                    >
                        <a
                            href="{{ route('meetups.show', $meetup->slug) }}"
                            wire:navigate
                            x-on:click="$haptic('light')"
                            class="pressable group flex min-w-0 flex-1 items-center gap-3"
                        >
                            <x-meetup-avatar :logo="$meetup->logo" :name="$meetup->name"/>
                            <span class="flex min-w-0 flex-col gap-1">
                                <span class="truncate font-semibold">{{ $meetup->name }}</span>
                                @if ($meetup->is_active)
                                    <flux:badge color="green" size="sm" class="w-fit">{{ __('Aktiv') }}</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm" class="w-fit">{{ __('Inaktiv') }}</flux:badge>
                                @endif
                            </span>
                        </a>
                        <flux:button
                            type="button"
                            variant="ghost"
                            icon="pencil-square"
                            :aria-label="__('Meetup bearbeiten')"
                            x-on:click="$haptic('light'); $flux.modal('create-meetup').show(); Livewire.dispatch('open-meetup-editor', { id: {{ $meetup->id }} })"
                            class="shrink-0 cursor-pointer"
                        />
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</x-portal-page>
