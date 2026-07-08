<?php

use App\Data\Portal\MapMeetupData;
use App\Data\Portal\MeetupData;
use App\Data\Portal\MeetupEventData;
use App\Data\Portal\MyMeetupEventData;
use App\Livewire\Concerns\InteractsWithCalendar;
use App\Livewire\Concerns\InteractsWithEventRsvp;
use App\Livewire\PortalPage;
use App\Services\PortalApi;
use App\Services\PortalAuth;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Native\Mobile\Facades\Share;

new #[Layout('layouts::mobile', ['title' => 'Meetup', 'back' => '/meetups'])] class extends PortalPage {
    use InteractsWithCalendar;
    use InteractsWithEventRsvp;

    public string $slug;

    public function mount(string $slug): void
    {
        $this->slug = $slug;
        $this->loadRsvp();
    }

    /**
     * Das RSVP der Detailseite bezieht sich auf den nächsten Termin.
     */
    protected function rsvpEventId(): ?int
    {
        return $this->meetup?->next_event?->id;
    }

    #[Computed]
    public function meetup(): ?MapMeetupData
    {
        return app(PortalApi::class)
            ->mapMeetups(withIntro: true, withLogos: true)
            ->first(fn (MapMeetupData $meetup): bool => $meetup->slug() === $this->slug);
    }

    /**
     * Setzt den sticky Header-Titel auf den Meetup-Namen statt des generischen
     * „Meetup". Das #[Layout]-Attribut ist statisch, deshalb hier dynamisch
     * über die Layout-Daten — der Name steht erst nach dem API-Call fest.
     */
    public function rendering(\Illuminate\View\View $view): void
    {
        $view->layoutData(['heading' => $this->meetup?->name ?? __('Meetup')]);
    }

    /**
     * Weitere Termine des Meetups in diesem Monat (ab heute), ohne den
     * bereits separat angezeigten next_event.
     *
     * @return Collection<int, MeetupEventData>
     */
    #[Computed]
    public function upcomingEvents(): Collection
    {
        $meetup = $this->meetup;

        if ($meetup === null) {
            return new Collection;
        }

        return app(PortalApi::class)
            ->meetupEvents(CarbonImmutable::today()->toDateString())
            ->filter(fn (MeetupEventData $event): bool => $event->meetup->slug() === $this->slug)
            ->reject(fn (MeetupEventData $event): bool => $meetup->next_event !== null
                && $event->start->equalTo($meetup->next_event->start))
            ->sortBy(fn (MeetupEventData $event): int => $event->start->getTimestamp())
            ->values();
    }

    /**
     * Das passende eigene Meetup (per Slug), wenn der verbundene Nutzer dieses
     * Meetup erstellt hat — schaltet die Termin-Verwaltung frei (Phase 5.3).
     * `myMeetups()` enthält nur die eigenen Meetups; der Slug-Abgleich ist daher
     * eindeutig (das Portal vergibt Slugs pro Nutzer eindeutig).
     */
    #[Computed]
    public function ownMeetup(): ?MeetupData
    {
        if (! app(PortalAuth::class)->hasToken()) {
            return null;
        }

        return app(PortalApi::class)
            ->myMeetups()
            ->first(fn (MeetupData $meetup): bool => $meetup->slug === $this->slug);
    }

    /**
     * Eigene Termine dieses Meetups (zum Verwalten), nach Startzeit sortiert.
     * Kommende vs. vergangene wird im Template aus dieser einen Liste abgeleitet.
     *
     * @return Collection<int, MyMeetupEventData>
     */
    #[Computed]
    public function myEvents(): Collection
    {
        $own = $this->ownMeetup;

        if ($own === null) {
            return new Collection;
        }

        return app(PortalApi::class)
            ->myMeetupEvents()
            ->filter(fn (MyMeetupEventData $event): bool => $event->meetup_id === $own->id)
            ->sortBy(fn (MyMeetupEventData $event): int => $event->start->getTimestamp())
            ->values();
    }

    /**
     * Nach dem Anlegen/Bearbeiten im Termin-Editor (Phase 5) die eigene
     * Termin-Liste neu laden. Der PortalWriter hat den Cache bereits
     * invalidiert; das Verwerfen des Computeds erzwingt den frischen Refetch.
     */
    #[On('meetup-event-saved')]
    public function refreshMyEvents(): void
    {
        unset($this->myEvents);
    }

    /**
     * Externe Links des Meetups als [Label => URL] für die Link-Liste.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function links(): array
    {
        return $this->meetup?->socialLinks() ?? [];
    }

    /**
     * Meetup-Link über das native Share-Sheet teilen.
     */
    public function share(): void
    {
        $meetup = $this->meetup;

        if ($meetup === null) {
            return;
        }

        Share::url(
            title: $meetup->name,
            text: __(':name — Bitcoin-Meetup in :city', ['name' => $meetup->name, 'city' => $meetup->city]),
            url: $meetup->portalLink,
        );
    }

    /**
     * Den nächsten Termin (statt der Meetup-Seite) übers Share-Sheet teilen.
     */
    public function shareEvent(): void
    {
        $meetup = $this->meetup;
        $event = $meetup?->next_event;

        if ($event === null) {
            return;
        }

        Share::url(
            title: $meetup->name,
            text: __(':name am :date', [
                'name' => $meetup->name,
                'date' => $event->start->forDisplay()->translatedFormat('d.m.Y · H:i'),
            ]),
            url: $event->link ?? $event->portalLink,
        );
    }

    /**
     * Den nächsten Termin „zum Kalender hinzufügen" (nativer Editor bzw.
     * .ics-Fallback über InteractsWithCalendar).
     */
    public function addToCalendar(): void
    {
        $meetup = $this->meetup;
        $event = $meetup?->next_event;

        if ($event === null) {
            return;
        }

        $this->exportToCalendar(
            title: $meetup->name,
            start: $event->start,
            end: $event->start->copy()->addMinutes(120), // API liefert kein Ende → 2h-Default.
            location: $event->location,
            description: $event->description,
            filename: 'event-'.$event->id,
        );
    }
};
?>

