<?php

use App\Data\Portal\CourseData;
use App\Data\Portal\LecturerData;
use App\Livewire\Concerns\HandlesImageUpload;
use App\Livewire\Concerns\HandlesPortalWriteFeedback;
use App\Livewire\Forms\CourseForm;
use App\Services\PortalApi;
use App\Services\PortalWriter;
use App\Services\WriteResult;
use App\Support\Markdown;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Kurs-Editor (Phase 7.2): das Create-/Edit-Formular für Kurse. Wie die übrigen
 * Editoren einmal im Layout eingebettet, besitzt es das Bottom-Sheet
 * `create-course`. Geöffnet über das `open-course-editor`-Event (ohne ID =
 * Anlegen, mit ID = einen eigenen Kurs bearbeiten).
 *
 * Felder: Name, Referent (per Namen gesucht → lecturer_id, mit inline „Referent
 * anlegen"), Beschreibung (Markdown). Anlegen/Bearbeiten erfordert serverseitig
 * den Referenten-Status (is_lecturer) bzw. Eigentum (403). Schreibt über den
 * {@see PortalWriter}; 422-Feldfehler werden an die Form-Felder gemappt.
 */
new class extends Component {
    use HandlesImageUpload, HandlesPortalWriteFeedback;

    public CourseForm $form;

    /** Null = Anlegen, sonst die ID des bearbeiteten eigenen Kurses. */
    public ?int $editingId = null;

    /** Suchbegriff für die Referenten-Auswahl (eigenes Feld, nicht Teil der Payload). */
    public string $lecturerQuery = '';

    /** Markdown-Vorschau der Beschreibung umschalten. */
    public bool $showPreview = false;

    #[On('open-course-editor')]
    public function open(?int $id = null): void
    {
        $this->resetEditor();

        if ($id !== null) {
            $this->loadForEdit($id);
        }
    }

    protected function imageUploadKey(): string
    {
        return 'course-logo';
    }

    private function resetEditor(): void
    {
        $this->form->reset();
        $this->editingId = null;
        $this->lecturerQuery = '';
        $this->showPreview = false;
        $this->resetImageState();
        $this->resetErrorBag();
    }

    private function loadForEdit(int $id): void
    {
        $course = app(PortalApi::class)
            ->myCourses()
            ->first(fn (CourseData $candidate): bool => $candidate->id === $id);

        if ($course === null) {
            Flux::toast(text: __('Dieser Kurs konnte nicht geladen werden.'), variant: 'danger');

            return;
        }

        $this->editingId = $course->id;
        $this->setCurrentImageUrl($course->imageOrNull());
        $this->form->setCourse($course, $course->lecturerOrNull()?->name ?? '');
    }

    /**
     * Referenten-Treffer für die Auswahl (ab 2 Zeichen, debounced).
     *
     * @return Collection<int, LecturerData>
     */
    #[Computed]
    public function lecturerResults(): Collection
    {
        $query = trim($this->lecturerQuery);

        if (mb_strlen($query) < 2) {
            return collect();
        }

        return app(PortalApi::class)
            ->lecturers($query, withDetails: true)
            ->take(8)
            ->values();
    }

    public function selectLecturer(int $id, string $name): void
    {
        $this->form->lecturer_id = $id;
        $this->form->lecturerName = $name;
        $this->lecturerQuery = '';
        $this->resetErrorBag('form.lecturer_id');
        unset($this->lecturerResults);
    }

    public function clearLecturer(): void
    {
        $this->form->lecturer_id = null;
        $this->form->lecturerName = '';
    }

    /**
     * Einen im Referenten-Editor frisch angelegten Referenten direkt übernehmen
     * (inline aus dem Kurs-Flow). Greift nur, wenn noch keiner gewählt ist.
     */
    #[On('lecturer-saved')]
    public function useSavedLecturer(int $id, string $name): void
    {
        if ($this->form->lecturer_id !== null) {
            return;
        }

        $this->selectLecturer($id, $name);
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

    public function save(): void
    {
        $payload = $this->form->payload();

        $writer = app(PortalWriter::class);

        $result = $this->editingId === null
            ? $writer->createCourse($payload)
            : $writer->updateCourse($this->editingId, $payload);

        if ($result->successful()) {
            // Zweistufig: der Kurs existiert jetzt, das Logo geht separat raus.
            $logoFailed = $this->uploadSelectedImage($this->editingId ?? $result->createdId());

            $this->handleSuccess();
            $this->warnIfImageUploadFailed($logoFailed);

            return;
        }

        $this->reportWriteFailure($result, __('Du darfst diesen Kurs nicht bearbeiten.'));
    }

    protected function uploadImage(int $id, string $filePath): WriteResult
    {
        return app(PortalWriter::class)->uploadCourseLogo($id, $filePath);
    }

    private function handleSuccess(): void
    {
        $created = $this->editingId === null;

        Flux::modal('create-course')->close();
        Flux::toast(
            text: $created ? __('Kurs angelegt.') : __('Kurs aktualisiert.'),
            variant: 'success',
        );

        $this->dispatch('teaching-changed');
        $this->js("window.haptic && window.haptic('success')");
        $this->resetEditor();
    }
};
?>

<x-sheet name="create-course" :heading="$editingId ? __('Kurs bearbeiten') : __('Kurs anlegen')">
    <form wire:submit="save" class="flex flex-col gap-5">
        <flux:input
            wire:model="form.name"
            :label="__('Kursname')"
            :placeholder="__('z. B. Bitcoin, Blockchain und Geld')"
            required
        />

        <x-image-picker
            :label="__('Logo')"
            :current-url="$currentImageUrl"
            :has-selected="$this->hasSelectedImage()"
            shape="square"
            :hint="__('JPEG, PNG, WebP oder AVIF, max. 5 MB.')"
        />

        {{-- Referent: gewählter Referent als Chip, sonst Suche. --}}
        <div class="flex flex-col gap-2">
            <flux:label>{{ __('Referent') }}</flux:label>

            @if ($form->lecturer_id)
                <div class="flex items-center justify-between gap-3 rounded-tile border border-zinc-200 px-4 py-3 dark:border-zinc-800">
                    <span class="flex min-w-0 items-center gap-2">
                        <flux:icon name="user" class="size-5 shrink-0 text-brand-600 dark:text-brand-400"/>
                        <span class="truncate font-semibold">{{ $form->lecturerName !== '' ? $form->lecturerName : __('Referent gewählt') }}</span>
                    </span>
                    <flux:button wire:click="clearLecturer" type="button" size="xs" variant="ghost" icon="x-mark" :aria-label="__('Referent ändern')" class="cursor-pointer"/>
                </div>
            @else
                <flux:input
                    wire:model.live.debounce.300ms="lecturerQuery"
                    type="search"
                    icon="magnifying-glass"
                    :placeholder="__('Referent suchen …')"
                />

                @error('form.lecturer_id')
                    <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
                @enderror

                @if ($this->lecturerResults->isNotEmpty())
                    <div class="flex flex-col gap-1 rounded-tile border border-zinc-200 p-1 dark:border-zinc-800">
                        @foreach ($this->lecturerResults as $lecturer)
                            <button
                                type="button"
                                wire:click="selectLecturer({{ $lecturer->id }}, @js($lecturer->name))"
                                x-on:click="$haptic('medium')"
                                wire:key="course-lecturer-{{ $lecturer->id }}"
                                class="pressable flex items-center gap-2 rounded-md px-3 py-2 text-start active:bg-zinc-100 dark:active:bg-zinc-800"
                            >
                                <flux:icon name="user" class="size-4 shrink-0 text-zinc-400"/>
                                <span class="truncate text-sm font-medium">{{ $lecturer->name }}</span>
                                @if ($lecturer->subtitleOrNull())
                                    <flux:text class="ms-auto shrink-0 text-xs">{{ $lecturer->subtitleOrNull() }}</flux:text>
                                @endif
                            </button>
                        @endforeach
                    </div>
                @elseif (mb_strlen(trim($lecturerQuery)) >= 2)
                    {{-- Inline „Referent anlegen": der Referenten-Editor öffnet mit
                         dem Suchbegriff als Namensvorschlag; nach dem Speichern
                         übernimmt useSavedLecturer() den neuen Referenten. --}}
                    <div class="flex flex-col gap-2 rounded-tile border border-zinc-200 p-3 dark:border-zinc-800">
                        <flux:text class="text-sm">{{ __('Keinen Referenten gefunden.') }}</flux:text>
                        <flux:button
                            type="button"
                            size="sm"
                            variant="ghost"
                            icon="plus"
                            x-on:click="$haptic('medium'); $flux.modal('create-lecturer').show(); Livewire.dispatch('open-lecturer-editor', { name: @js(trim($lecturerQuery)) })"
                            class="w-fit cursor-pointer"
                        >
                            {{ __('Referent anlegen') }}
                        </flux:button>
                    </div>
                @endif
            @endif
        </div>

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
                    :placeholder="__('Worum geht es in diesem Kurs? Markdown wird unterstützt.')"
                />
            @endif
        </div>

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
</x-sheet>
