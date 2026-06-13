<?php

use App\Data\Portal\CourseDetailData;
use App\Livewire\PortalPage;
use App\Services\PortalApi;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Native\Mobile\Facades\Share;

new #[Layout('layouts::mobile', ['title' => 'Kurs', 'heading' => 'Kurs'])] class extends PortalPage {
    public int $id;

    public function mount(int $id): void
    {
        $this->id = $id;
    }

    #[Computed]
    public function course(): ?CourseDetailData
    {
        return app(PortalApi::class)->course($this->id);
    }

    /**
     * Kurs-Link über das native Share-Sheet teilen.
     */
    public function share(): void
    {
        $course = $this->course;

        if ($course === null) {
            return;
        }

        Share::url(
            title: $course->name,
            text: __(':name — Bitcoin-Kurs auf dem EINUNDZWANZIG-Portal', ['name' => $course->name]),
            url: $course->portalLink,
        );
    }
};
?>

<x-portal-page>
    @if ($this->course === null)
        <x-portal-empty-state icon="academic-cap" :heading="__('Kurs nicht gefunden')" min-height="min-h-[60dvh]">
            <flux:text class="max-w-xs">
                {{ __('Dieser Kurs existiert nicht mehr.') }}
            </flux:text>
            <flux:button :href="route('courses')" wire:navigate icon="arrow-left" size="sm">
                {{ __('Zu den Kursen') }}
            </flux:button>
        </x-portal-empty-state>
    @else
        <section class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
            <div class="flex items-start gap-4">
                <x-meetup-avatar :logo="$this->course->image ?: null" :name="$this->course->name" size="xl"/>
                <div class="min-w-0 flex-1">
                    <flux:heading size="xl" level="1">{{ $this->course->name }}</flux:heading>
                    @if ($this->course->lecturer)
                        <flux:text class="mt-1">{{ $this->course->lecturer->name }}</flux:text>
                    @endif
                </div>
            </div>
            <div class="mt-4 flex gap-2">
                <flux:button wire:click="share" size="sm" icon="share" class="cursor-pointer">
                    {{ __('Teilen') }}
                </flux:button>
                <flux:button wire:click="openLink({{ Js::from($this->course->portalLink) }})" size="sm" variant="ghost" icon="arrow-top-right-on-square" class="cursor-pointer">
                    {{ __('Im Portal öffnen') }}
                </flux:button>
            </div>
        </section>

        <section class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
            <flux:heading size="lg" level="2">{{ __('Kommende Termine') }}</flux:heading>
            @if ($this->course->events === [])
                <flux:text class="mt-3 text-sm">{{ __('Aktuell sind keine Termine geplant.') }}</flux:text>
            @else
                <div class="mt-3 flex flex-col gap-3">
                    @foreach ($this->course->events as $event)
                        <div wire:key="event-{{ $event->id }}" class="flex items-center gap-3">
                            <span class="flex size-10 shrink-0 items-center justify-center rounded-xl bg-brand-500/15 text-brand-600 dark:text-brand-400">
                                <flux:icon name="calendar-days" class="size-5"/>
                            </span>
                            <div class="min-w-0 flex-1">
                                <span class="font-semibold">{{ $event->from->translatedFormat('D, d. M Y · H:i') }}</span>
                                @if ($event->locationLabel())
                                    <flux:text class="truncate text-sm">{{ $event->locationLabel() }}</flux:text>
                                @endif
                            </div>
                            @if ($event->link)
                                <flux:button wire:click="openLink({{ Js::from($event->link) }})" size="xs" variant="ghost" icon="link" class="shrink-0 cursor-pointer" :aria-label="__('Termin-Link öffnen')"/>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        </section>

        @if ($this->course->descriptionHtml())
            <section class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <flux:heading size="lg" level="2">{{ __('Über den Kurs') }}</flux:heading>
                <div class="markdown mt-3 text-sm">
                    {!! $this->course->descriptionHtml() !!}
                </div>
            </section>
        @endif

        @if ($this->course->lecturer)
            <section class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
                <flux:heading size="lg" level="2">{{ __('Referent') }}</flux:heading>
                <div class="mt-3">
                    <x-list-link-card href="{{ route('lecturers.show', $this->course->lecturer->id) }}">
                        <x-meetup-avatar :logo="$this->course->lecturer->image ?: null" :name="$this->course->lecturer->name"/>
                        <span class="flex min-w-0 flex-col gap-0.5">
                            <span class="truncate font-semibold">{{ $this->course->lecturer->name }}</span>
                            @if ($this->course->lecturer->subtitleOrNull())
                                <flux:text class="truncate text-sm">{{ $this->course->lecturer->subtitleOrNull() }}</flux:text>
                            @endif
                        </span>
                    </x-list-link-card>
                </div>
            </section>
        @endif
    @endif

</x-portal-page>
