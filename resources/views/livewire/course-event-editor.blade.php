<?php

use App\Data\Portal\CourseData;
use App\Data\Portal\CourseEventData;
use App\Data\Portal\VenueData;
use App\Livewire\Concerns\HandlesPortalWriteFeedback;
use App\Livewire\Forms\CourseEventForm;
use App\Services\PortalApi;
use App\Services\PortalWriter;
use App\Support\Clock;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Kurs-Event-Editor (Phase 7.3): das Create-/Edit-Formular für Kurs-Termine.
 * Wie der Termin-Editor einmal im Layout eingebettet, besitzt es das Bottom-
 * Sheet `create-course-event`. Geöffnet über das `open-course-event-editor`-
 * Event: ohne Argumente = Anlegen (Kurs frei wählbar), mit `courseId` = Anlegen
 * für einen bestimmten Kurs, mit `eventId` = ein eigenes Kurs-Event bearbeiten.
 *
 * Anders als der Meetup-Termin trägt das Kurs-Event einen echten Veranstaltungs-
 * ort (`venue_id`, per Namen gesucht, mit inline „Ort anlegen") und Start-/
 * Endzeit. Anlegen erfordert serverseitig den Referenten-Status (is_lecturer)
 * bzw. Eigentum (403). Das `ready`-Gate verhindert API-Calls beim globalen
 * Layout-Render — die eigene Kurs-Liste lädt erst beim ersten Öffnen.
 */
new class extends Component {
    use HandlesPortalWriteFeedback;

    public CourseEventForm $form;

    /** Null = Anlegen, sonst die ID des bearbeiteten eigenen Kurs-Events. */
    public ?int $editingId = null;

    /** Erst nach dem ersten Öffnen true (kein Datenabruf beim Layout-Render). */
    public bool $ready = false;

    /** Kurs-Auswahl sperren (Bearbeiten oder Anlegen aus einem Kurs-Detail). */
    public bool $courseLocked = false;

    /** Suchbegriff für die Ort-Auswahl (eigenes Feld, nicht Teil der Payload). */
    public string $venueQuery = '';

    #[On('open-course-event-editor')]
    public function open(?int $eventId = null, ?int $courseId = null): void
    {
        $this->ready = true;
        $this->resetEditor();

        if ($eventId !== null) {
            $this->loadForEdit($eventId);

            return;
        }

        if ($courseId !== null) {
            $this->form->course_id = $courseId;
            $this->form->courseName = $this->myCourses
                ->first(fn (CourseData $course): bool => $course->id === $courseId)?->name ?? '';
            $this->courseLocked = true;
        }
    }

    private function resetEditor(): void
    {
        $this->form->reset();
        $this->editingId = null;
        $this->courseLocked = false;
        $this->venueQuery = '';
        $this->resetErrorBag();
    }

    private function loadForEdit(int $id): void
    {
        $event = app(PortalApi::class)
            ->myCourseEvents()
            ->first(fn (CourseEventData $candidate): bool => $candidate->id === $id);

        if ($event === null) {
            Flux::toast(text: __('Dieses Kurs-Event konnte nicht geladen werden.'), variant: 'danger');

            return;
        }

        $courseName = $event->course?->name
            ?? $this->myCourses->first(fn (CourseData $course): bool => $course->id === $event->course_id)?->name
            ?? '';

        $this->editingId = $event->id;
        $this->courseLocked = true;
        $this->form->setEvent($event, $courseName, $event->venue?->name ?? '');
    }

    /**
     * Die eigenen Kurse für die Auswahl.
     *
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
     * Ort-Treffer für die Auswahl (ab 2 Zeichen, debounced).
     *
     * @return Collection<int, VenueData>
     */
    #[Computed]
    public function venueResults(): Collection
    {
        $query = trim($this->venueQuery);

        if (mb_strlen($query) < 2) {
            return collect();
        }

        return app(PortalApi::class)
            ->venues($query, withDetails: true)
            ->take(8)
            ->values();
    }

    public function selectVenue(int $id, string $name): void
    {
        $this->form->venue_id = $id;
        $this->form->venueName = $name;
        $this->venueQuery = '';
        $this->resetErrorBag('form.venue_id');
        unset($this->venueResults);
    }

    public function clearVenue(): void
    {
        $this->form->venue_id = null;
        $this->form->venueName = '';
    }

    /**
     * Einen im Venue-Editor frisch angelegten Ort direkt übernehmen (inline aus
     * dem Kurs-Event-Flow). Greift nur, wenn noch kein Ort gewählt ist.
     */
    #[On('venue-saved')]
    public function useSavedVenue(int $id, string $name): void
    {
        if ($this->form->venue_id !== null) {
            return;
        }

        $this->selectVenue($id, $name);
    }

    public function save(): void
    {
        $payload = $this->form->payload();

        if ($this->editingId === null && $this->startsInPast()) {
            $this->addError('form.date', __('Der Termin darf nicht in der Vergangenheit liegen.'));

            return;
        }

        $writer = app(PortalWriter::class);

        $result = $this->editingId === null
            ? $writer->createCourseEvent($payload)
            : $writer->updateCourseEvent($this->editingId, $payload);

        if ($result->successful()) {
            $this->handleSuccess();

            return;
        }

        // Portal-Felder `from`/`to` zeigen wir an Datum bzw. Endzeit an.
        $this->reportWriteFailure($result, __('Du darfst dieses Kurs-Event nicht bearbeiten.'), ['from' => 'date', 'to' => 'to_time']);
    }

    private function startsInPast(): bool
    {
        return Clock::localIsPast($this->form->date.' '.$this->form->from_time);
    }

    private function handleSuccess(): void
    {
        $created = $this->editingId === null;

        Flux::modal('create-course-event')->close();
        Flux::toast(
            text: $created ? __('Kurs-Event angelegt.') : __('Kurs-Event aktualisiert.'),
            variant: 'success',
        );

        $this->dispatch('teaching-changed');
        $this->js("window.haptic && window.haptic('success')");
        $this->resetEditor();
    }
};
?>

<x-sheet name="create-course-event" :heading="$ready ? ($editingId ? __('Kurs-Event bearbeiten') : __('Kurs-Event anlegen')) : ''">
    @if (! $ready)
        {{-- Vor dem ersten Öffnen kein Datenabruf (global im Layout eingebettet). --}}
    @elseif ($this->myCourses->isEmpty())
        {{-- Kurs-Events hängen an einem eigenen Kurs — ohne Kurs kein Termin. --}}
        <div class="flex flex-col items-center gap-3 py-6 text-center">
            <span class="flex size-14 items-center justify-center rounded-tile bg-brand-500/10 text-brand-600 dark:text-brand-400">
                <flux:icon name="academic-cap" class="size-7"/>
            </span>
            <flux:text class="max-w-xs">
                {{ __('Lege zuerst einen eigenen Kurs an — Kurs-Events gehören immer zu einem Kurs.') }}
            </flux:text>
            <flux:button
                type="button"
                variant="primary"
                icon="plus"
                x-on:click="$haptic('medium'); $flux.modal('create-course-event').close(); $flux.modal('create-course').show(); Livewire.dispatch('open-course-editor')"
                class="cursor-pointer"
            >
                {{ __('Kurs anlegen') }}
            </flux:button>
        </div>
    @else
        <form wire:submit="save" class="flex flex-col gap-5">
            {{-- Kurs-Auswahl: gesperrt als Chip (Bearbeiten / aus Kurs-Detail), sonst Select. --}}
            <div class="flex flex-col gap-2">
                <flux:label>{{ __('Kurs') }}</flux:label>

                @if ($courseLocked)
                    <div class="flex items-center gap-2 rounded-tile border border-zinc-200 px-4 py-3 dark:border-zinc-800">
                        <flux:icon name="academic-cap" class="size-5 shrink-0 text-brand-600 dark:text-brand-400"/>
                        <span class="truncate font-semibold">{{ $form->courseName !== '' ? $form->courseName : __('Kurs gewählt') }}</span>
                    </div>
                @else
                    <flux:select wire:model="form.course_id" :placeholder="__('Kurs wählen …')">
                        @foreach ($this->myCourses as $course)
                            <flux:select.option :value="$course->id" wire:key="ce-course-{{ $course->id }}">{{ $course->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('form.course_id')
                        <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                    @enderror
                @endif
            </div>

            {{-- Ort: gewählter Ort als Chip, sonst Suche. --}}
            <div class="flex flex-col gap-2">
                <flux:label>{{ __('Ort') }}</flux:label>

                @if ($form->venue_id)
                    <div class="flex items-center justify-between gap-3 rounded-tile border border-zinc-200 px-4 py-3 dark:border-zinc-800">
                        <span class="flex min-w-0 items-center gap-2">
                            <flux:icon name="map-pin" class="size-5 shrink-0 text-brand-600 dark:text-brand-400"/>
                            <span class="truncate font-semibold">{{ $form->venueName !== '' ? $form->venueName : __('Ort gewählt') }}</span>
                        </span>
                        <flux:button wire:click="clearVenue" type="button" size="xs" variant="ghost" icon="x-mark" :aria-label="__('Ort ändern')" class="cursor-pointer"/>
                    </div>
                @else
                    <flux:input
                        wire:model.live.debounce.300ms="venueQuery"
                        type="search"
                        icon="magnifying-glass"
                        :placeholder="__('Ort suchen …')"
                    />

                    @error('form.venue_id')
                        <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                    @enderror

                    @if ($this->venueResults->isNotEmpty())
                        <div class="flex flex-col gap-1 rounded-tile border border-zinc-200 p-1 dark:border-zinc-800">
                            @foreach ($this->venueResults as $venue)
                                <button
                                    type="button"
                                    wire:click="selectVenue({{ $venue->id }}, @js($venue->name))"
                                    x-on:click="$haptic('medium')"
                                    wire:key="ce-venue-{{ $venue->id }}"
                                    class="pressable flex items-center gap-2 rounded-md px-3 py-2 text-start active:bg-zinc-100 dark:active:bg-zinc-800"
                                >
                                    <flux:icon name="map-pin" class="size-4 shrink-0 text-zinc-400"/>
                                    <span class="truncate text-sm font-medium">{{ $venue->name }}</span>
                                    @if ($venue->locationLabel())
                                        <flux:text class="ms-auto shrink-0 text-xs">{{ $venue->locationLabel() }}</flux:text>
                                    @endif
                                </button>
                            @endforeach
                        </div>
                    @elseif (mb_strlen(trim($venueQuery)) >= 2)
                        {{-- Inline „Ort anlegen": der Venue-Editor öffnet mit dem
                             Suchbegriff als Namensvorschlag; nach dem Speichern
                             übernimmt useSavedVenue() den neuen Ort. --}}
                        <div class="flex flex-col gap-2 rounded-tile border border-zinc-200 p-3 dark:border-zinc-800">
                            <flux:text class="text-sm">{{ __('Keinen Ort gefunden.') }}</flux:text>
                            <flux:button
                                type="button"
                                size="sm"
                                variant="ghost"
                                icon="plus"
                                x-on:click="$haptic('medium'); $flux.modal('create-venue').show(); Livewire.dispatch('open-venue-editor', { name: @js(trim($venueQuery)) })"
                                class="w-fit cursor-pointer"
                            >
                                {{ __('Ort anlegen') }}
                            </flux:button>
                        </div>
                    @endif
                @endif
            </div>

            {{-- Datum + Start-/Endzeit (native Picker auf dem Gerät). --}}
            <div class="flex flex-col gap-2">
                <flux:input wire:model="form.date" type="date" :label="__('Datum')" required/>
                @error('form.date')
                    <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror
                <div class="flex gap-3">
                    <div class="flex flex-1 flex-col gap-2">
                        <flux:input wire:model="form.from_time" type="time" :label="__('Beginn')" required/>
                        @error('form.from_time')
                            <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                        @enderror
                    </div>
                    <div class="flex flex-1 flex-col gap-2">
                        <flux:input wire:model="form.to_time" type="time" :label="__('Ende')" required/>
                        @error('form.to_time')
                            <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                        @enderror
                    </div>
                </div>
            </div>

            <flux:input wire:model="form.link" :label="__('Anmelde-Link')" type="url" placeholder="https://…" required/>

            <div class="flex gap-2 pt-1">
                <flux:spacer/>
                <flux:modal.close>
                    <flux:button type="button" variant="ghost" class="cursor-pointer">{{ __('Abbrechen') }}</flux:button>
                </flux:modal.close>
                <flux:button
                    type="submit"
                    variant="primary"
                    icon="check"
                    x-on:click="$haptic('medium')"
                    class="cursor-pointer"
                    wire:loading.attr="disabled"
                    wire:target="save"
                >
                    {{ $editingId ? __('Speichern') : __('Anlegen') }}
                </flux:button>
            </div>
        </form>
    @endif
</x-sheet>
