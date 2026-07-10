<?php

use App\Livewire\PortalPage;
use App\Services\PortalAuth;
use Livewire\Attributes\Layout;

/**
 * „Mehr“-Hub (P3, §3.4): der vierte Tab der verschmolzenen Shell. Ersetzt den
 * alten Hamburger-Flyout durch einen eigenen Screen mit Identitäts-Header +
 * gruppierten Sektionen (Entdecken · Meine Inhalte · Einstellungen).
 *
 * Der Identitäts-Header ist NUR ein Shortcut in die Einstellungen › Konto (§3.4,
 * Konfliktauflösung): Konto/Portal lebt genau einmal, hier steht kein zweites
 * Profil. Gast → „Anmelden“-CTA auf den (bis P6) bestehenden Login-View.
 *
 * Erbt PortalPage, damit der globale Header-Refresh (`portal-refresh`) auch hier
 * sauber quittiert wird (sonst dreht der Spinner endlos) und das Portal-Profil
 * des Headers frisch bleibt.
 */
new #[Layout('layouts::mobile', ['title' => 'Mehr', 'heading' => 'Mehr'])] class extends PortalPage
{
    public bool $connected = false;

    /** @var array<string, mixed>|null */
    public ?array $profile = null;

    public function mount(PortalAuth $auth): void
    {
        $this->connected = $auth->hasToken();
        $this->profile = $this->connected ? $auth->freshProfile() : null;
    }

    /**
     * Entdecken-Einträge (guest-lesbar §4.1). Routen spiegeln die realen
     * Discover-Ziele des alten Flyouts (Kurse/Referenten teilen `courses` via
     * ?tab, Städte hängen an `map`).
     *
     * @return list<array{icon: string, label: string, href: string, subtitle: string}>
     */
    public function discover(): array
    {
        return [
            ['icon' => 'calendar-days', 'label' => __('Termine'), 'href' => route('events'), 'subtitle' => __('Kommende Meetup-Termine')],
            ['icon' => 'map', 'label' => __('Karte'), 'href' => route('map'), 'subtitle' => __('Meetups & Orte in deiner Nähe')],
            ['icon' => 'academic-cap', 'label' => __('Kurse'), 'href' => route('courses'), 'subtitle' => __('Bildungsangebote der Community')],
            ['icon' => 'user', 'label' => __('Referenten'), 'href' => route('courses', ['tab' => 'referenten']), 'subtitle' => __('Wer unterrichtet')],
            ['icon' => 'building-office-2', 'label' => __('Städte & Orte'), 'href' => route('map', ['tab' => 'staedte']), 'subtitle' => __('Veranstaltungsorte')],
        ];
    }
};
?>

<x-portal-page>
    {{-- Identitäts-Header (§3.4): Shortcut in die Einstellungen bzw. Login-CTA. --}}
    @if ($connected)
        <x-list-link-card href="{{ route('profile') }}">
            @if ($profile['avatar'] ?? null)
                <flux:avatar src="{{ $profile['avatar'] }}" size="lg"/>
            @else
                <flux:avatar size="lg" name="{{ $profile['name'] ?? __('Verbunden') }}"/>
            @endif
            <span class="flex min-w-0 flex-col gap-0.5">
                <span class="truncate font-semibold">{{ $profile['name'] ?? __('Verbunden') }}</span>
                <span class="flex items-center gap-1.5">
                    <span class="size-2 rounded-full bg-green-500"></span>
                    <flux:text class="text-sm">{{ __('Mit Portal verbunden') }}</flux:text>
                </span>
            </span>
        </x-list-link-card>
    @else
        {{-- Gast: prominenter Anmelde-CTA (brand-getönt). Führt bis P6 auf den
             bestehenden Nostr-Login-View; P6 promotet ihn zum Login-Sheet. --}}
        <x-list-link-card href="{{ route('group.nostr-login') }}" class="!bg-brand-500/10 ring-1 ring-brand-500/20">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-full bg-brand-500/15 text-brand-600 dark:text-brand-400">
                <flux:icon name="user-circle" class="size-7"/>
            </span>
            <span class="flex min-w-0 flex-col gap-0.5">
                <span class="font-semibold text-brand-700 dark:text-brand-300">{{ __('Anmelden') }}</span>
                <flux:text class="text-sm">{{ __('Mit Nostr anmelden für Chat, Wallet & mehr') }}</flux:text>
            </span>
        </x-list-link-card>
    @endif

    {{-- ENTDECKEN (§3.4, guest-lesbar) --}}
    <div class="flex flex-col gap-2">
        <flux:heading class="px-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Entdecken') }}</flux:heading>
        <div class="list-stagger flex flex-col gap-3">
            @foreach ($this->discover() as $item)
                <x-list-link-card href="{{ $item['href'] }}" wire:key="discover-{{ $loop->index }}" style="--i: {{ $loop->index }}">
                    <span class="flex size-11 shrink-0 items-center justify-center rounded-tile bg-brand-500/10 text-brand-600 dark:text-brand-400">
                        <flux:icon :name="$item['icon']" class="size-6"/>
                    </span>
                    <span class="flex min-w-0 flex-col gap-0.5">
                        <span class="font-semibold">{{ $item['label'] }}</span>
                        <flux:text class="text-sm">{{ $item['subtitle'] }}</flux:text>
                    </span>
                </x-list-link-card>
            @endforeach
        </div>
    </div>

    {{-- MEINE INHALTE (§3.4, login-nötig — die Zielseite self-gated via requires-portal) --}}
    <div class="flex flex-col gap-2">
        <flux:heading class="px-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Meine Inhalte') }}</flux:heading>
        <x-list-link-card href="{{ route('mine') }}">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-tile bg-brand-500/10 text-brand-600 dark:text-brand-400">
                <flux:icon name="square-2-stack" class="size-6"/>
            </span>
            <span class="flex min-w-0 flex-col gap-0.5">
                <span class="font-semibold">{{ __('Meine Inhalte') }}</span>
                <flux:text class="text-sm">{{ __('Meetups, Termine, Orte & Kurse verwalten') }}</flux:text>
            </span>
        </x-list-link-card>
    </div>

    {{-- EINSTELLUNGEN (§3.4 → der eine Settings-Ort, §6; P5 verschmilzt den Inhalt) --}}
    <div class="flex flex-col gap-2">
        <flux:heading class="px-1 text-sm text-zinc-500 dark:text-zinc-400">{{ __('Einstellungen') }}</flux:heading>
        <x-list-link-card href="{{ route('profile') }}">
            <span class="flex size-11 shrink-0 items-center justify-center rounded-tile bg-zinc-500/10 text-zinc-600 dark:text-zinc-300">
                <flux:icon name="cog-6-tooth" class="size-6"/>
            </span>
            <span class="flex min-w-0 flex-col gap-0.5">
                <span class="font-semibold">{{ __('Einstellungen') }}</span>
                <flux:text class="text-sm">{{ __('Konto, Darstellung, Sprache & Region') }}</flux:text>
            </span>
        </x-list-link-card>
    </div>
</x-portal-page>
