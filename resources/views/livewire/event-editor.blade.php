<?php

use App\Data\Portal\MeetupData;
use App\Data\Portal\MyMeetupEventData;
use App\Livewire\Concerns\HandlesPortalWriteFeedback;
use App\Livewire\Forms\EventForm;
use App\Services\PortalApi;
use App\Services\PortalWriter;
use App\Support\Clock;
use App\Support\Markdown;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Termin-Editor (Phase 5.1/5.2): das Create-/Edit-Formular hinter dem
 * Create-FAB (Termine-Tab) und der Termin-Verwaltung im Meetup-Detail. Wie der
 * {@see App\Livewire\Forms\MeetupForm}-Editor einmal im Layout eingebettet,
 * besitzt es das Bottom-Sheet `create-event`. Geöffnet über das
 * `open-event-editor`-Event: ohne Argumente = Anlegen (Meetup frei wählbar),
 * mit `meetupId` = Anlegen für ein bestimmtes Meetup, mit `eventId` = einen
 * eigenen Termin bearbeiten.
 *
 * Schreibt über den {@see PortalWriter}; 422-Feldfehler werden zurück an die
 * Form-Felder gemappt (das Portal-Feld `start` auf das Datumsfeld), Erfolg/
 * Fehler über Toast + Haptik bestätigt. Nach Erfolg lädt die Termin-Liste über
 * das `meetup-event-saved`-Event neu.
 */
