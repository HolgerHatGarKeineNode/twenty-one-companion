<?php

use App\Data\Portal\MeetupLeaderData;
use App\Services\PortalApi;
use App\Services\PortalWriter;
use App\Services\WriteResult;
use App\Services\WriteStatus;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Leader-Verwaltung (Leader-Delegation): das Bottom-Sheet hinter dem
 * „Leader verwalten“-Button im Meetup-Editor. Als eigenständige Komponente
 * einmal im Layout eingebettet (wie der Meetup-Editor) — geöffnet über das
 * `open-meetup-leaders`-Event (mit Meetup-ID + Name); das Sheet selbst
 * öffnet/schließt clientseitig über die Flux-Modal-API.
 *
 * Ein Leader kann weitere Leader per Nostr-npub einsetzen und (außer dem
 * Ersteller) wieder entziehen. Schreibt über den {@see PortalWriter}; die
 * Liste lädt live über die {@see PortalApi} (reiner Online-Vorgang).
 */
new class extends Component
{
    /** Meetup, dessen Leader verwaltet werden (null = Sheet ungeöffnet). */
    public ?int $meetupId = null;

    public string $meetupName = '';

    /** npub-Eingabe für den neu einzusetzenden Leader. */
    public string $npub = '';

    #[On('open-meetup-leaders')]
    public function open(int $id, string $name): void
    {
        $this->meetupId = $id;
        $this->meetupName = $name;
        $this->npub = '';
        $this->resetErrorBag();
        unset($this->leaders);
    }

    /**
     * Aktuelle Leader des Meetups (live, ungecacht). Ohne geöffnetes Sheet
     * leer.
     *
     * @return Collection<int, MeetupLeaderData>
     */
    #[Computed]
    public function leaders(): Collection
    {
        if ($this->meetupId === null) {
            return new Collection;
        }

        return app(PortalApi::class)->meetupLeaders($this->meetupId);
    }

    /**
     * Einen weiteren Leader per npub einsetzen. Clientseitige Kurzprüfung
     * (npub1-Präfix), die volle Validierung macht das Portal (422 → Feldfehler).
     */
    public function addLeader(): void
    {
        if ($this->meetupId === null) {
            return;
        }

        $npub = trim($this->npub);

        if (! str_starts_with($npub, 'npub1') || mb_strlen($npub) < 60) {
            $this->addError('npub', __('Bitte gib einen gültigen npub ein (beginnt mit npub1…).'));

            return;
        }

        $result = app(PortalWriter::class)->addMeetupLeader($this->meetupId, $npub);

        if ($result->successful()) {
            $this->npub = '';
            $this->resetErrorBag();
            $this->reportLeaderSuccess(__('Leader eingesetzt.'));

            return;
        }

        if ($result->status === WriteStatus::ValidationError) {
            $this->addError('npub', $result->errors['npub'][0] ?? __('Das ist kein gültiger npub.'));
            $this->js("window.haptic && window.haptic('error')");

            return;
        }

        $this->reportLeaderFailure($result, __('Nur Leader dürfen weitere Leader einsetzen.'));
    }

    /**
     * Einem Leader die Rolle entziehen (Demote). Der Ersteller ist
     * serverseitig geschützt und hat in der UI keinen Entfernen-Button.
     */
    public function removeLeader(int $userId): void
    {
        if ($this->meetupId === null) {
            return;
        }

        $result = app(PortalWriter::class)->removeMeetupLeader($this->meetupId, $userId);

        if ($result->successful()) {
            $this->reportLeaderSuccess(__('Leader entzogen.'));

            return;
        }

        $this->reportLeaderFailure($result, __('Diesen Leader kannst du nicht entziehen.'));
    }

    /**
     * Erfolgs-Feedback eines Leader-Writes: frische Liste, Erfolgs-Toast, Haptik.
     */
    private function reportLeaderSuccess(string $text): void
    {
        unset($this->leaders);
        Flux::toast(text: $text, variant: 'success');
        $this->js("window.haptic && window.haptic('success')");
    }

    /**
     * Fehler-Feedback eines Leader-Writes: passender Toast + Fehler-Haptik. Der
     * Forbidden-Text ist je Operation verschieden (einsetzen vs. entziehen).
     */
    private function reportLeaderFailure(WriteResult $result, string $forbidden): void
    {
        $message = match ($result->status) {
            WriteStatus::Forbidden => $forbidden,
            WriteStatus::Unauthorized => __('Bitte verbinde zuerst dein Portal-Konto.'),
            default => __('Senden fehlgeschlagen. Bitte prüfe deine Verbindung und versuche es erneut.'),
        };
        Flux::toast(text: $message, variant: 'danger');
        $this->js("window.haptic && window.haptic('error')");
    }
};
?>

