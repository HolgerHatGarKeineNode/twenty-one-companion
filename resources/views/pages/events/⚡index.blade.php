<?php

use App\Data\Portal\MeetupEventData;
use App\Livewire\Concerns\InteractsWithCalendar;
use App\Livewire\Concerns\InteractsWithEventRsvp;
use App\Livewire\PortalPage;
use App\Services\CountryOptions;
use App\Services\PortalApi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Native\Mobile\Facades\Share;

new #[Layout('layouts::mobile', ['title' => 'Termine', 'heading' => 'Termine'])] class extends PortalPage {
    use InteractsWithCalendar;
    use InteractsWithEventRsvp;

    /** Angezeigter Monat im Format Y-m; leer = aktueller Monat. */
    #[Url]
    public string $month = '';

    /** Länderfilter (Code); leer = alle Länder. */
    #[Url]
    public string $country = '';

    /** Index des im Modal geöffneten Termins (Position in $this->events). */
    public ?int $selected = null;

    public bool $showEvent = false;

    public function mount(): void
    {
        $this->country = $this->defaultCountry();
    }

    public function updatedCountry(): void
    {
        $this->selected = null;
        $this->showEvent = false;
        $this->resetRsvp();
        unset($this->events, $this->days, $this->selectedEvent, $this->countries);
        $this->syncBrand($this->country);
    }

    public function previousMonth(): void
    {
        $this->switchMonth($this->monthStart()->subMonth());
    }

    public function nextMonth(): void
    {
        $this->switchMonth($this->monthStart()->addMonth());
    }

    public function select(int $index): void
    {
        if ($this->events->has($index)) {
            $this->selected = $index;
            $this->showEvent = true;
            // selectedEvent neu auflösen, dann RSVP-Status des gewählten Termins laden.
            unset($this->selectedEvent);
            $this->loadRsvp();
        }
    }

    /**
     * Das RSVP des Slide-Ins bezieht sich auf den gerade geöffneten Termin.
     */
    protected function rsvpEventId(): ?int
    {
        return $this->selectedEvent?->id;
    }

    #[Computed]
    public function monthStart(): CarbonImmutable
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $this->month) === 1) {
            return CarbonImmutable::createFromFormat('!Y-m', $this->month);
        }

        return CarbonImmutable::today()->startOfMonth();
    }

    #[Computed]
    public function isCurrentMonth(): bool
    {
        return $this->monthStart()->isSameMonth(CarbonImmutable::today());
    }

    /**
     * Termine des angezeigten Monats, chronologisch und nach Länderfilter.
     * Im aktuellen Monat ab heute (die API filtert ab dem übergebenen
     * Datum bis Monatsende).
     *
     * @return Collection<int, MeetupEventData>
     */
    #[Computed]
    public function events(): Collection
    {
        $from = $this->isCurrentMonth() ? CarbonImmutable::today() : $this->monthStart();

        $country = mb_strtolower($this->country);

        return $this->allEvents($from)
            ->filter(fn (MeetupEventData $event): bool => $country === '' || mb_strtolower($event->meetup->country) === $country)
            ->sortBy(fn (MeetupEventData $event): int => $event->start->getTimestamp())
            ->values();
    }

    /**
     * Ländercodes aller Termine des Monats für den Filter.
     *
     * @return list<string>
     */
    #[Computed]
    public function countries(): array
    {
        $from = $this->isCurrentMonth() ? CarbonImmutable::today() : $this->monthStart();

        return CountryOptions::filterCodes(
            $this->allEvents($from)->map(fn (MeetupEventData $event): string => $event->meetup->country),
            $this->country,
        );
    }

    /**
     * @return Collection<int, MeetupEventData>
     */
    protected function allEvents(CarbonImmutable $from): Collection
    {
        return app(PortalApi::class)->meetupEvents($from->toDateString());
    }

    /**
     * Termine gruppiert nach Tag; die Schlüssel innerhalb der Gruppen
     * bleiben die Indizes aus $this->events (für select()).
     *
     * @return Collection<string, Collection<int, MeetupEventData>>
     */
    #[Computed]
    public function days(): Collection
    {
        return $this->events->groupBy(fn (MeetupEventData $event): string => $event->start->forDisplay()->toDateString(), preserveKeys: true);
    }

    #[Computed]
    public function selectedEvent(): ?MeetupEventData
    {
        return $this->selected === null ? null : $this->events->get($this->selected);
    }

    /**
     * Termin-Link (oder ersatzweise Meetup-Link) über das Share-Sheet teilen.
     */
    public function share(): void
    {
        $event = $this->selectedEvent;

        if ($event === null) {
            return;
        }

        Share::url(
            title: $event->meetup->name,
            text: __(':name am :date', [
                'name' => $event->meetup->name,
                'date' => $event->start->forDisplay()->translatedFormat('d.m.Y · H:i'),
            ]),
            url: $event->link ?? $event->meetup->portalLink,
        );
    }

    /**
     * Den gewählten Termin als .ics erzeugen und ans native Share-Sheet
     * übergeben („Zum Kalender hinzufügen").
     */
    public function addToCalendar(): void
    {
        $event = $this->selectedEvent;

        if ($event === null) {
            return;
        }

        $start = $event->start;

        $this->exportToCalendar(
            title: $event->meetup->name,
            start: $start,
            end: $start->copy()->addMinutes(120), // API liefert kein Ende → 2h-Default.
            location: $event->location,
            description: $event->description,
            filename: 'event-'.($event->id ?? $start->getTimestamp()),
        );
    }

    protected function switchMonth(CarbonImmutable $start): void
    {
        $this->month = $start->isSameMonth(CarbonImmutable::today()) ? '' : $start->format('Y-m');
        $this->selected = null;
        $this->showEvent = false;
        $this->resetRsvp();
        unset($this->monthStart, $this->isCurrentMonth, $this->events, $this->days, $this->selectedEvent, $this->countries);
    }
};
?>