new class extends Component {
    use HandlesPortalWriteFeedback;

    public EventForm $form;

    /** Null = Anlegen, sonst die ID des bearbeiteten eigenen Termins. */
    public ?int $editingId = null;

    /**
     * Erst nach dem ersten Öffnen true. Verhindert, dass die global im Layout
     * eingebettete Komponente schon beim Seiten-Render die eigenen Meetups
     * abruft — der API-Call läuft erst, wenn der FAB/Edit das Sheet öffnet.
     */
    public bool $ready = false;

    /** Meetup-Auswahl sperren (Bearbeiten oder Anlegen aus einem Meetup-Detail). */
    public bool $meetupLocked = false;

    /** Markdown-Vorschau der Beschreibung umschalten. */
    public bool $showPreview = false;

    /**
     * Öffnen aus dem FAB (Anlegen, Meetup frei), aus dem Meetup-Detail
     * (Anlegen mit vorgewähltem Meetup) oder per Edit-Affordance (Bearbeiten).
     * Das Sheet selbst öffnet clientseitig; hier wird nur der Zustand
     * vorbereitet.
     */
    #[On('open-event-editor')]
    public function open(?int $eventId = null, ?int $meetupId = null): void
    {
        $this->ready = true;
        $this->resetEditor();

        if ($eventId !== null) {
            $this->loadForEdit($eventId);

            return;
        }

        if ($meetupId !== null) {
            $this->form->meetup_id = $meetupId;
            $this->form->meetupName = $this->myMeetups
                ->first(fn (MeetupData $meetup): bool => $meetup->id === $meetupId)?->name ?? '';
            $this->meetupLocked = true;

            return;
        }

        // Den ersten eigenen Meetup vorauswählen: die native Select-Box zeigt
        // ohnehin den ersten Eintrag an, bindet wire:model aber erst bei einer
        // echten Änderung (change-Event). Ohne Vorauswahl bliebe meetup_id null,
        // obwohl ein Meetup sichtbar gewählt ist → „meetup_id required" beim
        // Speichern. Der Nutzer kann jederzeit auf ein anderes wechseln.
        $first = $this->myMeetups->first();

        if ($first !== null) {
            $this->form->meetup_id = $first->id;
            $this->form->meetupName = $first->name;
        }
    }

    private function resetEditor(): void
    {
        $this->form->reset();
        $this->editingId = null;
        $this->meetupLocked = false;
        $this->showPreview = false;
        $this->resetErrorBag();
    }

    /**
     * Eigenen Termin zum Bearbeiten laden. Den Meetup-Namen lösen wir aus
     * den eigenen Meetups auf (netzwerkfrei) — die MeetupEventResource liefert
     * nur die meetup_id.
     */
    private function loadForEdit(int $id): void
    {
        $event = app(PortalApi::class)
            ->myMeetupEvents()
            ->first(fn (MyMeetupEventData $candidate): bool => $candidate->id === $id);

        if ($event === null) {
            Flux::toast(text: __('Dieser Termin konnte nicht geladen werden.'), variant: 'danger');

            return;
        }

        $meetupName = $this->myMeetups
            ->first(fn (MeetupData $meetup): bool => $meetup->id === $event->meetup_id)?->name ?? '';

        $this->editingId = $event->id;
        $this->meetupLocked = true;
        $this->form->setEvent($event, $meetupName);
    }

    /**
     * Die eigenen Meetups für die Auswahl. Ohne Token leer (das Schreib-Gate
     * im PortalWriter fängt den Rest ab).
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

    public function togglePreview(): void
    {
        $this->showPreview = ! $this->showPreview;
    }

    #[Computed]
    public function descriptionPreviewHtml(): ?string
    {
        return Markdown::toHtml($this->form->description);
    }

    /**
     * Anlegen oder Aktualisieren. Validiert zuerst über die Form; beim Anlegen
     * darf der Termin nicht in der Vergangenheit liegen.
     */
    public function save(): void
    {
        $payload = $this->form->payload();

        if ($this->editingId === null && $this->startsInPast()) {
            $this->addError('form.date', __('Der Termin darf nicht in der Vergangenheit liegen.'));

            return;
        }

        if ($this->editingId === null && $this->hasInvalidRecurrence()) {
            return;
        }

        $writer = app(PortalWriter::class);

        $result = $this->editingId === null
            ? $writer->createMeetupEvent($payload)
            : $writer->updateMeetupEvent($this->editingId, $payload);

        if ($result->successful()) {
            $this->handleSuccess();

            return;
        }

        // Das Portal-Feld `start` (kombiniertes Datum+Zeit) zeigen wir am Datumsfeld an.
        $this->reportWriteFailure($result, __('Du darfst diesen Termin nicht bearbeiten.'), ['start' => 'date']);
    }

    private function startsInPast(): bool
    {
        return Clock::localIsPast($this->form->date.' '.$this->form->time);
    }

    /**
     * Prüft die Serien-Eingaben und mappt fehlende/falsche Felder zurück an die
     * Form. Greift nur im Serien-Modus (repeats); gibt true zurück, wenn die
     * Serie unvollständig ist und nicht gesendet werden darf.
     */
    private function hasInvalidRecurrence(): bool
    {
        if (! $this->form->repeats) {
            return false;
        }

        $invalid = false;

        if ($this->form->recurrence_type === '') {
            $this->addError('form.recurrence_type', __('Bitte wähle einen Wiederhol-Typ.'));
            $invalid = true;
        }

        if ($this->form->recurrence_end_date === '') {
            $this->addError('form.recurrence_end_date', __('Bitte wähle ein Enddatum für die Serie.'));
            $invalid = true;
        } elseif ($this->form->recurrence_end_date < $this->form->date) {
            $this->addError('form.recurrence_end_date', __('Das Enddatum muss nach dem Startdatum liegen.'));
            $invalid = true;
        }

        // „Benutzerdefiniert" (z. B. „2. Dienstag im Monat") braucht Wochentag + Position.
        if ($this->form->recurrence_type === 'custom'
            && ($this->form->recurrence_day_of_week === '' || $this->form->recurrence_day_position === '')) {
            $this->addError('form.recurrence_day_position', __('Bitte wähle Wochentag und Position.'));
            $invalid = true;
        }

        return $invalid;
    }

    private function handleSuccess(): void
    {
        $created = $this->editingId === null;
        $series = $created && $this->form->repeats;

        Flux::modal('create-event')->close();
        Flux::toast(
            text: $series
                ? __('Terminserie angelegt.')
                : ($created ? __('Termin angelegt.') : __('Termin aktualisiert.')),
            variant: 'success',
        );

        $this->dispatch('meetup-event-saved');
        $this->js("window.haptic && window.haptic('success')");
        $this->resetEditor();
    }
};
?>

