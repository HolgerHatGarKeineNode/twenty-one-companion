<?php

use App\Services\PortalAuth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Einmaliger Hinweis-Banner zu den neuen Anmeldungs-/Sichtbarkeits-Einstellungen.
 * Nur für Meetup-Leader. Das Wegklicken wird pro Gerät via Alpine $persist
 * (localStorage) dauerhaft gemerkt — kein Server-Roundtrip nötig, da ein Gerät
 * hier genau einem Nutzer entspricht.
 */
new class extends Component
{
    /**
     * Der Profil-Cache trägt das is_leader-Flag bereits (netzwerkfrei) — kein
     * zusätzlicher my-meetups-Request auf dem Layout-Hot-Path nötig.
     */
    #[Computed]
    public function isMeetupAdmin(): bool
    {
        return (bool) (app(PortalAuth::class)->cachedProfile()['is_leader'] ?? false);
    }
}; ?>

<div>
    @if ($this->isMeetupAdmin)
        <div
            x-data="{ show: $persist(true).as('meetup-privacy-hint-dismissed') }"
            x-show="show"
            x-cloak
            class="px-4 pt-4"
        >
            <div class="rounded-tile border border-brand-200 bg-brand-50 p-4 dark:border-brand-500/30 dark:bg-brand-500/10">
                <div class="flex items-start gap-3">
                    <flux:icon name="megaphone" class="size-6 shrink-0 text-brand-600 dark:text-brand-400"/>
                    <div class="min-w-0 flex-1">
                        <flux:heading size="sm">{{ __('Neu: Anmeldung & Sichtbarkeit') }}</flux:heading>
                        <flux:text class="mt-1 text-sm">
                            {{ __('Du kannst jetzt pro Meetup steuern, ob sich Besucher anmelden können und ob die Teilnehmerliste öffentlich ist. Öffne dein Meetup zum Bearbeiten.') }}
                        </flux:text>
                        <div class="mt-3">
                            <flux:button size="sm" variant="primary" icon="check"
                                         x-on:click="$haptic('light'); show = false">
                                {{ __('Verstanden') }}
                            </flux:button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
