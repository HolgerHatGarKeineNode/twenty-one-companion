<?php

use App\Data\Portal\CityData;
use App\Data\Portal\MyVenueData;
use App\Livewire\Concerns\HandlesPortalWriteFeedback;
use App\Livewire\Forms\VenueForm;
use App\Services\PortalApi;
use App\Services\PortalWriter;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Venue-Editor (Phase 6.1): das Create-/Edit-Formular für Veranstaltungsorte.
 * Wie der Meetup-/Termin-Editor einmal im Layout eingebettet, besitzt es das
 * Bottom-Sheet `create-venue`. Geöffnet über das `open-venue-editor`-Event
 * (ohne ID = Anlegen, mit ID = einen eigenen Ort bearbeiten).
 *
 * Felder: Name, Straße, Stadt (per Namen gesucht → city_id). Die Portal-API
 * für Venues kennt keine Geo-Koordinaten — die hängen an der Stadt (City-
 * Editor). Schreibt über den {@see PortalWriter}; 422-Feldfehler werden an die
 * Form-Felder gemappt.
 */
new class extends Component {
    use HandlesPortalWriteFeedback;

    public VenueForm $form;

    /** Null = Anlegen, sonst die ID des bearbeiteten eigenen Orts. */
    public ?int $editingId = null;

    /** Suchbegriff für die Stadt-Auswahl (eigenes Feld, nicht Teil der Payload). */
    public string $cityQuery = '';

    #[On('open-venue-editor')]
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
        $this->cityQuery = '';
        $this->resetErrorBag();
    }

    /**
     * Eigenen Ort zum Bearbeiten laden. Den Stadtnamen lösen wir netzwerkfrei
     * aus der gecachten Städte-Liste (dieselbe withDetails-Variante wie die
     * Karten-Seite) über die city_id auf — die VenueResource liefert nur die id.
     */
    private function loadForEdit(int $id): void
    {
        $venue = app(PortalApi::class)
            ->myVenues()
            ->first(fn (MyVenueData $candidate): bool => $candidate->id === $id);

        if ($venue === null) {
            Flux::toast(text: __('Dieser Ort konnte nicht geladen werden.'), variant: 'danger');

            return;
        }

        $cityName = app(PortalApi::class)
            ->cities(withDetails: true)
            ->first(fn (CityData $city): bool => $city->id === $venue->city_id)
            ?->name ?? '';

        $this->editingId = $venue->id;
        $this->form->setVenue($venue, $cityName);
    }

    /**
     * Städte-Treffer für die Auswahl (ab 2 Zeichen, debounced).
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
     * Eine im City-Editor frisch angelegte Stadt direkt übernehmen (Phase 6.2:
     * inline aus dem Venue-Flow). Greift nur, wenn noch keine Stadt gewählt ist,
     * damit eine im Meetup-Flow angelegte Stadt nicht hier hineinspringt.
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

        $writer = app(PortalWriter::class);

        $result = $this->editingId === null
            ? $writer->createVenue($payload)
            : $writer->updateVenue($this->editingId, $payload);

        if ($result->successful()) {
            $this->handleSuccess($result->data);

            return;
        }

        $this->reportWriteFailure($result, __('Du darfst diesen Ort nicht bearbeiten.'));
    }

    /**
     * @param  array<int|string, mixed>  $data
     */
    private function handleSuccess(array $data): void
    {
        $created = $this->editingId === null;

        Flux::modal('create-venue')->close();
        Flux::toast(
            text: $created ? __('Ort angelegt.') : __('Ort aktualisiert.'),
            variant: 'success',
        );

        // Inline aus dem Kurs-Event-Flow: die neue id+name zurückmelden, damit
        // ein offener Kurs-Event-Editor den frisch angelegten Ort übernimmt
        // (analog zu city-saved beim Stadt-Editor).
        if ($created) {
            $newId = $data['data']['id'] ?? null;
            if (is_int($newId)) {
                $this->dispatch('venue-saved', id: $newId, name: $this->form->name);
            }
        }

        $this->dispatch('places-changed');
        $this->js("window.haptic && window.haptic('success')");
        $this->resetEditor();
    }
};
?>

<x-sheet name="create-venue" :heading="$editingId ? __('Ort bearbeiten') : __('Ort anlegen')">
    <form wire:submit="save" class="flex flex-col gap-5">
        <flux:input
            wire:model="form.name"
            :label="__('Name')"
            :placeholder="__('z. B. Bitcoin-Bar')"
            required
        />

        <flux:input
            wire:model="form.street"
            :label="__('Straße')"
            :placeholder="__('z. B. Musterstraße 21')"
            required
        />

        {{-- Stadt: gewählte Stadt als Chip, sonst Suche. --}}
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
                                wire:key="venue-city-{{ $city->id }}"
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
