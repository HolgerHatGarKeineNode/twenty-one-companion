<?php

use App\Data\Portal\MeetupData;
use App\Data\Portal\MyMeetupEventData;
use App\Livewire\PortalPage;
use App\Services\PortalApi;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;

/**
 * „Meine Termine" (`/ich/inhalte/termine`, P5) — managing the dates of the meetups one
 * leads.
 *
 * ── Why the page moved ───────────────────────────────────────────────────────
 *
 * This management hung on this app's meetup DETAIL page, below the header card, and only
 * for leaders. The detail page has lived in the package since P4 (D9) and is read-only
 * there; the two buttons a leader needs at ONE meetup („Bearbeiten", „Termin anlegen")
 * still stand there in the host slot (`partials/portal/detail-aktionen`). What has no
 * room there is the LIST of one's own dates with their edit rows — and that list stands
 * here now, across all of one's meetups instead of one page per meetup.
 *
 * This is not only a move but the better shape: whoever leads three meetups had to open
 * three detail pages for it.
 *
 * ── Why the meetup names come from `myMeetups()` ────────────────────────────
 *
 * `GET /api/my-meetup-events` delivers the flat write view with `meetup_id` and without
 * meetup data (see {@see MyMeetupEventData}). The name is therefore resolved without a
 * network call from the already cached list of one's own meetups — the same method the
 * meetup editor uses for cities.
 */
new #[Layout('layouts::mobile', ['title' => 'Meine Termine', 'heading' => 'Meine Termine', 'back' => '/ich/inhalte'])] class extends PortalPage
{
    /**
     * One's own dates, ascending. The template splits upcoming from past out of this ONE
     * list — two queries would be two truths about the same stock.
     *
     * @return Collection<int, MyMeetupEventData>
     */
    #[Computed]
    public function myEvents(): Collection
    {
        return app(PortalApi::class)
            ->myMeetupEvents()
            ->sortBy(fn (MyMeetupEventData $event): int => $event->start->getTimestamp())
            ->values();
    }

    /**
     * The own meetups one LEADS — only for those does the API accept dates (leader model),
     * so only those belong in the „create" button.
     *
     * @return Collection<int, MeetupData>
     */
    #[Computed]
    public function myLeaderMeetups(): Collection
    {
        return app(PortalApi::class)
            ->myMeetups()
            ->filter(fn (MeetupData $meetup): bool => $meetup->is_leader)
            ->sortBy(fn (MeetupData $meetup): string => mb_strtolower($meetup->name))
            ->values();
    }

    /**
     * meetup_id → Name, netzwerkfrei aus der gecachten eigenen Liste.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function meetupNames(): array
    {
        return app(PortalApi::class)
            ->myMeetups()
            ->mapWithKeys(fn (MeetupData $meetup): array => [$meetup->id => $meetup->name])
            ->all();
    }

    #[On('meetup-event-saved')]
    public function refreshMyEvents(): void
    {
        unset($this->myEvents);
    }
};
?>

<x-portal-page>
    <x-requires-portal :heading="__('Mit Portal verbinden')" :text="__('Verbinde dein Konto, um die Termine deiner Meetups zu verwalten.')">
        @if ($this->myLeaderMeetups->isEmpty())
            {{-- No meetup led: then there is nothing to create, and the sentence says why
                 — instead of a button the API answers with a 403. --}}
            <x-portal-empty-state icon="calendar-days" :heading="__('Keine Termin-Verwaltung')" :error-heading="__('Termine nicht verfügbar')">
                <flux:text class="max-w-xs">
                    {{ __('Termine anlegen kann nur, wer ein Meetup führt. Trag dich im Portal als Leader ein, dann erscheint die Verwaltung hier.') }}
                </flux:text>
            </x-portal-empty-state>
        @else
            <div class="flex justify-end">
                {{-- Without a preselected meetup: the editor lets you choose one, and with
                     exactly one meetup led it is preselected there already. --}}
                <flux:button
                    type="button"
                    size="sm"
                    variant="primary"
                    icon="plus"
                    x-on:click="$haptic('medium'); $flux.modal('create-event').show(); Livewire.dispatch('open-event-editor', {{ Js::from($this->myLeaderMeetups->count() === 1 ? ['meetupId' => $this->myLeaderMeetups->first()->id] : []) }})"
                    class="cursor-pointer"
                >
                    {{ __('Termin anlegen') }}
                </flux:button>
            </div>

            @php
                // Kommende vs. vergangene aus der einen Liste ableiten (aufsteigend
                // sortiert); vergangene jüngste zuerst.
                $upcoming = $this->myEvents->reject(fn (MyMeetupEventData $event) => $event->start->isPast());
                $past = $this->myEvents->filter(fn (MyMeetupEventData $event) => $event->start->isPast())->reverse();
            @endphp

            @if ($this->myEvents->isEmpty())
                <flux:text class="text-sm">
                    {{ __('Noch keine eigenen Termine — lege den ersten an.') }}
                </flux:text>
            @else
                @if ($upcoming->isNotEmpty())
                    <div class="flex flex-col gap-2" data-meine-termine="kommend">
                        @foreach ($upcoming as $event)
                            <x-my-event-row :event="$event" wire:key="my-upcoming-{{ $event->id }}">
                                <x-slot:meetup>{{ $this->meetupNames[$event->meetup_id] ?? '' }}</x-slot:meetup>
                            </x-my-event-row>
                        @endforeach
                    </div>
                @endif

                @if ($past->isNotEmpty())
                    <flux:heading size="sm" level="2" class="mt-5 text-zinc-500 dark:text-zinc-400">{{ __('Vergangene Termine') }}</flux:heading>
                    <div class="mt-2 flex flex-col gap-2" data-meine-termine="vergangen">
                        @foreach ($past as $event)
                            <x-my-event-row :event="$event" past wire:key="my-past-{{ $event->id }}">
                                <x-slot:meetup>{{ $this->meetupNames[$event->meetup_id] ?? '' }}</x-slot:meetup>
                            </x-my-event-row>
                        @endforeach
                    </div>
                @endif
            @endif
        @endif
    </x-requires-portal>
</x-portal-page>
