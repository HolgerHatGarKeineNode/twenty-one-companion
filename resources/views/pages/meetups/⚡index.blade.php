<?php

use App\Data\Portal\MapMeetupData;
use App\Data\Portal\MeetupData;
use App\Livewire\Concerns\HandlesPortalWriteFeedback;
use App\Livewire\PortalPage;
use App\Services\CountryOptions;
use App\Services\PortalApi;
use App\Services\PortalAuth;
use App\Services\PortalWriter;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

new #[Layout('layouts::mobile', ['title' => 'Meetups', 'heading' => 'Meetups'])] class extends PortalPage
{
    use HandlesPortalWriteFeedback;

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

    /** Länder-Filter wechselt zugleich die App-Region/Marke (Logo + Animation). */
    public function updatedCountry(): void
    {
        $this->syncBrand($this->country);
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
            // Wie im Portal: Meetups mit dem nächsten kommenden Termin zuerst
            // (frühestes Datum vorn), Meetups ohne Termin ans Ende, dann nach Name.
            ->sortBy(fn (MapMeetupData $meetup): array => [
                $meetup->next_event === null,
                $meetup->next_event?->start->getTimestamp() ?? 0,
                mb_strtolower($meetup->name),
            ])
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

    /**
     * Ein Meetup wieder aus „Meine Meetups" entfernen (löst serverseitig die
     * meetup_user-Pivot, per Slug — Gegenstück zum Aussuchen im Picker). Die
     * Stammdaten bleiben erhalten; bei Erfolg verschwindet das Meetup sofort
     * aus dem „Meine"-Tab.
     */
    public function removeFromMine(string $slug): void
    {
        $result = app(PortalWriter::class)->removeMeetupFromMine($slug);

        if ($result->successful()) {
            unset($this->myMeetups);
            Flux::toast(text: __('Meetup aus „Meine“ entfernt.'), variant: 'success');
            $this->js("window.haptic && window.haptic('success')");

            return;
        }

        $this->reportWriteFailure($result, __('Dieses Meetup konnte nicht entfernt werden.'));
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
                    {{-- QoL: aktiver Länderfilter ist beim ersten Öffnen der Default (defaultCountry) —
                         ein Tap raus aus der leeren Region statt Select öffnen und scrollen. --}}
                    @if ($country !== '')
                        <x-reset-country-filter/>
                    @endif
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
                                <x-meetup-name :name="$meetup->name"/>
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
                    {{ __('Such zuerst dein Meetup — gibt es das in deiner Stadt schon, füge es zu „Meine“ hinzu, statt ein Duplikat anzulegen.') }}
                </flux:text>
                {{-- Discovery-First (Phase 4.3): bestehendes Meetup aussuchen, statt
                     vorschnell ein Duplikat anzulegen. Anlegen ist der zweite Weg. --}}
                <flux:button
                    type="button"
                    variant="primary"
                    icon="magnifying-glass"
                    x-on:click="$haptic('medium'); $flux.modal('pick-meetup').show(); Livewire.dispatch('open-meetup-picker')"
                    class="cursor-pointer"
                >
                    {{ __('Meetup aussuchen') }}
                </flux:button>
                <flux:button
                    type="button"
                    variant="ghost"
                    size="sm"
                    icon="plus"
                    x-on:click="$haptic('medium'); $flux.modal('create-meetup').show(); Livewire.dispatch('open-meetup-editor')"
                    class="cursor-pointer"
                >
                    {{ __('Neues Meetup anlegen') }}
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
                                <x-meetup-name :name="$meetup->name"/>
                                @if ($meetup->is_active)
                                    <flux:badge color="green" size="sm" class="w-fit">{{ __('Aktiv') }}</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm" class="w-fit">{{ __('Inaktiv') }}</flux:badge>
                                @endif
                            </span>
                        </a>
                        {{-- Bearbeiten nur für Leader dieses Meetups (Leader-Modell).
                             Nicht-Leader-Mitglieder sehen Karte + Entfernen, aber
                             keinen Edit-Button. --}}
                        @if ($meetup->is_leader)
                            <flux:button
                                type="button"
                                variant="ghost"
                                icon="pencil-square"
                                :aria-label="__('Meetup bearbeiten')"
                                x-on:click="$haptic('light'); $flux.modal('create-meetup').show(); Livewire.dispatch('open-meetup-editor', { id: {{ $meetup->id }} })"
                                class="shrink-0 cursor-pointer"
                            />
                        @endif
                        {{-- Aus „Meine“ entfernen (Phase 1.2.1): löst nur die Pivot-
                             Zuordnung, die Stammdaten bleiben. Bestätigung gegen
                             versehentliches Entfernen. --}}
                        <flux:button
                            type="button"
                            variant="ghost"
                            icon="trash"
                            :aria-label="__('Aus „Meine“ entfernen')"
                            wire:click="removeFromMine(@js($meetup->slug))"
                            wire:confirm="{{ __('Dieses Meetup aus „Meine Meetups“ entfernen? Die Stammdaten bleiben erhalten.') }}"
                            x-on:click="$haptic('medium')"
                            wire:loading.attr="disabled"
                            wire:target="removeFromMine"
                            class="shrink-0 cursor-pointer"
                        />
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</x-portal-page>
