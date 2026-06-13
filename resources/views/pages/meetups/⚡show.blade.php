<?php

use App\Data\Portal\MapMeetupData;
use App\Data\Portal\MeetupEventData;
use App\Livewire\PortalPage;
use App\Services\PortalApi;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Native\Mobile\Facades\Share;

new #[Layout('layouts::mobile', ['title' => 'Meetup', 'heading' => 'Meetup'])] class extends PortalPage {
    public string $slug;

    public function mount(string $slug): void
    {
        $this->slug = $slug;
    }

    #[Computed]
    public function meetup(): ?MapMeetupData
    {
        return app(PortalApi::class)
            ->mapMeetups(withIntro: true, withLogos: true)
            ->first(fn (MapMeetupData $meetup): bool => $meetup->slug() === $this->slug);
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
            </div>
        </section>

        @if ($this->meetup->next_event)
            <section class="surface-card p-6">
                <flux:heading size="lg" level="2">{{ __('Nächster Termin') }}</flux:heading>
                <div class="mt-3 flex items-center gap-3">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-xl bg-brand-500/15 text-brand-600 dark:text-brand-400">
                        <flux:icon name="calendar-days" class="size-6"/>
                    </span>
                    <div class="min-w-0">
                        <span class="font-semibold">
                            {{ $this->meetup->next_event->start->translatedFormat('l, d. F Y · H:i') }}
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
                @if ($this->meetup->next_event->attendees > 0 || $this->meetup->next_event->might_attendees > 0)
                    <flux:text class="mt-3 text-sm tabular-nums">
                        {{ __(':yes Zusagen · :maybe Vielleicht', [
                            'yes' => $this->meetup->next_event->attendees,
                            'maybe' => $this->meetup->next_event->might_attendees,
                        ]) }}
                    </flux:text>
                @endif
                @if ($this->meetup->next_event->link)
                    <flux:button wire:click="openLink({{ Js::from($this->meetup->next_event->link) }})" size="sm" icon="link" class="mt-4 cursor-pointer">
                        {{ __('Termin-Link öffnen') }}
                    </flux:button>
                @endif
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
                                <span class="font-semibold">{{ $event->start->translatedFormat('D, d. M · H:i') }}</span>
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
