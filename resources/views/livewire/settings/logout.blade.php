<?php

use App\Services\PortalAuth;
use Livewire\Component;

/**
 * The ONE sign-out of this app, injected into the package settings hub instead of the
 * package's own `session` partial (P2, `config('group.settings')`).
 *
 * **Why it replaces the package partial rather than standing next to it.** The package
 * partial ends the NOSTR session only. In this app there is a second one: the Portal token,
 * which lives in the native key store and has to be revoked server-side. Two sign-out
 * buttons would leave whoever presses the wrong one half signed in — with a Portal token
 * still valid on a device that looks signed out.
 *
 * The key itself stays in the signer (Amber/Bunker); only the local session ends. A full
 * reload instead of `wire:navigate`, because a clean guest start is the point.
 */
new class extends Component
{
    public function logout(PortalAuth $portalAuth): void
    {
        $portalAuth->logout();

        $start = route('group.start');
        $this->js(<<<JS
            try { localStorage.removeItem('pubkey'); localStorage.removeItem('sessions'); } catch (e) {}
            window.location.assign('{$start}');
        JS);
    }
}; ?>

<section aria-labelledby="settings-session">
    <flux:heading id="settings-session" level="2" size="sm" class="mb-2 text-muted">{{ __('Sitzung') }}</flux:heading>
    <flux:button wire:click="logout"
                 wire:confirm="{{ __('Abmelden? Dein Schlüssel bleibt in deinem Signer (Amber/Bunker) — nur die lokale Sitzung wird beendet.') }}"
                 variant="danger" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer justify-center">
        {{ __('Abmelden') }}
    </flux:button>
    <flux:text class="mt-1 px-1 text-xs text-muted">{{ __('Dein Schlüssel bleibt in deinem Signer (Amber/Bunker/Erweiterung) — nur die lokale Sitzung endet.') }}</flux:text>
</section>