<x-sheet name="meetup-leaders" :heading="__('Leader verwalten')">
    <div class="flex flex-col gap-5">
        @if ($meetupName !== '')
            <flux:text class="text-sm">
                {{ __('Leader für :meetup', ['meetup' => $meetupName]) }}
            </flux:text>
        @endif

        {{-- Anleitung: was ein Leader darf und woher der npub kommt. --}}
        <div class="flex flex-col gap-2 rounded-tile border border-brand-200 bg-brand-50 p-4 dark:border-brand-500/30 dark:bg-brand-500/10">
            <span class="flex items-center gap-2 font-semibold text-brand-800 dark:text-brand-300">
                <flux:icon name="information-circle" class="size-5"/>
                {{ __('Was ist ein Leader?') }}
            </span>
            <flux:text class="text-sm">
                {{ __('Leader dürfen dieses Meetup bearbeiten und selbst weitere Leader einsetzen. Setze nur Personen ein, denen du vertraust.') }}
            </flux:text>
            <flux:text class="text-sm">
                {{ __('Frag die Person nach ihrem Nostr-Schlüssel (npub). Sie findet ihn in ihrer Nostr-App oder in ihrem Portal-Profil — er beginnt mit „npub1…“.') }}
            </flux:text>
        </div>

        {{-- npub-Eingabe + einsetzen. --}}
        <div class="flex flex-col gap-2">
            <flux:input
                wire:model="npub"
                :label="__('npub der Person')"
                placeholder="npub1…"
                autocapitalize="none"
                autocorrect="off"
                spellcheck="false"
            />
            @error('npub')
                <flux:text class="text-sm text-red-600 dark:text-red-400">{{ $message }}</flux:text>
            @enderror
            <flux:button
                wire:click="addLeader"
                type="button"
                variant="primary"
                icon="user-plus"
                x-on:click="$haptic('medium')"
                wire:loading.attr="disabled"
                wire:target="addLeader"
                class="w-full cursor-pointer"
            >
                {{ __('Als Leader einsetzen') }}
            </flux:button>
        </div>

        {{-- Aktuelle Leader. --}}
        <div class="flex flex-col gap-2">
            <flux:label>{{ __('Aktuelle Leader') }}</flux:label>

            @forelse ($this->leaders as $leader)
                <div class="surface-card flex items-center gap-3 p-3" wire:key="leader-{{ $leader->id }}">
                    <flux:avatar
                        size="sm"
                        :src="$leader->avatar"
                        :name="$leader->name"
                        circle
                    />
                    <span class="flex min-w-0 flex-1 flex-col">
                        <span class="truncate font-semibold">{{ $leader->name }}</span>
                        {{-- npub antippen, um ihn in die Zwischenablage zu kopieren
                             (nur wenn vorhanden). navigator.clipboard wie im 2FA-Setup. --}}
                        @if ($leader->nostr)
                            @php($npubShort = \Illuminate\Support\Str::substr($leader->nostr, 0, 12).'…'.\Illuminate\Support\Str::substr($leader->nostr, -6))
                            <button
                                type="button"
                                x-data="{ copied: false }"
                                x-on:click.stop="navigator.clipboard.writeText(@js($leader->nostr)).then(() => { copied = true; $haptic('light'); setTimeout(() => copied = false, 1500); })"
                                :class="copied && 'text-green-600 dark:text-green-400'"
                                aria-label="{{ __('npub kopieren') }}"
                                class="pressable flex w-fit max-w-full items-center gap-1 text-xs text-zinc-500 dark:text-zinc-400"
                            >
                                <flux:icon name="clipboard-document" class="size-3.5 shrink-0"/>
                                <span class="truncate font-mono" x-text="copied ? '{{ __('Kopiert!') }}' : '{{ $npubShort }}'">{{ $npubShort }}</span>
                            </button>
                        @endif
                    </span>

                    @if ($leader->is_creator)
                        <flux:badge color="amber" size="sm" icon="star" class="shrink-0">{{ __('Ersteller') }}</flux:badge>
                    @else
                        <flux:button
                            type="button"
                            variant="ghost"
                            size="sm"
                            icon="user-minus"
                            :aria-label="__('Leader entziehen')"
                            wire:click="removeLeader({{ $leader->id }})"
                            wire:confirm="{{ __(':name die Leader-Rolle entziehen? Die Person bleibt Mitglied, kann das Meetup aber nicht mehr bearbeiten.', ['name' => $leader->name]) }}"
                            x-on:click="$haptic('medium')"
                            wire:loading.attr="disabled"
                            wire:target="removeLeader({{ $leader->id }})"
                            class="shrink-0 cursor-pointer"
                        />
                    @endif
                </div>
            @empty
                <flux:text class="py-2 text-sm">
                    {{ __('Noch keine Leader geladen — bist du online?') }}
                </flux:text>
            @endforelse
        </div>

        <div class="flex pt-1">
            <flux:spacer/>
            <flux:modal.close>
                <flux:button type="button" variant="ghost" class="cursor-pointer">{{ __('Fertig') }}</flux:button>
            </flux:modal.close>
        </div>
    </div>
</x-sheet>
