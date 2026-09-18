<?php

use App\Data\Portal\MeetupData;
use App\Livewire\Concerns\HandlesNativeConfirm;
use App\Livewire\Concerns\HandlesPortalWriteFeedback;
use App\Livewire\PortalPage;
use App\Services\PortalApi;
use App\Services\PortalWriter;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\On;

/**
 * „Meine Meetups" (`/ich/inhalte/meetups`, P5) — managing one's own meetups.
 *
 * ── Why the page moved ───────────────────────────────────────────────────────
 *
 * This was the „Meine" tab on this app's `/meetups`. The meetup LIST has lived in
 * the package since P4 (D9) and is read-only there — that is the decision, and it
 * is the right one: any host can read a list, while creating and changing needs a
 * Portal token that only this app has. The write part therefore has no place left
 * on a page that no longer exists here, and moves under „Ich › Meine Inhalte" —
 * next to one's own places and courses, which already live there.
 *
 * It stays reachable through exactly that hub (`pages/mine/index`) and through the
 * 302 row `/meetups?tab=meine` → here (`routes/web.php`).
 *
 * ── What is NOT here any more ────────────────────────────────────────────────
 *
 * The all-list, the search and the country filter. Those live in the package
 * (`/bereich/meetups`), and a second stock of the same data would be the
 * duplication D9 has just removed. The way there stands below as a row: whoever
 * searches for their meetup in order to add it to „Meine" uses the picker.
 */
new #[Layout('layouts::mobile', ['title' => 'Meine Meetups', 'heading' => 'Meine Meetups', 'back' => '/ich/inhalte'])] class extends PortalPage
{
    use HandlesNativeConfirm;
    use HandlesPortalWriteFeedback;

    /**
     * Die im Portal-Dashboard ausgewählten Meetups des Nutzers.
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

    /**
     * Reload one's own list after a create/edit in the meetup editor. The PortalWriter has
     * already invalidated the cache; dropping the memoised computed forces the fresh
     * refetch.
     */
    #[On('meetup-saved')]
    public function refreshMyMeetups(): void
    {
        unset($this->myMeetups);
    }

    /**
     * Native Rückfrage vor dem Entfernen (statt window.confirm). Bestätigt der
     * Nutzer, entfernt {@see onConfirmed()} das Meetup über {@see removeFromMine()}.
     */
    public function confirmRemoveFromMine(string $slug, string $name): void
    {
        $this->confirmAction(
            'remove-from-mine',
            __('Aus „Meine“ entfernen'),
            __(':name aus „Meine Meetups“ entfernen? Die Stammdaten bleiben erhalten.', ['name' => $name]),
            __('Entfernen'),
            ['slug' => $slug],
        );
    }

    protected function onConfirmed(string $key, array $payload): void
    {
        if ($key === 'remove-from-mine') {
            $this->removeFromMine((string) $payload['slug']);
        }
    }

    /**
     * Ein Meetup wieder aus „Meine Meetups" entfernen (löst serverseitig die
     * meetup_user-Pivot, per Slug — Gegenstück zum Aussuchen im Picker). Die
     * Stammdaten bleiben erhalten.
     */
    public function removeFromMine(string $slug): void
    {
        $result = app(PortalWriter::class)->removeMeetupFromMine($slug);

        if ($result->successful()) {
            unset($this->myMeetups);
            Flux::toast(text: __('Meetup aus „Meine“ entfernt.'), variant: 'success');
            $this->js("window.haptic && window.haptic('success')");

            return;
        }

        $this->reportWriteFailure($result, __('Dieses Meetup konnte nicht entfernt werden.'));
    }
};
?>

