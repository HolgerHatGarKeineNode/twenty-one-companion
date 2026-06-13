<?php

use App\Livewire\PortalPage;
use App\Services\AppPreferences;
use App\Services\CountryOptions;
use App\Services\PortalAuth;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;

new #[Layout('layouts::mobile', ['title' => 'Profil', 'heading' => 'Profil'])] class extends PortalPage {
    public string $locale = AppPreferences::DEFAULT_LOCALE;

    public string $country = AppPreferences::DEFAULT_COUNTRY;

    public function mount(AppPreferences $preferences): void
    {
        $this->locale = $preferences->locale();
        $this->country = $preferences->country();

        // Landeplatz nach dem Login-Deep-Link: Rückmeldung als Toast.
        if (session()->pull('portal-connected')) {
            Flux::toast(text: __('Mit dem Portal verbunden.'), variant: 'success');
        }

        if (session()->pull('portal-connect-failed')) {
            Flux::toast(text: __('Anmeldung fehlgeschlagen. Bitte versuche es erneut.'), variant: 'danger');
        }
    }

    /**
     * @return Collection<int, array{code: string, name: string}>
     */
    #[Computed]
    public function countries(): Collection
    {
        return app(CountryOptions::class)->all();
    }

    public function updatedLocale(AppPreferences $preferences): void
    {
        if (! in_array($this->locale, AppPreferences::SUPPORTED_LOCALES, true)) {
            $this->locale = $preferences->locale();

            return;
        }

        $preferences->setLocale($this->locale);
        Flux::toast(text: __('Gespeichert.'), variant: 'success');
    }

    public function updatedCountry(AppPreferences $preferences, CountryOptions $countryOptions): void
    {
        if (! in_array($this->country, $countryOptions->validCodes(), true)) {
            $this->country = $preferences->country();

            return;
        }

        $preferences->setCountry($this->country);
        Flux::toast(text: __('Gespeichert.'), variant: 'success');
    }

    public function openPortal(PortalAuth $portalAuth): void
    {
        $this->openLink($portalAuth->baseUrl());
    }
};
?>

<div class="flex flex-col gap-6">
    <livewire:portal.connect/>

    <section class="flex flex-col gap-3">
        <flux:heading size="lg" level="2">{{ __('Einstellungen') }}</flux:heading>

        <div class="flex flex-col gap-5 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
            <x-locale-radio-group wire:model.live="locale"/>

            <flux:field>
                <flux:label>{{ __('Region') }}</flux:label>
                <x-country-select :countries="$this->countries" wire:model.live="country"/>
                <flux:description>{{ __('Standardfilter für Meetups und Termine.') }}</flux:description>
            </flux:field>

            <flux:radio.group x-data x-model="$flux.appearance" :label="__('Darstellung')" variant="segmented">
                <flux:radio value="dark" :label="__('Dunkel')"/>
                <flux:radio value="light" :label="__('Hell')"/>
                <flux:radio value="system" :label="__('System')"/>
            </flux:radio.group>
        </div>
    </section>

    <section class="flex flex-col gap-3">
        <flux:heading size="lg" level="2">{{ __('Über die App') }}</flux:heading>

        <div class="flex flex-col gap-4 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
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
</div>
