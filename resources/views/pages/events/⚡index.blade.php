<?php

use App\Data\Portal\MeetupEventData;
use App\Livewire\PortalPage;
use App\Services\PortalApi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Url;
use Native\Mobile\Facades\Share;

new #[Layout('layouts::mobile', ['title' => 'Termine', 'heading' => 'Termine'])] class extends PortalPage {
    /** Angezeigter Monat im Format Y-m; leer = aktueller Monat. */
    #[Url]
    public string $month = '';

    /** Index des im Modal geöffneten Termins (Position in $this->events). */
    public ?int $selected = null;

    public bool $showEvent = false;

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
        }
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
     * Termine des angezeigten Monats, chronologisch. Im aktuellen Monat
     * ab heute (die API filtert ab dem übergebenen Datum bis Monatsende).
     *
     * @return Collection<int, MeetupEventData>
     */
    #[Computed]
    public function events(): Collection
    {
        $from = $this->isCurrentMonth() ? CarbonImmutable::today() : $this->monthStart();

        return app(PortalApi::class)
            ->meetupEvents($from->toDateString())
            ->sortBy(fn (MeetupEventData $event): int => $event->start->getTimestamp())
            ->values();
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
        return $this->events->groupBy(fn (MeetupEventData $event): string => $event->start->toDateString(), preserveKeys: true);
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
                'date' => $event->start->translatedFormat('d.m.Y · H:i'),
            ]),
            url: $event->link ?? $event->meetup->portalLink,
        );
    }

    protected function switchMonth(CarbonImmutable $start): void
    {
        $this->month = $start->isSameMonth(CarbonImmutable::today()) ? '' : $start->format('Y-m');
        $this->selected = null;
        $this->showEvent = false;
        unset($this->monthStart, $this->isCurrentMonth, $this->events, $this->days, $this->selectedEvent);
    }
};
?>

<div class="flex flex-col gap-4">
    <div class="flex items-center justify-between gap-2">
        <flux:button wire:click="previousMonth" size="sm" variant="ghost" icon="chevron-left" class="cursor-pointer" :aria-label="__('Voriger Monat')"/>
        <flux:heading size="lg" level="1">
            {{ $this->monthStart->translatedFormat('F Y') }}
        </flux:heading>
        <flux:button wire:click="nextMonth" size="sm" variant="ghost" icon="chevron-right" class="cursor-pointer" :aria-label="__('Nächster Monat')"/>
    </div>

    @if ($this->events->isEmpty())
        <x-empty-state icon="calendar-days" :heading="__('Keine Termine')">
            <flux:text class="max-w-xs">
                {{ $this->isCurrentMonth
                    ? __('Für den Rest dieses Monats sind keine Meetup-Termine eingetragen — oder die Daten konnten nicht geladen werden.')
                    : __('Für diesen Monat sind keine Meetup-Termine eingetragen.') }}
            </flux:text>
        </x-empty-state>
    @else
        @foreach ($this->days as $day => $eventsOfDay)
            <section wire:key="day-{{ $day }}" class="flex flex-col gap-2">
                <flux:heading size="sm" level="2" class="text-zinc-500 dark:text-zinc-400">
                    {{ $eventsOfDay->first()->start->translatedFormat('l, d. F') }}
                </flux:heading>
                @foreach ($eventsOfDay as $index => $event)
                    <button
                        type="button"
                        wire:click="select({{ $index }})"
                        wire:key="event-{{ $index }}"
                        class="flex cursor-pointer items-center gap-4 rounded-2xl border border-zinc-200 bg-white p-4 text-start transition-colors active:bg-zinc-100 dark:border-zinc-800 dark:bg-zinc-900 dark:active:bg-zinc-800"
                    >
                        <x-meetup-avatar :logo="$event->meetup->logo" :name="$event->meetup->name"/>
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span class="truncate font-semibold">{{ $event->meetup->name }}</span>
                            <flux:text class="truncate text-sm">
                                {{ $event->start->format('H:i') }}{{ $event->location ? ' · '.$event->location : '' }}
                            </flux:text>
                        </span>
                        <flux:icon name="chevron-right" class="ms-auto size-5 shrink-0 text-zinc-400"/>
                    </button>
                @endforeach
            </section>
        @endforeach
    @endif

    <flux:modal wire:model.self="showEvent" class="w-full">
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
                        {{ $this->selectedEvent->start->translatedFormat('l, d. F Y · H:i') }}
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

                <div class="flex flex-wrap gap-2">
                    @if ($this->selectedEvent->link)
                        <flux:button wire:click="openLink({{ Js::from($this->selectedEvent->link) }})" size="sm" icon="link" class="cursor-pointer">
                            {{ __('Link öffnen') }}
                        </flux:button>
                    @endif
                    <flux:button wire:click="share" size="sm" icon="share" class="cursor-pointer">
                        {{ __('Teilen') }}
                    </flux:button>
                    <flux:button
                        :href="route('meetups.show', $this->selectedEvent->meetup->slug())"
                        wire:navigate
                        size="sm"
                        variant="ghost"
                        icon="map-pin"
                    >
                        {{ __('Zum Meetup') }}
                    </flux:button>
                </div>
            </div>
        @endif
    </flux:modal>
</div>