<x-sheet name="create-event" :heading="$ready ? ($editingId ? __('Termin bearbeiten') : __('Termin anlegen')) : ''">
    @if (! $ready)
        {{-- Vor dem ersten Öffnen kein Datenabruf (global im Layout eingebettet). --}}
    @elseif ($this->myMeetups->isEmpty())
        {{-- Termine hängen an einem eigenen Meetup — ohne Meetup kein Termin. --}}
        <div class="flex flex-col items-center gap-3 py-6 text-center">
            <span class="flex size-14 items-center justify-center rounded-tile bg-brand-500/10 text-brand-600 dark:text-brand-400">
                <flux:icon name="calendar-days" class="size-7"/>
            </span>
            <flux:text class="max-w-xs">
                {{ __('Lege zuerst ein eigenes Meetup an — Termine gehören immer zu einem Meetup.') }}
            </flux:text>
            <flux:button
                type="button"
                variant="primary"
                icon="plus"
                x-on:click="$haptic('medium'); $flux.modal('create-event').close(); $flux.modal('create-meetup').show(); Livewire.dispatch('open-meetup-editor')"
                class="cursor-pointer"
            >
                {{ __('Meetup anlegen') }}
            </flux:button>
        </div>
    @else
        <form wire:submit="save" class="flex flex-col gap-5">
            {{-- Meetup-Auswahl: gesperrt als Chip (Bearbeiten / aus Meetup-Detail), sonst Select. --}}
            <div class="flex flex-col gap-2">
                <flux:label>{{ __('Meetup') }}</flux:label>

                @if ($meetupLocked)
                    <div class="flex items-center gap-2 rounded-tile border border-zinc-200 px-4 py-3 dark:border-zinc-800">
                        <flux:icon name="user-group" class="size-5 shrink-0 text-brand-600 dark:text-brand-400"/>
                        <span class="truncate font-semibold">{{ $form->meetupName !== '' ? $form->meetupName : __('Meetup gewählt') }}</span>
                    </div>
                @else
                    <flux:select wire:model="form.meetup_id" :placeholder="__('Meetup wählen …')">
                        @foreach ($this->myMeetups as $meetup)
                            <flux:select.option :value="$meetup->id" wire:key="event-meetup-{{ $meetup->id }}">{{ $meetup->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    @error('form.meetup_id')
                        <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                    @enderror
                @endif
            </div>

            {{-- Datum + Uhrzeit (native Picker auf dem Gerät). --}}
            <div class="flex gap-3">
                <div class="flex flex-1 flex-col gap-2">
                    <flux:input wire:model="form.date" type="date" :label="__('Datum')" required/>
                    @error('form.date')
                        <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                    @enderror
                </div>
                <div class="flex flex-1 flex-col gap-2">
                    <flux:input wire:model="form.time" type="time" :label="__('Uhrzeit')" required/>
                    @error('form.time')
                        <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                    @enderror
                </div>
            </div>

            <flux:input
                wire:model="form.location"
                :label="__('Ort')"
                :placeholder="__('z. B. Bitcoin-Bar, Musterstraße 21')"
            />

            {{-- Beschreibung mit Markdown-Vorschau-Toggle. --}}
            <div class="flex flex-col gap-2">
                <div class="flex items-center justify-between">
                    <flux:label>{{ __('Beschreibung') }}</flux:label>
                    <flux:button wire:click="togglePreview" type="button" size="xs" variant="ghost" :icon="$showPreview ? 'pencil-square' : 'eye'" class="cursor-pointer">
                        {{ $showPreview ? __('Bearbeiten') : __('Vorschau') }}
                    </flux:button>
                </div>

                @if ($showPreview)
                    <div class="markdown min-h-24 rounded-tile border border-zinc-200 p-4 text-sm dark:border-zinc-800">
                        @if ($this->descriptionPreviewHtml)
                            {!! $this->descriptionPreviewHtml !!}
                        @else
                            <flux:text class="text-sm">{{ __('Noch keine Beschreibung.') }}</flux:text>
                        @endif
                    </div>
                @else
                    <flux:textarea
                        wire:model="form.description"
                        rows="4"
                        :placeholder="__('Worum geht es bei diesem Termin? Markdown wird unterstützt.')"
                    />
                @endif
            </div>

            <flux:input wire:model="form.link" :label="__('Online-Link')" type="url" placeholder="https://…"/>

            {{-- Wiederkehrender Termin (nur beim Anlegen): das Portal expandiert die
                 Regel über die gemeinsame Action in einzelne Termine (max. 100). --}}
            @if (! $editingId)
                <div class="flex flex-col gap-3 rounded-tile border border-zinc-200 p-4 dark:border-zinc-800">
                    <flux:switch
                        wire:model.live="form.repeats"
                        :label="__('Wiederkehrender Termin')"
                        :description="__('Als Serie einzelner Termine anlegen.')"
                    />

                    @if ($form->repeats)
                        <div class="flex flex-col gap-2">
                            <flux:select wire:model.live="form.recurrence_type" :label="__('Wiederholung')" :placeholder="__('Typ wählen …')">
                                <flux:select.option value="weekly">{{ __('Wöchentlich') }}</flux:select.option>
                                <flux:select.option value="monthly">{{ __('Monatlich (gleiches Datum)') }}</flux:select.option>
                                <flux:select.option value="custom">{{ __('Monatlich (an einem Wochentag)') }}</flux:select.option>
                            </flux:select>
                            @error('form.recurrence_type')
                                <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                            @enderror
                        </div>

                        @if (in_array($form->recurrence_type, ['weekly', 'custom'], true))
                            <flux:select
                                wire:model="form.recurrence_day_of_week"
                                :label="$form->recurrence_type === 'custom' ? __('Wochentag') : __('Wochentag (optional)')"
                                :placeholder="__('Wochentag wählen …')"
                            >
                                <flux:select.option value="monday">{{ __('Montag') }}</flux:select.option>
                                <flux:select.option value="tuesday">{{ __('Dienstag') }}</flux:select.option>
                                <flux:select.option value="wednesday">{{ __('Mittwoch') }}</flux:select.option>
                                <flux:select.option value="thursday">{{ __('Donnerstag') }}</flux:select.option>
                                <flux:select.option value="friday">{{ __('Freitag') }}</flux:select.option>
                                <flux:select.option value="saturday">{{ __('Samstag') }}</flux:select.option>
                                <flux:select.option value="sunday">{{ __('Sonntag') }}</flux:select.option>
                            </flux:select>
                        @endif

                        @if ($form->recurrence_type === 'custom')
                            <div class="flex flex-col gap-2">
                                <flux:select wire:model="form.recurrence_day_position" :label="__('Position im Monat')" :placeholder="__('Position wählen …')">
                                    <flux:select.option value="first">{{ __('Erster') }}</flux:select.option>
                                    <flux:select.option value="second">{{ __('Zweiter') }}</flux:select.option>
                                    <flux:select.option value="third">{{ __('Dritter') }}</flux:select.option>
                                    <flux:select.option value="fourth">{{ __('Vierter') }}</flux:select.option>
                                    <flux:select.option value="last">{{ __('Letzter') }}</flux:select.option>
                                </flux:select>
                                @error('form.recurrence_day_position')
                                    <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                                @enderror
                            </div>
                        @endif

                        <div class="flex flex-col gap-2">
                            <flux:input wire:model="form.recurrence_end_date" type="date" :label="__('Endet am')"/>
                            @error('form.recurrence_end_date')
                                <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                            @enderror
                        </div>
                    @endif
                </div>
            @endif

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
