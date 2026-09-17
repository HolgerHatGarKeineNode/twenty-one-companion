<?php

use App\Livewire\PortalPage;
use App\Services\PortalAuth;

/**
 * "Über die App" — version plus the way out to the Portal in a real browser, injected into
 * the package settings hub (P2).
 *
 * Extends `PortalPage` for `openLink()`: on the device the Portal opens in the in-app
 * browser through the native bridge, not as a plain `href` inside the WebView (which would
 * be a window without a way back).
 */
new class extends PortalPage
{
    public function openPortal(PortalAuth $portalAuth): void
    {
        $this->openLink($portalAuth->baseUrl());
    }
}; ?>

<section aria-labelledby="settings-about">
    <flux:heading id="settings-about" level="2" size="sm" class="mb-2 text-muted">{{ __('Über die App') }}</flux:heading>
    <div class="surface-card flex flex-col gap-4 p-4">
        <div class="flex items-center justify-between gap-3">
            <flux:text>{{ __('Version') }}</flux:text>
            <span class="font-semibold">{{ config('nativephp.version') }}</span>
        </div>

        <flux:separator/>

        <flux:button wire:click="openPortal" size="sm" icon="globe-alt" class="cursor-pointer">
            {{ __('EINUNDZWANZIG-Portal öffnen') }}
        </flux:button>
    </div>
</section>
