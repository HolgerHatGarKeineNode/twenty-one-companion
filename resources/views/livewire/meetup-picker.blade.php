<?php

use App\Data\Portal\MapMeetupData;
use App\Data\Portal\MeetupData;
use App\Livewire\Concerns\HandlesPortalWriteFeedback;
use App\Services\PortalApi;
use App\Services\PortalWriter;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Meetup-Picker („Meetup aussuchen“): der Discovery-First-Einstieg, der
 * verhindert, dass Nutzer ein Duplikat anlegen, obwohl es ihr Meetup in der
 * Stadt schon gibt. Als eigenständige Livewire-Komponente einmal im Layout
 * eingebettet (wie der Meetup-Editor), besitzt sie das Bottom-Sheet
 * `pick-meetup`, geöffnet über das `open-meetup-picker`-Event.
 *
 * Sucht in-memory auf den gecachten Karten-Meetups (wie die globale Suche,
 * kein API-Call pro Tastendruck) und fügt das gewählte Meetup über den
 * {@see PortalWriter::addMeetupToMine()} (per Slug) zu „Meine Meetups“ hinzu.
 * Erst wenn nichts passt, führt ein Fallback in den Anlege-Editor.
 */
new class extends Component {
    use HandlesPortalWriteFeedback;

    private const MIN_TERM = 2;

    private const LIMIT = 8;

    public string $search = '';

    #[On('open-meetup-picker')]
    public function open(): void
    {
        $this->search = '';
        $this->resetErrorBag();
        unset($this->results, $this->mySlugs);
    }

    /**
     * Slugs der bereits zu „Meine“ gehörenden Meetups — markiert Treffer, die
     * der Nutzer schon hat (netzwerkfrei nach dem ersten Laden).
     *
     * @return Collection<int, string>
     */
    #[Computed]
    public function mySlugs(): Collection
    {
        return app(PortalApi::class)
            ->myMeetups()
            ->map(fn (MeetupData $meetup): string => $meetup->slug)
            ->values();
    }

    /**
     * Bestehende Meetups, gefiltert nach Name/Stadt (in-memory auf der
     * gecachten Karten-Liste — gemeinsamer Cache-Key mit dem Meetups-Index).
     *
     * @return Collection<int, MapMeetupData>
     */
    #[Computed]
    public function results(): Collection
    {
        $term = mb_strtolower(trim($this->search));

        if (mb_strlen($term) < self::MIN_TERM) {
            return collect();
        }

        return app(PortalApi::class)
            ->mapMeetups(withIntro: false, withLogos: true)
            ->filter(fn (MapMeetupData $meetup): bool => str_contains(mb_strtolower($meetup->name), $term)
                || str_contains(mb_strtolower($meetup->city), $term))
            ->sortBy(fn (MapMeetupData $meetup): string => mb_strtolower($meetup->name))
            ->take(self::LIMIT)
            ->values();
    }

    /**
     * Ein bestehendes Meetup zu „Meine Meetups“ hinzufügen (per Slug, da die
     * Karten-Liste keine numerische ID exponiert). Idempotent serverseitig.
     */
    public function addToMine(string $slug): void
    {
        $result = app(PortalWriter::class)->addMeetupToMine($slug);

        if ($result->successful()) {
            $this->reportWriteSuccess('pick-meetup', __('Meetup zu „Meine“ hinzugefügt.'));
            $this->reset('search');

            return;
        }

        $this->reportWriteFailure($result, __('Dieses Meetup konnte nicht hinzugefügt werden.'));
    }
};
?>

<x-sheet name="pick-meetup" :heading="__('Meetup aussuchen')">
    <div class="flex flex-col gap-4">
        <flux:text class="text-sm">
            {{ __('Suche zuerst, ob es dein Meetup schon gibt — füge es dann zu „Meine“ hinzu, statt ein Duplikat anzulegen.') }}
        </flux:text>

        <flux:input
            wire:model.live.debounce.300ms="search"
            type="search"
            icon="magnifying-glass"
            :placeholder="__('Meetup oder Stadt suchen …')"
            clearable
        />

        @if (mb_strlen(trim($search)) >= 2)
            @if ($this->results->isEmpty())
                <div class="flex flex-col items-center gap-2 rounded-tile border border-zinc-200 p-6 text-center dark:border-zinc-800">
                    <flux:icon name="magnifying-glass" class="size-8 text-zinc-400"/>
                    <flux:text class="text-sm">{{ __('Kein passendes Meetup gefunden.') }}</flux:text>
                </div>
            @else
                <div class="flex flex-col gap-2">
                    @foreach ($this->results as $meetup)
                        <div class="surface-card flex items-center gap-3 p-3" wire:key="pick-{{ $meetup->slug() }}">
                            <x-meetup-avatar :logo="$meetup->logo" :name="$meetup->name"/>
                            <span class="flex min-w-0 flex-1 flex-col gap-0.5">
                                <span class="truncate font-semibold">{{ $meetup->name }}</span>
                                <flux:text class="truncate text-sm">{{ $meetup->city }} · {{ $meetup->country }}</flux:text>
                            </span>
                            @if ($this->mySlugs->contains($meetup->slug()))
                                <flux:badge color="green" size="sm" icon="check" class="shrink-0">{{ __('Dabei') }}</flux:badge>
                            @else
                                <flux:button
                                    type="button"
                                    size="sm"
                                    variant="primary"
                                    icon="plus"
                                    wire:click="addToMine(@js($meetup->slug()))"
                                    x-on:click="$haptic('medium')"
                                    wire:loading.attr="disabled"
                                    wire:target="addToMine"
                                    class="shrink-0 cursor-pointer"
                                >
                                    {{ __('Hinzufügen') }}
                                </flux:button>
                            @endif
                        </div>
                    @endforeach
                </div>
            @endif
        @endif

        {{-- Fallback: nur, wenn es das eigene Meetup wirklich noch nicht gibt
             (Discovery-First) — führt in den Anlege-Editor mit Duplikat-Schutz. --}}
        <div class="flex flex-col gap-2 rounded-tile border border-dashed border-zinc-300 p-4 dark:border-zinc-700">
            <flux:text class="text-sm">{{ __('Dein Meetup gibt es noch nicht?') }}</flux:text>
            <flux:button
                type="button"
                size="sm"
                variant="ghost"
                icon="plus"
                x-on:click="$haptic('medium'); $flux.modal('pick-meetup').close(); $flux.modal('create-meetup').show(); Livewire.dispatch('open-meetup-editor')"
                class="w-fit cursor-pointer"
            >
                {{ __('Neues Meetup anlegen') }}
            </flux:button>
        </div>
    </div>
</x-sheet>