<x-portal-page>
    <div class="flex items-center justify-between gap-2">
        <flux:button wire:click="previousMonth" size="sm" variant="ghost" icon="chevron-left" class="cursor-pointer" :aria-label="__('Voriger Monat')"/>
        <flux:heading size="lg" level="1">
            {{ $this->monthStart->translatedFormat('F Y') }}
        </flux:heading>
        <flux:button wire:click="nextMonth" size="sm" variant="ghost" icon="chevron-right" class="cursor-pointer" :aria-label="__('Nächster Monat')"/>
    </div>

    <flux:select wire:model.live="country">
        <flux:select.option value="">🌍 {{ __('Alle Länder') }}</flux:select.option>
        @foreach ($this->countries as $code)
            <flux:select.option value="{{ $code }}">{{ \App\Services\CountryOptions::flagEmoji($code) }} {{ strtoupper($code) }}</flux:select.option>
        @endforeach
    </flux:select>

    @if ($this->events->isEmpty())
        <x-portal-empty-state icon="calendar-days" :heading="__('Keine Termine')" :error-heading="__('Termine nicht verfügbar')">
            <flux:text class="max-w-xs">
                @if ($country !== '')
                    {{ __('Für diese Region sind in diesem Monat keine Termine eingetragen — wähle „Alle Länder“, um alle Termine zu sehen.') }}
                @else
                    {{ $this->isCurrentMonth
                        ? __('Für den Rest dieses Monats sind keine Meetup-Termine eingetragen.')
                        : __('Für diesen Monat sind keine Meetup-Termine eingetragen.') }}
                @endif
            </flux:text>
            {{-- QoL: statt nur den Hinweis zu lesen, den Länderfilter mit einem Tap zurücksetzen. --}}
            @if ($country !== '')
                <x-reset-country-filter/>
            @endif
        </x-portal-empty-state>
    @else
        @foreach ($this->days as $day => $eventsOfDay)
            <section wire:key="day-{{ $day }}" class="list-stagger flex flex-col gap-2">
                <flux:heading size="sm" level="2" class="text-zinc-500 dark:text-zinc-400">
                    {{ $eventsOfDay->first()->start->forDisplay()->translatedFormat('l, d. F') }}
                </flux:heading>
                @foreach ($eventsOfDay as $index => $event)
                    <button
                        type="button"
                        wire:click="select({{ $index }})"
                        x-on:click="$haptic('medium')"
                        wire:key="event-{{ $index }}"
                        class="surface-card pressable group flex cursor-pointer items-center gap-4 p-4 text-start active:bg-zinc-50 dark:active:bg-zinc-800"
                    >
                        <x-meetup-avatar :logo="$event->meetup->logo" :name="$event->meetup->name"/>
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <x-meetup-name :name="$event->meetup->name"/>
                            <flux:text class="truncate text-sm">
                                {{ $event->start->forDisplay()->format('H:i') }}{{ $event->location ? ' · '.$event->location : '' }}
                            </flux:text>
                        </span>
                        <flux:icon name="chevron-right" class="ms-auto size-5 shrink-0 text-zinc-400"/>
                    </button>
                @endforeach
            </section>
        @endforeach
    @endif

    <x-sheet wire:model="showEvent">
        @if ($this->selectedEvent)
            <div class="flex flex-col gap-4">
                <div class="flex items-center gap-3">
                    <x-meetup-avatar :logo="$this->selectedEvent->meetup->logo" :name="$this->selectedEvent->meetup->name"/>
                    <div class="min-w-0">
                        <flux:heading size="lg">{{ $this->selectedEvent->meetup->name }}</flux:heading>
                        <flux:text class="truncate text-sm">
                            {{ $this->selectedEvent->meetup->city }} · {{ $this->selectedEvent->meetup->country }}
                        </flux:text>
                    </div>
                </div>

                <div class="flex items-center gap-3">
                    <flux:icon name="calendar-days" class="size-5 shrink-0 text-zinc-400"/>
                    <span class="font-semibold">
                        {{ $this->selectedEvent->start->forDisplay()->translatedFormat('l, d. F Y · H:i') }}
                    </span>
                </div>

                @if ($this->selectedEvent->location)
                    <div class="flex items-center gap-3">
                        <flux:icon name="map-pin" class="size-5 shrink-0 text-zinc-400"/>
                        <span>{{ $this->selectedEvent->location }}</span>
                    </div>
                @endif

                @if ($this->selectedEvent->descriptionHtml())
                    <div class="markdown text-sm">
                        {!! $this->selectedEvent->descriptionHtml() !!}
                    </div>
                @endif

                {{-- RSVP (B.5) direkt im Slide-In — sichtbarste Stelle beim Stöbern. --}}
                <x-rsvp-controls
                    :status="$rsvpStatus"
                    :attendees="$rsvpAttendees ?? $this->selectedEvent->attendees"
                    :might-attendees="$rsvpMightAttendees ?? $this->selectedEvent->might_attendees"
                    :can-rsvp="$this->canRsvp()"
                />

                <x-event-action-grid
                    :link="$this->selectedEvent->link"
                    share="share"
                    :meetup-route="route('meetups.show', $this->selectedEvent->meetup->slug())"
                />
            </div>
        @endif
    </x-sheet>
</x-portal-page>
