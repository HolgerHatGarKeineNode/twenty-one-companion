<?php

use App\Data\Portal\CityData;
use App\Data\Portal\MapMeetupData;
use App\Data\Portal\MeetupData;
use App\Livewire\Concerns\HandlesImageUpload;
use App\Livewire\Concerns\HandlesPortalWriteFeedback;
use App\Livewire\Forms\MeetupForm;
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
 * Meetup-Editor (Phase 4.1/4.2): das Create-/Edit-Formular hinter dem
 * Create-FAB und der Bearbeiten-Affordance im „Meine“-Tab. Als eigenständige
 * Livewire-Komponente einmal im Layout eingebettet (wie die globale Suche),
 * besitzt sie das Bottom-Sheet `create-meetup`. Geöffnet wird sie über das
 * `open-meetup-editor`-Event (ohne ID = Anlegen, mit ID = das eigene Meetup
 * bearbeiten); das Sheet selbst öffnet/schließt clientseitig über die
 * Flux-Modal-API.
 *
 * Schreibt über den {@see PortalWriter}; 422-Feldfehler werden zurück an die
 * Form-Felder gemappt, Erfolg/Fehler über Toast + Haptik bestätigt.
 */
new class extends Component
{
    use HandlesImageUpload, HandlesPortalWriteFeedback;

    public MeetupForm $form;

    /** Null = Anlegen, sonst die ID des bearbeiteten eigenen Meetups. */
    public ?int $editingId = null;

    /** Darf der Nutzer für dieses Meetup Leader verwalten (ist selbst Leader)? */
    public bool $canManageLeaders = false;

    /** Suchbegriff für die Stadt-Auswahl (eigenes Feld, nicht Teil der Payload). */
    public string $cityQuery = '';

    /** Markdown-Vorschau der Beschreibung umschalten (Phase 4.5). */
    public bool $showPreview = false;

    /** Ein mögliches Duplikat bewusst überstimmen (Phase 4.1). */
    public bool $ignoreDuplicates = false;

    /** @var Collection<int, MapMeetupData>|null Karten-Meetups einmal pro Request gemappt. */
    private ?Collection $cachedMapMeetups = null;

    /**
     * Öffnen aus FAB (Anlegen) oder Karte (Bearbeiten). Das Sheet selbst
     * öffnet clientseitig; hier wird nur der Zustand vorbereitet.
     */
    #[On('open-meetup-editor')]
    public function open(?int $id = null): void
    {
        $this->resetEditor();

        if ($id !== null) {
            $this->loadForEdit($id);
        }
    }

    protected function imageUploadKey(): string
    {
        return 'meetup-logo';
    }

    private function resetEditor(): void
    {
        $this->form->reset();
        $this->editingId = null;
        $this->canManageLeaders = false;
        $this->cityQuery = '';
        $this->showPreview = false;
        $this->ignoreDuplicates = false;
        $this->resetImageState();
        $this->resetErrorBag();
    }

    /**
     * Eigenes Meetup zum Bearbeiten laden. Den Stadtnamen lösen wir aus den
     * gecachten Karten-Meetups (netzwerkfrei) über den Slug auf — die
     * MeetupResource selbst liefert nur die city_id.
     */
    private function loadForEdit(int $id): void
    {
        $meetup = app(PortalApi::class)
            ->myMeetups()
            ->first(fn (MeetupData $candidate): bool => $candidate->id === $id);

        if ($meetup === null) {
            Flux::toast(text: __('Dieses Meetup konnte nicht geladen werden.'), variant: 'danger');

            return;
        }

        $this->editingId = $meetup->id;
        $this->canManageLeaders = $meetup->is_leader;
        $this->setCurrentImageUrl($meetup->logo);
        $this->form->setMeetup($meetup);
        // Stadtname netzwerkfrei aus den gecachten Karten-Meetups (per Slug).
        $this->form->cityName = $this->cachedMapMeetups()
            ->first(fn (MapMeetupData $map): bool => $map->slug() === $meetup->slug)
            ?->city ?? '';
    }

    /**
     * Städte-Treffer für die Auswahl (ab 2 Zeichen, debounced). Nutzt den
     * Such-Endpunkt der PortalApi — ein bewusster Server-Treffer pro Begriff,
     * weil die volle Städteliste nicht vorgehalten wird.
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
        unset($this->cityResults, $this->duplicates);
    }

    public function clearCity(): void
    {
        $this->form->city_id = null;
        $this->form->cityName = '';
        unset($this->duplicates);
    }

    /**
     * Eine im City-Editor frisch angelegte Stadt direkt übernehmen (Phase 6.2:
     * inline aus dem Meetup-Flow). Greift nur, wenn noch keine Stadt gewählt ist.
     */
    #[On('city-saved')]
    public function useSavedCity(int $id, string $name): void
    {
        if ($this->form->city_id !== null) {
            return;
        }

        $this->selectCity($id, $name);
    }

    /**
     * Karten-Meetups einmal pro Request gemappt — die Duplikat-Checks
     * (duplicates + exactDuplicate) und das Edit-Laden lesen dieselbe Liste.
     * Gemeinsamer Cache-Key mit dem Meetups-Index (withIntro/withLogos: true).
     *
     * @return Collection<int, MapMeetupData>
     */
    private function cachedMapMeetups(): Collection
    {
        return $this->cachedMapMeetups ??= app(PortalApi::class)->mapMeetups(withIntro: true, withLogos: true);
    }

    /**
     * Mögliche Duplikate beim Anlegen (Phase 4.1): in-memory auf den
     * gecachten Karten-Meetups nach Name (und, wenn gewählt, Stadt). Beim
     * Bearbeiten irrelevant.
     *
     * @return Collection<int, MapMeetupData>
     */
    #[Computed]
    public function duplicates(): Collection
    {
        if ($this->editingId !== null) {
            return collect();
        }

        $name = mb_strtolower(trim($this->form->name));

        if (mb_strlen($name) < 3) {
            return collect();
        }

        $city = mb_strtolower(trim($this->form->cityName));

        return $this->cachedMapMeetups()
            ->filter(fn (MapMeetupData $map): bool => str_contains(mb_strtolower($map->name), $name)
                || ($city !== '' && mb_strtolower($map->city) === $city))
            ->sortBy(fn (MapMeetupData $map): string => mb_strtolower($map->name))
            ->take(4)
            ->values();
    }

    /**
     * Exakter Treffer (gleicher Name UND gleiche Stadt): harte Duplikat-Sperre
     * (Discovery-First). Statt anzulegen soll der Nutzer das bestehende Meetup
     * übernehmen. Nur beim Anlegen, nur wenn Name + Stadt gesetzt sind.
     */
    #[Computed]
    public function exactDuplicate(): ?MapMeetupData
    {
        if ($this->editingId !== null) {
            return null;
        }

        $name = mb_strtolower(trim($this->form->name));
        $city = mb_strtolower(trim($this->form->cityName));

        if ($name === '' || $city === '') {
            return null;
        }

        return $this->cachedMapMeetups()
            ->first(fn (MapMeetupData $map): bool => mb_strtolower($map->name) === $name
                && mb_strtolower($map->city) === $city);
    }

    /**
     * Das exakt passende bestehende Meetup zu „Meine“ hinzufügen, statt ein
     * Duplikat anzulegen (Discovery-First, Phase 4.3). Per Slug, da die
     * Karten-Liste keine numerische ID exponiert.
     */
    public function addExistingToMine(): void
    {
        $exact = $this->exactDuplicate;

        if ($exact === null) {
            return;
        }

        $result = app(PortalWriter::class)->addMeetupToMine($exact->slug());

        if ($result->successful()) {
            $this->reportWriteSuccess('create-meetup', __('Meetup zu „Meine“ hinzugefügt.'));
            $this->resetEditor();

            return;
        }

        $this->reportWriteFailure($result, __('Dieses Meetup konnte nicht hinzugefügt werden.'));
    }

    public function togglePreview(): void
    {
        $this->showPreview = ! $this->showPreview;
    }

    #[Computed]
    public function introPreviewHtml(): ?string
    {
        return Markdown::toHtml($this->form->intro);
    }

    /**
     * Anlegen oder Aktualisieren. Validiert zuerst clientseitig über die Form;
     * beim Anlegen wird ein Duplikat-Hinweis dazwischengeschaltet, bevor
     * tatsächlich gesendet wird.
     */
    public function save(): void
    {
        $payload = $this->form->payload();

        if ($this->editingId === null && $this->exactDuplicate !== null) {
            // Harte Sperre (Phase 4.3): exakter Name+Stadt-Treffer ist nicht
            // überstimmbar — der Nutzer übernimmt stattdessen das bestehende
            // Meetup (addExistingToMine). Niemals ein echtes Duplikat anlegen.
            return;
        }

        if ($this->editingId === null && ! $this->ignoreDuplicates && $this->duplicates->isNotEmpty()) {
            // Noch nicht senden: erst den (überstimmbaren) Duplikat-Hinweis zeigen (Phase 4.1).
            return;
        }

        $writer = app(PortalWriter::class);

        $result = $this->editingId === null
            ? $writer->createMeetup($payload)
            : $writer->updateMeetup($this->editingId, $payload);

        if ($result->successful()) {
            // Zweistufig: das Meetup existiert jetzt, das Logo geht separat raus.
            $logoFailed = $this->uploadSelectedImage($this->editingId ?? $result->createdId());

            $this->handleSuccess();
            $this->warnIfImageUploadFailed($logoFailed);

            return;
        }

        $this->reportWriteFailure($result, __('Du darfst dieses Meetup nicht bearbeiten.'));
    }

    protected function uploadImage(int $id, string $filePath): WriteResult
    {
        return app(PortalWriter::class)->uploadMeetupLogo($id, $filePath);
    }

    private function handleSuccess(): void
    {
        $this->reportWriteSuccess(
            'create-meetup',
            $this->editingId === null ? __('Meetup angelegt.') : __('Meetup aktualisiert.'),
        );
        $this->resetEditor();
    }
};
?>

