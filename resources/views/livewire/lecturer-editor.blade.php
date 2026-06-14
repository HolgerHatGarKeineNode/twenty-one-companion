<?php

use App\Data\Portal\MyLecturerData;
use App\Livewire\Concerns\HandlesPortalWriteFeedback;
use App\Livewire\Forms\LecturerForm;
use App\Services\PortalApi;
use App\Services\PortalWriter;
use App\Support\Markdown;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Referenten-Editor (Phase 7.1): das Create-/Edit-Formular für Referenten-
 * Profile. Wie die übrigen Editoren einmal im Layout eingebettet, besitzt es
 * das Bottom-Sheet `create-lecturer`. Geöffnet über das `open-lecturer-editor`-
 * Event (ohne ID = Anlegen, mit `name` = Namensvorschlag aus dem inline-Flow
 * des Kurs-Editors, mit ID = ein eigenes Profil bearbeiten).
 *
 * Anlegen darf jeder verbundene Nutzer (Portal-Policy), Bearbeiten nur der
 * Ersteller (403). Schreibt über den {@see PortalWriter}; 422-Feldfehler werden
 * an die Form-Felder gemappt. Nach dem Anlegen meldet `lecturer-saved` die neue
 * id+name zurück (für die inline-Übernahme im Kurs-Editor), `teaching-changed`
 * lädt die Hub-Listen neu.
 */
new class extends Component {
    use HandlesPortalWriteFeedback;

    public LecturerForm $form;

    /** Null = Anlegen, sonst die ID des bearbeiteten eigenen Profils. */
    public ?int $editingId = null;

    /** Markdown-Vorschau der Bio umschalten. */
    public bool $showPreview = false;

    #[On('open-lecturer-editor')]
    public function open(?int $id = null, ?string $name = null): void
    {
        $this->resetEditor();

        if ($id !== null) {
            $this->loadForEdit($id);

            return;
        }

        if ($name !== null) {
            $this->form->name = $name;
        }
    }

    private function resetEditor(): void
    {
        $this->form->reset();
        $this->editingId = null;
        $this->showPreview = false;
        $this->resetErrorBag();
    }

    private function loadForEdit(int $id): void
    {
        $lecturer = app(PortalApi::class)
            ->myLecturers()
            ->first(fn (MyLecturerData $candidate): bool => $candidate->id === $id);

        if ($lecturer === null) {
            Flux::toast(text: __('Dieses Referenten-Profil konnte nicht geladen werden.'), variant: 'danger');

            return;
        }

        $this->editingId = $lecturer->id;
        $this->form->setLecturer($lecturer);
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
            ? $writer->createLecturer($payload)
            : $writer->updateLecturer($this->editingId, $payload);

        if ($result->successful()) {
            $this->handleSuccess($result->data);

            return;
        }

        $this->reportWriteFailure($result, __('Du darfst dieses Referenten-Profil nicht bearbeiten.'));
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    private function handleSuccess(array $data): void
    {
        $created = $this->editingId === null;

        Flux::modal('create-lecturer')->close();
        Flux::toast(
            text: $created ? __('Referent angelegt.') : __('Referent aktualisiert.'),
            variant: 'success',
        );

        if ($created) {
            $newId = $data['data']['id'] ?? null;
            if (is_int($newId)) {
                $this->dispatch('lecturer-saved', id: $newId, name: $this->form->name);
            }
        }

        $this->dispatch('teaching-changed');
        $this->js("window.haptic && window.haptic('success')");
        $this->resetEditor();
    }
};
?>

<x-sheet name="create-lecturer" :heading="$editingId ? __('Referent bearbeiten') : __('Referent anlegen')">
    <form wire:submit="save" class="flex flex-col gap-5">
        <flux:input
            wire:model="form.name"
            :label="__('Name')"
            :placeholder="__('z. B. Toni Stack')"
            required
        />

        <flux:input
            wire:model="form.subtitle"
            :label="__('Kurzbeschreibung')"
            :placeholder="__('z. B. Bitcoin-Educator')"
        />

        <flux:textarea
            wire:model="form.intro"
            :label="__('Intro')"
            rows="2"
            :placeholder="__('Ein Satz zu dir.')"
        />

        {{-- Bio mit Markdown-Vorschau-Toggle. --}}
        <div class="flex flex-col gap-2">
            <div class="flex items-center justify-between">
                <flux:label>{{ __('Bio') }}</flux:label>
                <flux:button wire:click="togglePreview" type="button" size="xs" variant="ghost" :icon="$showPreview ? 'pencil-square' : 'eye'" class="cursor-pointer">
                    {{ $showPreview ? __('Bearbeiten') : __('Vorschau') }}
                </flux:button>
            </div>

            @if ($showPreview)
                <div class="markdown min-h-24 rounded-tile border border-zinc-200 p-4 text-sm dark:border-zinc-800">
                    @if ($this->descriptionPreviewHtml)
                        {!! $this->descriptionPreviewHtml !!}
                    @else
                        <flux:text class="text-sm">{{ __('Noch keine Bio.') }}</flux:text>
                    @endif
                </div>
            @else
                <flux:textarea
                    wire:model="form.description"
                    rows="4"
                    :placeholder="__('Erzähl etwas über dich. Markdown wird unterstützt.')"
                />
            @endif
        </div>

        {{-- Links & Socials. --}}
        <div class="flex flex-col gap-3">
            <flux:input wire:model="form.website" :label="__('Webseite')" type="url" placeholder="https://…"/>
            <flux:input wire:model="form.nostr" :label="__('Nostr')" placeholder="npub… / nprofile…"/>
            <flux:input wire:model="form.twitter_username" :label="__('X / Twitter')" placeholder="username"/>
            <flux:input wire:model="form.lightning_address" :label="__('Lightning-Adresse')" placeholder="name@wallet.example"/>
        </div>

        {{-- Status-Schalter. --}}
        <div class="flex flex-col gap-3 rounded-tile border border-zinc-200 p-4 dark:border-zinc-800">
            <flux:switch wire:model="form.active" :label="__('Aktiv')" :description="__('Inaktive Profile bleiben verborgen.')"/>
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