<x-portal-page>
    <x-requires-portal :heading="__('Mit Portal verbinden')" :text="__('Verbinde dein Konto, um deine eigenen Meetups zu verwalten.')">
        @if ($this->myMeetups->isEmpty())
            <x-portal-empty-state icon="user-group" :heading="__('Noch keine eigenen Meetups')" :error-heading="__('Meetups nicht verfügbar')">
                <flux:text class="max-w-xs">
                    {{ __('Such zuerst dein Meetup — gibt es das in deiner Stadt schon, füge es zu „Meine“ hinzu, statt ein Duplikat anzulegen.') }}
                </flux:text>
                {{-- Discovery-First: bestehendes Meetup aussuchen, statt vorschnell ein
                     Duplikat anzulegen. Anlegen ist der zweite Weg. --}}
                <flux:button
                    type="button"
                    variant="primary"
                    icon="magnifying-glass"
                    x-on:click="$haptic('medium'); $flux.modal('pick-meetup').show(); Livewire.dispatch('open-meetup-picker')"
                    class="cursor-pointer"
                >
                    {{ __('Meetup aussuchen') }}
                </flux:button>
                <flux:button
                    type="button"
                    variant="ghost"
                    size="sm"
                    icon="plus"
                    x-on:click="$haptic('medium'); $flux.modal('create-meetup').show(); Livewire.dispatch('open-meetup-editor')"
                    class="cursor-pointer"
                >
                    {{ __('Neues Meetup anlegen') }}
                </flux:button>
            </x-portal-empty-state>
        @else
            <div class="flex justify-end gap-2">
                <flux:button
                    type="button"
                    size="sm"
                    variant="ghost"
                    icon="magnifying-glass"
                    x-on:click="$haptic('medium'); $flux.modal('pick-meetup').show(); Livewire.dispatch('open-meetup-picker')"
                    class="cursor-pointer"
                >
                    {{ __('Meetup aussuchen') }}
                </flux:button>
                <flux:button
                    type="button"
                    size="sm"
                    variant="primary"
                    icon="plus"
                    x-on:click="$haptic('medium'); $flux.modal('create-meetup').show(); Livewire.dispatch('open-meetup-editor')"
                    class="cursor-pointer"
                >
                    {{ __('Neues Meetup anlegen') }}
                </flux:button>
            </div>

            <div class="list-stagger flex flex-col gap-3">
                @foreach ($this->myMeetups as $meetup)
                    {{-- The card links into the detail page — the PACKAGE one
                         (`/bereich/meetups/{slug}`, D9): this app's detail view is deleted
                         with P5, and a link onto a 302 row would be a detour nobody needs.
                         The edit button opens the editor, the bin only drops the
                         assignment. --}}
                    <div
                        class="surface-card flex items-center gap-3 p-4"
                        wire:key="my-meetup-{{ $meetup->slug }}"
                        style="--i: {{ $loop->index }}"
                    >
                        <a
                            href="{{ route('group.bereich.meetups.show', $meetup->slug) }}"
                            wire:navigate
                            x-on:click="$haptic('light')"
                            class="pressable group flex min-w-0 flex-1 items-center gap-3"
                        >
                            <x-meetup-avatar :logo="$meetup->logo" :name="$meetup->name"/>
                            <span class="flex min-w-0 flex-col gap-1">
                                <x-meetup-name :name="$meetup->name"/>
                                @if ($meetup->is_active)
                                    <flux:badge color="green" size="sm" class="w-fit">{{ __('Aktiv') }}</flux:badge>
                                @else
                                    <flux:badge color="zinc" size="sm" class="w-fit">{{ __('Inaktiv') }}</flux:badge>
                                @endif
                            </span>
                        </a>
                        {{-- Editing only for leaders of this meetup (leader model).
                             Non-leader members see the card and „remove", but no edit
                             button. --}}
                        @if ($meetup->is_leader)
                            <flux:button
                                type="button"
                                variant="ghost"
                                icon="pencil-square"
                                :aria-label="__('Meetup bearbeiten')"
                                x-on:click="$haptic('light'); $flux.modal('create-meetup').show(); Livewire.dispatch('open-meetup-editor', { id: {{ $meetup->id }} })"
                                class="shrink-0 cursor-pointer"
                            />
                        @endif
                        <flux:button
                            type="button"
                            variant="ghost"
                            icon="trash"
                            :aria-label="__('Aus „Meine“ entfernen')"
                            wire:click="confirmRemoveFromMine(@js($meetup->slug), @js($meetup->name))"
                            x-on:click="$haptic('medium')"
                            wire:loading.attr="disabled"
                            wire:target="removeFromMine"
                            class="shrink-0 cursor-pointer"
                        />
                    </div>
                @endforeach
            </div>
        @endif
    </x-requires-portal>
</x-portal-page>
