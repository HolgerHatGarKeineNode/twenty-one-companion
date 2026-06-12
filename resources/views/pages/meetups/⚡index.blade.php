<?php

use App\Data\Portal\MapMeetupData;
use App\Data\Portal\MeetupData;
use App\Services\PortalApi;
use App\Services\PortalAuth;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::mobile', ['title' => 'Meetups', 'heading' => 'Meetups'])] class extends Component {
    #[Url(as: 'q')]
    public string $search = '';

    #[Url]
    public string $country = '';

    #[Url]
    public string $tab = 'alle';

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

        return $this->allMeetups()
            ->filter(fn (MapMeetupData $meetup): bool => $this->country === '' || $meetup->country === $this->country)
            ->filter(fn (MapMeetupData $meetup): bool => $search === ''
                || str_contains(mb_strtolower($meetup->name), $search)
                || str_contains(mb_strtolower($meetup->city), $search))
            ->sortBy(fn (MapMeetupData $meetup): string => mb_strtolower($meetup->name))
            ->values();
    }

    /**
     * Ländercodes aller Meetups für den Filter.
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function countries(): Collection
    {
        return $this->allMeetups()->pluck('country')->unique()->sort()->values();
    }

    /**
     * Vom Nutzer selbst erstellte Meetups (Tab „Meine").
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

<div class="flex flex-col gap-4">
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
                <flux:select.option value="">{{ __('Alle Länder') }}</flux:select.option>
                @foreach ($this->countries as $code)
                    <flux:select.option value="{{ $code }}">{{ $code }}</flux:select.option>
                @endforeach
            </flux:select>
        </div>

        @if ($this->meetups->isEmpty())
            <x-empty-state icon="map-pin" :heading="__('Keine Meetups gefunden')">
                <flux:text class="max-w-xs">
                    {{ $search !== '' || $country !== ''
                        ? __('Versuche eine andere Suche oder einen anderen Länderfilter.')
                        : __('Die Meetups konnten nicht geladen werden. Prüfe deine Internetverbindung und versuche es erneut.') }}
                </flux:text>
            </x-empty-state>
        @else
            <div class="flex flex-col gap-3">
                @foreach ($this->meetups as $meetup)
                    <x-list-link-card
                        href="{{ route('meetups.show', $meetup->slug()) }}"
                        wire:key="meetup-{{ $meetup->slug() }}"
                    >
                        <x-meetup-avatar :logo="$meetup->logo" :name="$meetup->name"/>
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span class="truncate font-semibold">{{ $meetup->name }}</span>
                            <flux:text class="truncate text-sm">{{ $meetup->city }} · {{ $meetup->country }}</flux:text>
                            @if ($meetup->next_event)
                                <flux:badge color="orange" size="sm" class="mt-1 w-fit">
                                    {{ $meetup->next_event->start->translatedFormat('D, d. M · H:i') }}
                                </flux:badge>
                            @endif
                        </span>
                    </x-list-link-card>
                @endforeach
            </div>
        @endif
    @else
        @if ($this->myMeetups->isEmpty())
            <x-empty-state icon="user-group" :heading="__('Keine eigenen Meetups')">
                <flux:text class="max-w-xs">
                    {{ __('Du hast im Portal noch keine Meetups angelegt.') }}
                </flux:text>
            </x-empty-state>
        @else
            <div class="flex flex-col gap-3">
                @foreach ($this->myMeetups as $meetup)
                    <x-list-link-card
                        href="{{ route('meetups.show', $meetup->slug) }}"
                        wire:key="my-meetup-{{ $meetup->slug }}"
                    >
                        <x-meetup-avatar :name="$meetup->name"/>
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span class="truncate font-semibold">{{ $meetup->name }}</span>
                            @unless ($meetup->is_active)
                                <flux:badge color="zinc" size="sm" class="mt-1 w-fit">{{ __('Inaktiv') }}</flux:badge>
                            @endunless
                        </span>
                    </x-list-link-card>
                @endforeach
            </div>
        @endif
    @endif
</div>
