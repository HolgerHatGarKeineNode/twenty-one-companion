<?php

use App\Data\Portal\CourseData;
use App\Data\Portal\CourseEventData;
use App\Data\Portal\MyLecturerData;
use App\Livewire\PortalPage;
use App\Services\PortalApi;
use App\Services\PortalAuth;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;

/**
 * „Meine Kurse & Referenten“ (Phase 7.4): Verwaltungsseite für die eigenen
 * Kurse, Referenten-Profile und Kurs-Events. Auth-gated über <x-requires-portal>;
 * Anlegen/Bearbeiten laufen über die im Layout eingebetteten Kurs-/Referenten-/
 * Kurs-Event-Editoren (Sheets), die nach dem Speichern `teaching-changed` melden
 * → die Listen laden neu.
 *
 * Referenten-Profile darf jeder verbundene Nutzer anlegen; Kurse und Kurs-Events
 * erfordern serverseitig den Referenten-Status (is_lecturer) — die Anlegen-CTAs
 * dieser beiden Tabs sind entsprechend gegated (das Profil kommt netzwerkfrei aus
 * dem lokalen Cache).
 */
new #[Layout('layouts::mobile', ['title' => 'Meine Kurse & Referenten', 'heading' => 'Kurse & Referenten', 'back' => '/mine'])] class extends PortalPage
{
    #[Url]
    public string $tab = 'kurse';

    #[Computed]
    public function isLecturer(): bool
    {
        $auth = app(PortalAuth::class);

        return $auth->hasToken() && (bool) ($auth->cachedProfile()['is_lecturer'] ?? false);
    }

    /**
     * @return Collection<int, CourseData>
     */
    #[Computed]
    public function myCourses(): Collection
    {
        return app(PortalApi::class)
            ->myCourses()
            ->sortBy(fn (CourseData $course): string => mb_strtolower($course->name))
            ->values();
    }

    /**
     * @return Collection<int, MyLecturerData>
     */
    #[Computed]
    public function myLecturers(): Collection
    {
        return app(PortalApi::class)
            ->myLecturers()
            ->sortBy(fn (MyLecturerData $lecturer): string => mb_strtolower($lecturer->name))
            ->values();
    }

    /**
     * Eigene Kurs-Events, das jüngste zuerst.
     *
     * @return Collection<int, CourseEventData>
     */
    #[Computed]
    public function myCourseEvents(): Collection
    {
        return app(PortalApi::class)
            ->myCourseEvents()
            ->sortByDesc(fn (CourseEventData $event): int => $event->from->getTimestamp())
            ->values();
    }

    /**
     * Kursnamen für die Kurs-Events (netzwerkfrei aus den eigenen Kursen, falls
     * die Kurs-Event-Kurzinfo den Namen nicht mitliefert).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function courseNames(): array
    {
        return $this->myCourses
            ->mapWithKeys(fn (CourseData $course): array => [$course->id => $course->name])
            ->all();
    }

    #[On('teaching-changed')]
    public function refreshLists(): void
    {
        unset($this->myCourses, $this->myLecturers, $this->myCourseEvents, $this->courseNames);
    }
};
?>

<x-portal-page>
    <x-requires-portal :heading="__('Mit Portal verbinden')" :text="__('Verbinde dein Konto, um deine eigenen Kurse, Referenten und Kurs-Events zu verwalten.')">
        <flux:tabs wire:model.live="tab" variant="segmented" class="w-full">
            <flux:tab name="kurse">{{ __('Kurse') }}</flux:tab>
            <flux:tab name="referenten">{{ __('Referenten') }}</flux:tab>
            <flux:tab name="termine">{{ __('Termine') }}</flux:tab>
        </flux:tabs>

        @if ($tab === 'kurse')
            @if ($this->myCourses->isEmpty())
                <x-portal-empty-state icon="academic-cap" :heading="__('Noch keine eigenen Kurse')" :error-heading="__('Kurse nicht verfügbar')">
                    <flux:text class="max-w-xs">{{ __('Lege einen Kurs an, um ihn mit Terminen zu füllen.') }}</flux:text>
                    @if ($this->isLecturer)
                        <flux:button
                            type="button"
                            variant="primary"
                            icon="plus"
                            x-on:click="$haptic('medium'); $flux.modal('create-course').show(); Livewire.dispatch('open-course-editor')"
                            class="cursor-pointer"
                        >
                            {{ __('Kurs anlegen') }}
                        </flux:button>
                    @else
                        <flux:text class="max-w-xs text-sm">{{ __('Nur Referenten können Kurse anlegen.') }}</flux:text>
                    @endif
                </x-portal-empty-state>
            @else
                @if ($this->isLecturer)
                    <div class="flex justify-end">
                        <flux:button
                            type="button"
                            size="sm"
                            variant="primary"
                            icon="plus"
                            x-on:click="$haptic('medium'); $flux.modal('create-course').show(); Livewire.dispatch('open-course-editor')"
                            class="cursor-pointer"
                        >
                            {{ __('Kurs anlegen') }}
                        </flux:button>
                    </div>
                @endif

                <div class="list-stagger flex flex-col gap-3">
                    @foreach ($this->myCourses as $course)
                        <div class="surface-card flex items-center gap-3 p-4" wire:key="my-course-{{ $course->id }}" style="--i: {{ $loop->index }}">
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-tile bg-brand-500/10 text-brand-600 dark:text-brand-400">
                                <flux:icon name="academic-cap" class="size-6"/>
                            </span>
                            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="truncate font-semibold">{{ $course->name }}</span>
                                @if ($course->lecturerOrNull())
                                    <flux:text class="truncate text-sm">{{ $course->lecturerOrNull()->name }}</flux:text>
                                @endif
                            </span>
                            <flux:button
                                type="button"
                                variant="ghost"
                                icon="pencil-square"
                                :aria-label="__('Kurs bearbeiten')"
                                x-on:click="$haptic('light'); $flux.modal('create-course').show(); Livewire.dispatch('open-course-editor', { id: {{ $course->id }} })"
                                class="shrink-0 cursor-pointer"
                            />
                        </div>
                    @endforeach
                </div>
            @endif
        @elseif ($tab === 'referenten')
            @if ($this->myLecturers->isEmpty())
                <x-portal-empty-state icon="user" :heading="__('Noch keine eigenen Referenten')" :error-heading="__('Referenten nicht verfügbar')">
                    <flux:text class="max-w-xs">{{ __('Lege ein Referenten-Profil an, dem du Kurse zuordnen kannst.') }}</flux:text>
                    <flux:button
                        type="button"
                        variant="primary"
                        icon="plus"
                        x-on:click="$haptic('medium'); $flux.modal('create-lecturer').show(); Livewire.dispatch('open-lecturer-editor')"
                        class="cursor-pointer"
                    >
                        {{ __('Referent anlegen') }}
                    </flux:button>
                </x-portal-empty-state>
            @else
                <div class="flex justify-end">
                    <flux:button
                        type="button"
                        size="sm"
                        variant="primary"
                        icon="plus"
                        x-on:click="$haptic('medium'); $flux.modal('create-lecturer').show(); Livewire.dispatch('open-lecturer-editor')"
                        class="cursor-pointer"
                    >
                        {{ __('Referent anlegen') }}
                    </flux:button>
                </div>

                <div class="list-stagger flex flex-col gap-3">
                    @foreach ($this->myLecturers as $lecturer)
                        <div class="surface-card flex items-center gap-3 p-4" wire:key="my-lecturer-{{ $lecturer->id }}" style="--i: {{ $loop->index }}">
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-tile bg-brand-500/10 text-brand-600 dark:text-brand-400">
                                <flux:icon name="user" class="size-6"/>
                            </span>
                            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="truncate font-semibold">{{ $lecturer->name }}</span>
                                @if ($lecturer->subtitle)
                                    <flux:text class="truncate text-sm">{{ $lecturer->subtitle }}</flux:text>
                                @endif
                            </span>
                            @unless ($lecturer->active)
                                <flux:badge color="zinc" size="sm" class="shrink-0">{{ __('Inaktiv') }}</flux:badge>
                            @endunless
                            <flux:button
                                type="button"
                                variant="ghost"
                                icon="pencil-square"
                                :aria-label="__('Referent bearbeiten')"
                                x-on:click="$haptic('light'); $flux.modal('create-lecturer').show(); Livewire.dispatch('open-lecturer-editor', { id: {{ $lecturer->id }} })"
                                class="shrink-0 cursor-pointer"
                            />
                        </div>
                    @endforeach
                </div>
            @endif
        @else
            @if ($this->myCourseEvents->isEmpty())
                <x-portal-empty-state icon="calendar-days" :heading="__('Noch keine Kurs-Events')" :error-heading="__('Kurs-Events nicht verfügbar')">
                    <flux:text class="max-w-xs">{{ __('Lege einen Kurs-Termin an, damit Teilnehmer ihn finden.') }}</flux:text>
                    @if ($this->isLecturer && $this->myCourses->isNotEmpty())
                        <flux:button
                            type="button"
                            variant="primary"
                            icon="plus"
                            x-on:click="$haptic('medium'); $flux.modal('create-course-event').show(); Livewire.dispatch('open-course-event-editor')"
                            class="cursor-pointer"
                        >
                            {{ __('Kurs-Event anlegen') }}
                        </flux:button>
                    @elseif ($this->isLecturer)
                        <flux:text class="max-w-xs text-sm">{{ __('Lege zuerst einen Kurs an.') }}</flux:text>
                    @else
                        <flux:text class="max-w-xs text-sm">{{ __('Nur Referenten können Kurs-Events anlegen.') }}</flux:text>
                    @endif
                </x-portal-empty-state>
            @else
                @if ($this->isLecturer)
                    <div class="flex justify-end">
                        <flux:button
                            type="button"
                            size="sm"
                            variant="primary"
                            icon="plus"
                            x-on:click="$haptic('medium'); $flux.modal('create-course-event').show(); Livewire.dispatch('open-course-event-editor')"
                            class="cursor-pointer"
                        >
                            {{ __('Kurs-Event anlegen') }}
                        </flux:button>
                    </div>
                @endif

                <div class="list-stagger flex flex-col gap-3">
                    @foreach ($this->myCourseEvents as $event)
                        <div class="surface-card flex items-center gap-3 p-4" wire:key="my-course-event-{{ $event->id }}" style="--i: {{ $loop->index }}">
                            <span class="flex size-11 shrink-0 items-center justify-center rounded-tile bg-brand-500/10 text-brand-600 dark:text-brand-400">
                                <flux:icon name="calendar-days" class="size-6"/>
                            </span>
                            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="truncate font-semibold">{{ $event->course?->name ?? ($this->courseNames[$event->course_id] ?? __('Kurs')) }}</span>
                                <flux:text class="truncate text-sm">{{ $event->from->forDisplay()->translatedFormat('D, d. M Y · H:i') }}</flux:text>
                                @if ($event->locationLabel())
                                    <flux:text class="truncate text-sm">{{ $event->locationLabel() }}</flux:text>
                                @endif
                            </span>
                            <flux:button
                                type="button"
                                variant="ghost"
                                icon="pencil-square"
                                :aria-label="__('Kurs-Event bearbeiten')"
                                x-on:click="$haptic('light'); $flux.modal('create-course-event').show(); Livewire.dispatch('open-course-event-editor', { eventId: {{ $event->id }} })"
                                class="shrink-0 cursor-pointer"
                            />
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    </x-requires-portal>
</x-portal-page>
