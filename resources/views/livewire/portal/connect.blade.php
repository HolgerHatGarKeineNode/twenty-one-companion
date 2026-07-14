<?php

use App\Services\PortalAuth;
use App\Services\PortalWriter;
use App\Services\WriteStatus;
use Flux\Flux;
use Livewire\Component;
use Native\Mobile\Facades\Browser;

new class extends Component {
    public bool $connected = false;

    /** @var array<string, mixed>|null */
    public ?array $profile = null;

    /** Inline-Bearbeitung des Anzeigenamens (Profil-Erweiterung, B.8). */
    public bool $editingName = false;

    public string $name = '';

    public function mount(PortalAuth $portalAuth): void
    {
        $this->refreshState($portalAuth);
    }

    public function refresh(PortalAuth $portalAuth): void
    {
        $this->refreshState($portalAuth);
    }

    public function startEditingName(): void
    {
        $this->name = (string) ($this->profile['name'] ?? '');
        $this->editingName = true;
    }

    public function cancelEditingName(): void
    {
        $this->editingName = false;
        $this->resetErrorBag('name');
    }

    /**
     * Speichert den geänderten Anzeigenamen über die Portal-API. Bei Erfolg
     * übernimmt der PortalWriter das frische Profil in den Cache; hier wird
     * nur die lokale Anzeige aktualisiert und das Inline-Feld geschlossen.
     */
    public function saveName(PortalWriter $portalWriter): void
    {
        $this->name = trim($this->name);
        $this->validate(['name' => 'required|string|max:255']);

        $result = $portalWriter->updateUserProfile(['name' => $this->name]);

        if ($result->successful()) {
            $this->profile = $result->data;
            $this->editingName = false;
            Flux::toast(text: __('Gespeichert.'), variant: 'success');

            return;
        }

        if ($result->status === WriteStatus::ValidationError) {
            $this->addError('name', $result->errorFor('name') ?? __('Bitte überprüfe deine Eingabe.'));

            return;
        }

        Flux::toast(text: $result->message ?? __('Speichern fehlgeschlagen.'), variant: 'danger');
    }

    /**
     * Öffnet das eigene Nostr-Profil auf njump.me im System-Browser.
     */
    public function openNostr(): void
    {
        $nostr = $this->profile['nostr'] ?? null;

        if (is_string($nostr) && $nostr !== '') {
            Browser::open('https://njump.me/'.$nostr);
        }
    }

    public function disconnect(PortalAuth $portalAuth): void
    {
        $portalAuth->logout();
        $this->connected = false;
        $this->profile = null;
    }

    protected function refreshState(PortalAuth $portalAuth): void
    {
        $this->connected = $portalAuth->hasToken();
        $this->profile = $this->connected ? $portalAuth->profile() : null;
    }
};
?>

<section class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
    @if ($connected)
        <div class="flex items-center gap-4">
            @if ($profile && ($profile['avatar'] ?? null))
                <flux:avatar src="{{ $profile['avatar'] }}" size="lg"/>
            @else
                <flux:avatar size="lg" name="{{ $profile['name'] ?? app(\App\Services\BrandResolver::class)->current()->label() }}"/>
            @endif
            <div class="min-w-0 flex-1">
                @if ($editingName)
                    <div class="flex flex-col gap-2">
                        <flux:input wire:model="name" wire:keydown.enter="saveName" :label="__('Anzeigename')" autofocus/>
                        <div class="flex gap-2">
                            <flux:button wire:click="saveName" size="sm" variant="primary" class="cursor-pointer">
                                {{ __('Speichern') }}
                            </flux:button>
                            <flux:button wire:click="cancelEditingName" size="sm" variant="ghost" class="cursor-pointer">
                                {{ __('Abbrechen') }}
                            </flux:button>
                        </div>
                    </div>
                @else
                    <div class="flex items-center gap-2">
                        <flux:heading size="lg" class="truncate">{{ $profile['name'] ?? __('Verbunden') }}</flux:heading>
                        @if ($profile)
                            <flux:button wire:click="startEditingName" size="xs" variant="ghost" icon="pencil-square" class="cursor-pointer" :aria-label="__('Anzeigename ändern')"/>
                        @endif
                    </div>
                    <flux:text class="truncate">
                        {{ $profile ? __('Mit dem Portal verbunden') : __('Verbunden — Profil derzeit nicht erreichbar') }}
                    </flux:text>
                @endif
            </div>
        </div>

        @if ($profile && (($profile['is_lecturer'] ?? false) || ($profile['is_leader'] ?? false)))
            <div class="mt-3 flex flex-wrap gap-1.5">
                @if ($profile['is_lecturer'] ?? false)
                    <flux:badge size="sm" color="amber" icon="academic-cap">{{ __('Referent') }}</flux:badge>
                @endif
                @if ($profile['is_leader'] ?? false)
                    <flux:badge size="sm" color="green" icon="user-group">{{ __('Leiter') }}</flux:badge>
                @endif
            </div>
        @endif

        @if ($profile && ($profile['nostr'] ?? null))
            <div class="mt-3 flex items-center gap-2 rounded-xl border border-zinc-200 p-2.5 dark:border-zinc-800">
                <flux:icon.key class="size-4 shrink-0 text-zinc-400"/>
                {{-- npub antippen kopiert ihn; das Icon rechts öffnet das Profil auf njump.me. --}}
                <x-copy-button :value="$profile['nostr']" :label="__('npub kopieren')" class="min-w-0 flex-1"/>
                <button type="button" wire:click="openNostr" aria-label="{{ __('Auf njump.me öffnen') }}" class="pressable shrink-0 text-zinc-400 active:text-zinc-600 dark:active:text-zinc-200">
                    <flux:icon.arrow-top-right-on-square class="size-4"/>
                </button>
            </div>
        @endif

        <flux:button :href="route('mine')" wire:navigate size="sm" icon="square-2-stack" class="mt-3 w-full cursor-pointer">
            {{ __('Meine Inhalte') }}
        </flux:button>

        <div class="mt-4 flex gap-2">
            <flux:button wire:click="refresh" size="sm" icon="arrow-path" class="cursor-pointer">
                {{ __('Aktualisieren') }}
            </flux:button>
            <flux:button wire:click="disconnect" size="sm" variant="ghost" icon="arrow-right-start-on-rectangle" class="cursor-pointer">
                {{ __('Trennen') }}
            </flux:button>
        </div>
    @else
        {{-- Single-Login: der Portal-Token kommt aus DEMSELBEN Nostr-Login wie der
             Chat (welshman-Signer → portal-handoff, siehe PortalNostrHandoffController).
             Kein separater Portal-Web-Login mehr im In-App-Browser — ein Konto, ein
             Login. Voller Seitenwechsel (kein wire:navigate): group.* lädt das
             eigene Chat-/Login-Layout; nach dem Signieren holt das Boot-Gate den
             Token automatisch und diese Karte zeigt beim Zurückkehren „verbunden". --}}
        <flux:heading size="lg">{{ __('Dein Portal-Konto') }}</flux:heading>
        <flux:text class="mt-2">
            {{ __('Melde dich mit Nostr an, um deine Meetups und Kurse zu sehen — ein Login für Chat, Wallet und Portal.') }}
        </flux:text>
        <flux:button :href="route('group.nostr-login')" variant="primary" icon="key" class="mt-4 w-full cursor-pointer">
            {{ __('Mit Nostr anmelden') }}
        </flux:button>
    @endif
</section>
