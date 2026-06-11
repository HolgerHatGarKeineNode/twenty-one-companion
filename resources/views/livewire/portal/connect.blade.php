<?php

use App\Services\PortalAuth;
use Livewire\Component;
use Native\Mobile\Facades\Browser;

new class extends Component {
    public bool $connected = false;

    /** @var array{id: int, name: string, email: string|null, nostr: string|null, is_lecturer: bool, is_leader: bool, avatar: string|null}|null */
    public ?array $profile = null;

    public function mount(PortalAuth $portalAuth): void
    {
        $this->refreshState($portalAuth);
    }

    /**
     * Open the headless Nostr launcher in the in-app browser, which fires
     * the NIP-55 signer (e.g. Amber). Signing is local (no relay), and the
     * token comes back via the einundzwanzig:// App Link.
     */
    public function loginWithNostr(PortalAuth $portalAuth): void
    {
        Browser::inApp($portalAuth->nostrLoginUrl());
    }

    /**
     * Open the Lightning login page (QR + LNURL-auth) in the in-app browser.
     */
    public function loginWithLightning(PortalAuth $portalAuth): void
    {
        Browser::inApp($portalAuth->loginUrl());
    }

    public function refresh(PortalAuth $portalAuth): void
    {
        $this->refreshState($portalAuth);
    }

    public function disconnect(PortalAuth $portalAuth): void
    {
        $portalAuth->forgetToken();
        $this->connected = false;
        $this->profile = null;
    }

    protected function refreshState(PortalAuth $portalAuth): void
    {
        $this->connected = $portalAuth->hasToken();
        $this->profile = $this->connected ? $portalAuth->profile() : null;
        $this->connected = $portalAuth->hasToken();
    }
};
?>

<section class="rounded-2xl border border-zinc-200 bg-white p-6 dark:border-zinc-800 dark:bg-zinc-900">
    @if ($connected)
        <div class="flex items-center gap-4">
            @if ($profile && ($profile['avatar'] ?? null))
                <flux:avatar src="{{ $profile['avatar'] }}" size="lg"/>
            @else
                <flux:avatar size="lg" name="{{ $profile['name'] ?? 'Einundzwanzig' }}"/>
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
    @else
        <flux:heading size="lg">{{ __('Dein Portal-Konto') }}</flux:heading>
        <flux:text class="mt-2">
            {{ __('Verbinde die App mit deinem Einundzwanzig-Portal-Konto, um deine Meetups und Kurse zu sehen.') }}
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