<x-sheet name="create-meetup" :heading="$editingId ? __('Meetup bearbeiten') : __('Meetup anlegen')">
    <form wire:submit="save" class="flex flex-col gap-5">
        <flux:input
            wire:model="form.name"
            :label="__('Name')"
            :placeholder="__('z. B. Einundzwanzig Musterstadt')"
            required
        />

        {{-- Stadt: gewählte Stadt als Chip, sonst Suche (Phase 4.1). --}}
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
                                wire:key="city-{{ $city->id }}"
                                class="pressable flex items-center gap-2 rounded-md px-3 py-2 text-start active:bg-zinc-100 dark:active:bg-zinc-800"
                            >
                                <flux:icon name="map-pin" class="size-4 shrink-0 text-zinc-400"/>
                                <span class="truncate text-sm font-medium">{{ $city->name }}</span>
                                <flux:text class="ms-auto shrink-0 text-xs">{{ $city->country->name }}</flux:text>
                            </button>
                        @endforeach
                    </div>
                @elseif (mb_strlen(trim($cityQuery)) >= 2)
                    {{-- Inline „Stadt anlegen" (Phase 6.2): der City-Editor öffnet
                         mit dem Suchbegriff als Namensvorschlag; nach dem Speichern
                         übernimmt useSavedCity() die neue Stadt. --}}
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

        <x-image-picker
            :label="__('Logo')"
            :current-url="$currentImageUrl"
            :has-selected="$this->hasSelectedImage()"
            shape="square"
            :hint="__('JPEG, PNG, WebP oder AVIF, max. 5 MB.')"
        />

        {{-- Beschreibung mit Markdown-Vorschau-Toggle (Phase 4.5). --}}
        <div class="flex flex-col gap-2">
            <div class="flex items-center justify-between">
                <flux:label>{{ __('Beschreibung') }}</flux:label>
                <flux:button wire:click="togglePreview" type="button" size="xs" variant="ghost" :icon="$showPreview ? 'pencil-square' : 'eye'" class="cursor-pointer">
                    {{ $showPreview ? __('Bearbeiten') : __('Vorschau') }}
                </flux:button>
            </div>

            @if ($showPreview)
                <div class="markdown min-h-24 rounded-tile border border-zinc-200 p-4 text-sm dark:border-zinc-800">
                    @if ($this->introPreviewHtml)
                        {!! $this->introPreviewHtml !!}
                    @else
                        <flux:text class="text-sm">{{ __('Noch keine Beschreibung.') }}</flux:text>
                    @endif
                </div>
            @else
                <flux:textarea
                    wire:model="form.intro"
                    rows="4"
                    :placeholder="__('Worum geht es bei eurem Meetup? Markdown wird unterstützt.')"
                />
            @endif
        </div>

        {{-- Links & Socials. --}}
        <div class="flex flex-col gap-3">
            <flux:input wire:model="form.telegram_link" :label="__('Telegram-Link')" type="url" placeholder="https://t.me/…"/>
            <flux:input wire:model="form.webpage" :label="__('Webseite')" type="url" placeholder="https://…"/>
            <flux:input wire:model="form.nostr" :label="__('Nostr')" placeholder="npub… / nprofile…"/>
            <flux:input wire:model="form.twitter_username" :label="__('X / Twitter')" placeholder="username"/>
        </div>

        {{-- Status-Schalter. --}}
        <div class="flex flex-col gap-3 rounded-tile border border-zinc-200 p-4 dark:border-zinc-800">
            <flux:switch wire:model="form.is_active" :label="__('Aktiv')" :description="__('Inaktive Meetups bleiben verborgen.')"/>
            <flux:switch wire:model="form.visible_on_map" :label="__('Auf der Karte zeigen')"/>
        </div>

        {{-- Anmeldung & Sichtbarkeit: RSVP-Funktion und öffentliche Teilnehmerliste. --}}
        <div class="flex flex-col gap-4 rounded-tile border border-zinc-200 p-4 dark:border-zinc-800"
             x-data="{ rsvp: $wire.entangle('form.rsvp_enabled') }">
            <flux:switch
                wire:model.live="form.rsvp_enabled"
                :label="__('Anmeldung (RSVP) aktivieren')"
                :description="__('Besucher können sich für Termine an- oder abmelden.')"/>

            <div x-bind:class="rsvp ? '' : 'opacity-50 pointer-events-none'">
                <flux:switch
                    wire:model="form.attendees_public"
                    x-bind:disabled="!rsvp"
                    :label="__('Teilnehmerliste öffentlich zeigen')"
                    :description="__('Aus: nur du und weitere Leader sehen, wer kommt.')"/>
            </div>
        </div>

        {{-- Leader verwalten (Leader-Delegation): nur beim Bearbeiten und nur,
             wenn der Nutzer selbst Leader ist. Öffnet das gestapelte
             `meetup-leaders`-Sheet (wie der inline „Stadt anlegen“-Flow). --}}
        @if ($editingId && $canManageLeaders)
            <button
                type="button"
                x-on:click="$haptic('medium'); $flux.modal('meetup-leaders').show(); Livewire.dispatch('open-meetup-leaders', { id: {{ $editingId }}, name: @js($form->name) })"
                class="pressable flex w-full items-center gap-3 rounded-tile border border-zinc-200 p-4 text-start active:bg-zinc-100 dark:border-zinc-800 dark:active:bg-zinc-800"
            >
                <flux:icon name="user-group" class="size-6 shrink-0 text-brand-600 dark:text-brand-400"/>
                <span class="flex min-w-0 flex-1 flex-col">
                    <span class="font-semibold">{{ __('Leader verwalten') }}</span>
                    <flux:text class="text-sm">{{ __('Andere Personen per npub als Leader einsetzen.') }}</flux:text>
                </span>
                <flux:icon name="chevron-right" class="size-5 shrink-0 text-zinc-400"/>
            </button>
        @endif

        {{-- Harte Duplikat-Sperre (Phase 4.3): exakter Name+Stadt-Treffer. Statt
             ein Duplikat anzulegen, das bestehende Meetup zu „Meine“ übernehmen. --}}
        @if ($this->exactDuplicate)
            <div class="flex flex-col gap-3 rounded-tile border border-red-300 bg-red-50 p-4 dark:border-red-500/40 dark:bg-red-500/10">
                <span class="flex items-center gap-2 font-semibold text-red-800 dark:text-red-300">
                    <flux:icon name="exclamation-triangle" class="size-5"/>
                    {{ __('Dieses Meetup gibt es schon') }}
                </span>
                <flux:text class="text-sm">
                    {{ __('In :city existiert dieses Meetup bereits. Lege kein Duplikat an — füge es stattdessen zu deinen Meetups hinzu.', ['city' => $this->exactDuplicate->city]) }}
                </flux:text>
                <a
                    href="{{ route('meetups.show', $this->exactDuplicate->slug()) }}"
                    wire:navigate
                    class="flex items-center gap-2 text-sm font-medium text-red-900 underline dark:text-red-200"
                >
                    <flux:icon name="arrow-up-right" class="size-4"/>
                    {{ $this->exactDuplicate->name }} · {{ $this->exactDuplicate->city }}
                </a>
                <flux:button
                    type="button"
                    variant="primary"
                    icon="plus"
                    wire:click="addExistingToMine"
                    x-on:click="$haptic('medium')"
                    wire:loading.attr="disabled"
                    wire:target="addExistingToMine"
                    class="w-fit cursor-pointer"
                >
                    {{ __('Zu meinen Meetups hinzufügen') }}
                </flux:button>
            </div>
        {{-- Überstimmbarer Hinweis bei ähnlichen (nicht exakten) Treffern (Phase 4.1). --}}
        @elseif ($this->duplicates->isNotEmpty())
            <div class="flex flex-col gap-2 rounded-tile border border-amber-300 bg-amber-50 p-4 dark:border-amber-500/40 dark:bg-amber-500/10">
                <span class="flex items-center gap-2 font-semibold text-amber-800 dark:text-amber-300">
                    <flux:icon name="exclamation-triangle" class="size-5"/>
                    {{ __('Gibt es das schon?') }}
                </span>
                <flux:text class="text-sm">{{ __('Ähnliche Meetups existieren bereits:') }}</flux:text>
                <div class="flex flex-col gap-1">
                    @foreach ($this->duplicates as $duplicate)
                        <a
                            href="{{ route('meetups.show', $duplicate->slug()) }}"
                            wire:navigate
                            wire:key="dup-{{ $duplicate->slug() }}"
                            class="flex items-center gap-2 text-sm font-medium text-amber-900 underline dark:text-amber-200"
                        >
                            <flux:icon name="arrow-up-right" class="size-4"/>
                            {{ $duplicate->name }} · {{ $duplicate->city }}
                        </a>
                    @endforeach
                </div>
                <label class="mt-1 flex items-center gap-2">
                    <flux:checkbox wire:model.live="ignoreDuplicates"/>
                    <flux:text class="text-sm">{{ __('Trotzdem ein neues Meetup anlegen') }}</flux:text>
                </label>
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
                :disabled="(bool) $this->exactDuplicate"
            >
                {{ $editingId ? __('Speichern') : __('Anlegen') }}
            </flux:button>
        </div>
    </form>
</x-sheet>
