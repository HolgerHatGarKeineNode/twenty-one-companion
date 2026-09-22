<?php

use App\Data\Portal\CourseData;
use App\Data\Portal\CourseEventData;
use App\Data\Portal\CityData;
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
 * The place is a city searched by name (`city_id`, with an inline „Stadt anlegen") plus
 * free-text `location` — the portal's contract since it dropped the venue model — and
 * the date has a start and an end time. Anlegen erfordert serverseitig den Referenten-Status (is_lecturer)
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

    /** Search term of the city picker (its own field, not part of the payload). */
    public string $cityQuery = '';

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

            return;
        }

        // Ersten eigenen Kurs vorauswählen — wie im Termin-Editor. Ursprünglich
        // ein Bugfix gegen die native Select-Box (zeigte den ersten Eintrag, ohne
        // wire:model zu binden → „course_id required"); seit variant="listbox"
        // stünde dort der Platzhalter, die Vorauswahl bleibt als Bequemlichkeit.
        $first = $this->myCourses->first();

        if ($first !== null) {
            $this->form->course_id = $first->id;
            $this->form->courseName = $first->name;
        }
    }

    private function resetEditor(): void
    {
        $this->form->reset();
        $this->editingId = null;
        $this->courseLocked = false;
        $this->cityQuery = '';
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
        $this->form->setEvent($event, $courseName);
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
     * City hits for the picker (from 2 characters, debounced) — the same search the meetup
     * editor uses.
     *
     * @return Collection<int, CityData>
     */
    #[Computed]
    public function cityResults(): Collection
    {
        $query = trim($this->cityQuery);

        if (mb_strlen($query) < 2) {
            return collect();
        }

        return app(PortalApi::class)
            ->cities($query, withDetails: true)
            ->take(8)
            ->values();
    }

    public function selectCity(int $id, string $name): void
    {
        $this->form->city_id = $id;
        $this->form->cityName = $name;
        $this->cityQuery = '';
        $this->resetErrorBag('form.city_id');
        unset($this->cityResults);
    }

    public function clearCity(): void
    {
        $this->form->city_id = null;
        $this->form->cityName = '';
    }

    /**
     * Take over a city just created in the city editor (inline from this flow). Only while
     * no city is chosen yet.
     */
    #[On('city-saved')]
    public function useSavedCity(int $id, string $name): void
    {
        if ($this->form->city_id !== null) {
            return;
        }

        $this->selectCity($id, $name);
    }

    public function save(): void
    {
        $payload = $this->form->payload();

        if ($this->form->endsBeforeOrAtStart()) {
            $this->addError('form.to_time', __('Das Ende muss nach dem Beginn liegen.'));

            return;
        }

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
                    {{-- listbox statt nativem Select: der System-Dialog der
                         Android-WebView ignoriert das Dark-Theme. --}}
                    <flux:select variant="listbox" wire:model="form.course_id" :placeholder="__('Kurs wählen …')">
                        @foreach ($this->myCourses as $course)
                            <flux:select.option :value="$course->id" wire:key="ce-course-{{ $course->id }}">{{ $course->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('form.course_id')
                        <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                    @enderror
                @endif
            </div>

            {{-- City: the chosen city as a chip, otherwise the search (same pattern as the
                 meetup editor). --}}
            <div class="flex flex-col gap-2">
                <flux:label>{{ __('Stadt') }}</flux:label>

                @if ($form->city_id)
                    <div class="flex items-center justify-between gap-3 rounded-tile border border-zinc-200 px-4 py-3 dark:border-zinc-800">
                        <span class="flex min-w-0 items-center gap-2">
                            <flux:icon name="map-pin" class="size-5 shrink-0 text-brand-600 dark:text-brand-400"/>
                            <span class="truncate font-semibold">{{ $form->cityName !== '' ? $form->cityName : __('Stadt gewählt') }}</span>
                        </span>
                        <flux:button wire:click="clearCity" type="button" size="xs" variant="ghost" icon="x-mark" :aria-label="__('Stadt ändern')" class="cursor-pointer"/>
                    </div>
                @else
                    <flux:input
                        wire:model.live.debounce.300ms="cityQuery"
                        type="search"
                        icon="magnifying-glass"
                        :placeholder="__('Stadt suchen …')"
                    />

                    @error('form.city_id')
                        <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                    @enderror

                    @if ($this->cityResults->isNotEmpty())
                        <div class="flex flex-col gap-1 rounded-tile border border-zinc-200 p-1 dark:border-zinc-800">
                            @foreach ($this->cityResults as $city)
                                <button
                                    type="button"
                                    wire:click="selectCity({{ $city->id }}, @js($city->name))"
                                    x-on:click="$haptic('medium')"
                                    wire:key="ce-city-{{ $city->id }}"
                                    class="pressable flex items-center gap-2 rounded-md px-3 py-2 text-start active:bg-zinc-100 dark:active:bg-zinc-800"
                                >
                                    <flux:icon name="map-pin" class="size-4 shrink-0 text-zinc-400"/>
                                    <span class="truncate text-sm font-medium">{{ $city->name }}</span>
                                    <flux:text class="ms-auto shrink-0 text-xs">{{ $city->country->name }}</flux:text>
                                </button>
                            @endforeach
                        </div>
                    @elseif (mb_strlen(trim($cityQuery)) >= 2)
                        <div class="flex flex-col gap-2 rounded-tile border border-zinc-200 p-3 dark:border-zinc-800">
                            <flux:text class="text-sm">{{ __('Keine Stadt gefunden.') }}</flux:text>
                            <flux:button
                                type="button"
                                size="sm"
                                variant="ghost"
                                icon="plus"
                                x-on:click="$haptic('medium'); $flux.modal('create-city').show(); Livewire.dispatch('open-city-editor', { name: @js(trim($cityQuery)) })"
                                class="w-fit cursor-pointer"
                            >
                                {{ __('Stadt anlegen') }}
                            </flux:button>
                        </div>
                    @endif
                @endif
            </div>

            {{-- The place in plain words — the portal keeps it as free text. --}}
            <flux:input
                wire:model="form.location"
                :label="__('Ort')"
                :placeholder="__('z. B. Bitcoin-Bar, Musterstraße 21')"
            />

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
                {{-- Optionales End-Datum für mehrtägige Kurs-Events; leer = selber Tag. --}}
                <flux:input
                    wire:model="form.to_date"
                    type="date"
                    :label="__('End-Datum (optional)')"
                    :description="__('Nur für mehrtägige Termine — leer lassen für eintägige.')"
                />
                @error('form.to_date')
                    <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror
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
