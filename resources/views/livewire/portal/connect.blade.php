<?php

use App\Services\PortalAuth;
use Livewire\Component;
use Native\Mobile\Facades\Browser;

new class extends Component {
    public bool $connected = false;

    /** @var array<string, mixed>|null */
    public ?array $profile = null;

    /**
     * Gesetzt, sobald der Login-Browser geöffnet wurde: zeigt einen
     * Warte-Indikator mit Poll, damit der Nutzer beim Zurückkehren aus dem
     * Signer/der Lightning-Seite sieht, dass die App auf das Token wartet
     * und es zieht (Phase 3.3) — statt einer scheinbar untätigen Seite.
     */
    public bool $awaitingConnection = false;

    public function mount(PortalAuth $portalAuth): void
    {
        $this->refreshState($portalAuth);
    }

    /**
     * Open the headless Nostr launcher in an in-app Custom Tab. This keeps
     * the app's task (and process) alive while the user signs, so the
     * signer's einundzwanzig://signed callback opens the *running* app and
     * the deep link loads immediately. Opening the launcher in the full
     * system browser (Browser::open) backgrounds and kills the app, forcing
     * a cold start whose pending-deep-link handling is racy.
     */
    public function loginWithNostr(PortalAuth $portalAuth): void
    {
        $this->awaitingConnection = true;
        Browser::inApp($portalAuth->nostrLoginUrl());
    }

    /**
     * Open the Lightning login page (QR + LNURL-auth) in the in-app browser.
     */
    public function loginWithLightning(PortalAuth $portalAuth): void
    {
        $this->awaitingConnection = true;
        Browser::inApp($portalAuth->loginUrl());
    }

    public function refresh(PortalAuth $portalAuth): void
    {
        $this->refreshState($portalAuth);
    }

    public function cancelAwaiting(): void
    {
        $this->awaitingConnection = false;
    }

    public function disconnect(PortalAuth $portalAuth): void
    {
        $portalAuth->logout();
        $this->connected = false;
        $this->profile = null;
        $this->awaitingConnection = false;
    }

    protected function refreshState(PortalAuth $portalAuth): void
    {
        $this->connected = $portalAuth->hasToken();
        $this->profile = $this->connected ? $portalAuth->profile() : null;

        // Sobald das Token da ist, ist das Warten vorbei.
        if ($this->connected) {
            $this->awaitingConnection = false;
        }
    }
};
?>

<section class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
    @if ($connected)
        <div class="flex items-center gap-4">
            @if ($profile && ($profile['avatar'] ?? null))
                <flux:avatar src="{{ $profile['avatar'] }}" size="lg"/>
            @else
                <flux:avatar size="lg" name="{{ $profile['name'] ?? 'EINUNDZWANZIG' }}"/>
            @endif
            <div class="min-w-0">
                <flux:heading size="lg">{{ $profile['name'] ?? __('Verbunden') }}</flux:heading>
                <flux:text class="truncate">
                    {{ $profile ? __('Mit dem Portal verbunden') : __('Verbunden — Profil derzeit nicht erreichbar') }}
                </flux:text>
            </div>
        </div>
        <div class="mt-4 flex gap-2">
            <flux:button wire:click="refresh" size="sm" icon="arrow-path" class="cursor-pointer">
                {{ __('Aktualisieren') }}
            </flux:button>
            <flux:button wire:click="disconnect" size="sm" variant="ghost" icon="arrow-right-start-on-rectangle" class="cursor-pointer">
                {{ __('Trennen') }}
            </flux:button>
        </div>
    @elseif ($awaitingConnection)
        {{-- Warte auf den Deep-Link-Rücksprung aus Signer/Lightning-Seite und
             pollt das Keystore-Token. Sobald es da ist, wechselt die Karte
             in den Verbunden-Zustand (Phase 3.3). --}}
        <div wire:poll.2s="refresh" class="flex flex-col items-center gap-4 py-2 text-center">
            <flux:icon.loading class="size-8 text-brand-600 dark:text-brand-400"/>
            <div>
                <flux:heading size="lg">{{ __('Verbinde mit dem Portal …') }}</flux:heading>
                <flux:text class="mt-1 max-w-xs">
                    {{ __('Schließe die Anmeldung im Signer oder im Browser ab. Sobald du zurück bist, holt die App dein Token automatisch.') }}
                </flux:text>
            </div>
            <flux:button wire:click="cancelAwaiting" size="sm" variant="ghost" class="cursor-pointer">
                {{ __('Abbrechen') }}
            </flux:button>
        </div>
    @else
        <flux:heading size="lg">{{ __('Dein Portal-Konto') }}</flux:heading>
        <flux:text class="mt-2">
            {{ __('Verbinde die App mit deinem EINUNDZWANZIG-Portal-Konto, um deine Meetups und Kurse zu sehen.') }}
        </flux:text>
        <div class="mt-4 flex flex-col gap-2">
            <flux:button wire:click="loginWithNostr" variant="primary" icon="key" class="w-full cursor-pointer">
                {{ __('Mit Nostr anmelden') }}
            </flux:button>
            <flux:button wire:click="loginWithLightning" icon="bolt" class="w-full cursor-pointer">
                {{ __('Mit Lightning anmelden') }}
            </flux:button>
        </div>
    @endif
</section>