<x-portal-page>
    @if ($this->meetup === null)
        <x-portal-empty-state icon="map-pin" :heading="__('Meetup nicht gefunden')" min-height="min-h-[60dvh]">
            <flux:text class="max-w-xs">
                {{ __('Dieses Meetup ist nicht (mehr) auf der Karte gelistet.') }}
            </flux:text>
            <flux:button :href="route('meetups')" wire:navigate icon="arrow-left" size="sm">
                {{ __('Zu den Meetups') }}
            </flux:button>
        </x-portal-empty-state>
    @else
        <section class="surface-card p-6">
            <div class="flex items-start gap-4">
                <x-meetup-avatar :logo="$this->meetup->logo" :name="$this->meetup->name" size="xl"/>
                <div class="min-w-0 flex-1">
                    <flux:heading size="xl" level="1">{{ $this->meetup->name }}</flux:heading>
                    <flux:text class="mt-1">{{ $this->meetup->city }} · {{ $this->meetup->country }}</flux:text>
                </div>
            </div>
            <div class="mt-4 flex gap-2">
                <flux:button wire:click="share" size="sm" icon="share" class="cursor-pointer">
                    {{ __('Teilen') }}
                </flux:button>
                <flux:button wire:click="openLink({{ Js::from($this->meetup->portalLink) }})" size="sm" variant="ghost" icon="arrow-top-right-on-square" class="cursor-pointer">
                    {{ __('Im Portal öffnen') }}
                </flux:button>
                @if ($this->ownMeetup?->is_leader)
                    {{-- Bearbeiten nur für Leader (Leader-Modell) — analog zu courses.show/lecturers.show. --}}
                    <flux:button
                        size="sm"
                        variant="ghost"
                        icon="pencil-square"
                        x-on:click="$haptic('light'); $flux.modal('create-meetup').show(); Livewire.dispatch('open-meetup-editor', { id: {{ $this->ownMeetup->id }} })"
                        class="cursor-pointer"
                    >
                        {{ __('Bearbeiten') }}
                    </flux:button>
                @endif
            </div>
        </section>

        {{-- Termin-Verwaltung (Phase 5.3): nur für Leader des Meetups (Leader-
             Modell — die Portal-API verlangt is_leader). Anlegen öffnet den Editor
             mit vorgewähltem Meetup, jede eigene Zeile bietet eine Inline-Edit-
             Affordance; kommende vs. vergangene Termine getrennt. --}}
        @if ($this->ownMeetup?->is_leader)
            <section class="surface-card p-6">
                <div class="flex items-center justify-between gap-3">
                    <flux:heading size="lg" level="2">{{ __('Meine Termine') }}</flux:heading>
                    <flux:button
                        type="button"
                        size="sm"
                        variant="primary"
                        icon="plus"
                        x-on:click="$haptic('medium'); $flux.modal('create-event').show(); Livewire.dispatch('open-event-editor', {{ Js::from(['meetupId' => $this->ownMeetup->id]) }})"
                        class="cursor-pointer"
                    >
                        {{ __('Termin anlegen') }}
                    </flux:button>
                </div>

                @php
                    // Kommende vs. vergangene Termine aus der einen myEvents-Liste
                    // ableiten (aufsteigend sortiert); vergangene jüngste zuerst.
                    $upcoming = $this->myEvents->reject(fn ($event) => $event->start->isPast());
                    $past = $this->myEvents->filter(fn ($event) => $event->start->isPast())->reverse();
                @endphp

                @if ($this->myEvents->isEmpty())
                    <flux:text class="mt-3 text-sm">
                        {{ __('Noch keine eigenen Termine — lege den ersten an.') }}
                    </flux:text>
                @else
                    @if ($upcoming->isNotEmpty())
                        <div class="mt-3 flex flex-col gap-2">
                            @foreach ($upcoming as $event)
                                <x-my-event-row :event="$event" wire:key="my-upcoming-{{ $event->id }}"/>
                            @endforeach
                        </div>
                    @endif

                    @if ($past->isNotEmpty())
                        <flux:heading size="sm" level="3" class="mt-5 text-zinc-500 dark:text-zinc-400">{{ __('Vergangene Termine') }}</flux:heading>
                        <div class="mt-2 flex flex-col gap-2">
                            @foreach ($past as $event)
                                <x-my-event-row :event="$event" past wire:key="my-past-{{ $event->id }}"/>
                            @endforeach
                        </div>
                    @endif
                @endif
            </section>
        @endif

        @if ($this->meetup->next_event)
            <section class="surface-card p-6">
                <flux:heading size="lg" level="2">{{ __('Nächster Termin') }}</flux:heading>
                <div class="mt-3 flex items-center gap-3">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-500/15 text-brand-600 dark:text-brand-400">
                        <flux:icon name="calendar-days" class="size-6"/>
                    </span>
                    <div class="min-w-0">
                        <span class="font-semibold">
                            {{ $this->meetup->next_event->start->forDisplay()->translatedFormat('l, d. F Y · H:i') }}
                        </span>
                        @if ($this->meetup->next_event->location)
                            <flux:text class="truncate text-sm">{{ $this->meetup->next_event->location }}</flux:text>
                        @endif
                    </div>
                </div>
                @if ($this->meetup->next_event->descriptionHtml())
                    <div class="markdown mt-3 text-sm">
                        {!! $this->meetup->next_event->descriptionHtml() !!}
                    </div>
                @endif
                {{-- RSVP (B.5): Zähler + „Ich komme/Vielleicht/Kann nicht“. Live-
                     Zähler (nach eigener Zu-/Absage) bevorzugen, sonst die mit der
                     Karte gelieferten Zähler des nächsten Termins. --}}
                <x-rsvp-controls
                    class="mt-4"
                    :status="$rsvpStatus"
                    :attendees="$rsvpAttendees ?? $this->meetup->next_event->attendees"
                    :might-attendees="$rsvpMightAttendees ?? $this->meetup->next_event->might_attendees"
                    :can-rsvp="$this->canRsvp()"
                    :rsvp-enabled="$this->meetup->rsvp_enabled"
                />
                <x-event-action-grid
                    class="mt-4"
                    :link="$this->meetup->next_event->link"
                    share="shareEvent"
                />
            </section>
        @endif

        @if ($this->upcomingEvents->isNotEmpty())
            <section class="surface-card p-6">
                <flux:heading size="lg" level="2">{{ __('Weitere Termine') }}</flux:heading>
                <div class="mt-3 flex flex-col gap-3">
                    @foreach ($this->upcomingEvents as $event)
                        <div wire:key="event-{{ $event->start->getTimestamp() }}" class="flex items-center gap-3">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-500/15 text-brand-600 dark:text-brand-400">
                                <flux:icon name="calendar-days" class="size-5"/>
                            </span>
                            <div class="min-w-0">
                                <span class="font-semibold">{{ $event->start->forDisplay()->translatedFormat('D, d. M · H:i') }}</span>
                                @if ($event->location)
                                    <flux:text class="truncate text-sm">{{ $event->location }}</flux:text>
                                @endif
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        @if ($this->meetup->introHtml())
            <section class="surface-card p-6">
                <flux:heading size="lg" level="2">{{ __('Über das Meetup') }}</flux:heading>
                <div class="markdown mt-3 text-sm">
                    {!! $this->meetup->introHtml() !!}
                </div>
            </section>
        @endif

        @if ($this->links !== [])
            <section class="surface-card p-6">
                <flux:heading size="lg" level="2">{{ __('Links') }}</flux:heading>
                <div class="mt-3 flex flex-col gap-2">
                    @foreach ($this->links as $label => $url)
                        <button
                            type="button"
                            wire:click="openLink({{ Js::from($url) }})"
                            x-on:click="$haptic('light')"
                            wire:key="link-{{ $label }}"
                            class="pressable flex cursor-pointer items-center gap-3 rounded-tile border border-zinc-200 px-4 py-3 text-start active:bg-zinc-50 dark:border-zinc-800 dark:active:bg-zinc-800"
                        >
                            <flux:icon name="link" class="size-5 shrink-0 text-zinc-400"/>
                            <span class="flex min-w-0 flex-col">
                                <span class="font-semibold">{{ $label }}</span>
                                <flux:text class="truncate text-sm">{{ $url }}</flux:text>
                            </span>
                        </button>
                    @endforeach
                </div>
            </section>
        @endif
    @endif

</x-portal-page>
