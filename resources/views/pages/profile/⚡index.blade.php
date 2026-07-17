<?php

use App\Livewire\PortalPage;
use App\Services\AppPreferences;
use App\Services\CountryOptions;
use App\Services\PortalAuth;
use Carbon\CarbonImmutable;
use Einundzwanzig\Push\Push;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;

/*
 * Verschmolzene Einstellungen (P6, Option A): EIN Screen im group-Vollbild-Layout
 * (welshman verfügbar) statt der Mobile-Shell — so leben Portal-Prefs (Livewire-
 * Server-State, diese Klasse) UND die Nostr-Sektionen (welshman-Inseln, per
 * `@include('group::partials.settings.*')`) auf einer Fläche. Theme und Abmelden
 * erscheinen genau EINMAL (der Portal+Nostr-`logout()` gewinnt). Reihenfolge nach
 * plans/merged-mobile-settings-ux.md.
 */
new #[Layout('group::einundzwanzig')] #[Title('Einstellungen')] class extends PortalPage {
    /**
     * Kuratierte Anzeige-Zeitzonen (DACH zuerst, dann häufige Bitcoin-Regionen).
     * Eine abweichend gespeicherte Zeitzone wird in {@see timezones()} ergänzt.
     */
    private const TIMEZONE_OPTIONS = [
        'Europe/Berlin', 'Europe/Vienna', 'Europe/Zurich', 'Europe/London',
        'Europe/Lisbon', 'Europe/Madrid', 'Europe/Paris', 'Europe/Amsterdam',
        'Europe/Rome', 'Europe/Prague', 'Europe/Warsaw', 'Europe/Athens',
        'Europe/Helsinki', 'Europe/Istanbul', 'America/New_York', 'America/Chicago',
        'America/Denver', 'America/Los_Angeles', 'America/Sao_Paulo',
        'America/Argentina/Buenos_Aires', 'Africa/Johannesburg', 'Asia/Dubai',
        'Asia/Singapore', 'Asia/Tokyo', 'Australia/Sydney', 'UTC',
    ];

    public string $locale = AppPreferences::DEFAULT_LOCALE;

    public string $country = AppPreferences::DEFAULT_COUNTRY;

    public string $timezone = AppPreferences::DEFAULT_TIMEZONE;

    public string $density = AppPreferences::DEFAULT_DENSITY;

    public bool $pushEnabled = false;

    public function mount(AppPreferences $preferences): void
    {
        $this->locale = $preferences->locale();
        $this->country = $preferences->country();
        $this->timezone = $preferences->timezone();
        $this->density = $preferences->density();
        // Bewusst NUR die Einstellung, ohne `notificationPermissionGranted()`:
        // Der Schalter kann dadurch AN zeigen, obwohl der Nutzer den OS-Dialog
        // abgelehnt hat (bekannt, siehe plans/PUSH-NOTIFICATIONS.md). Der
        // naheliegende Fix ist aber schlimmer als der Fehler — Bridge-Aufrufe
        // während des Seitenaufbaus sind unzuverlässig (§4), und ein falsches
        // `false` von dort schaltet dem Nutzer Push still ab.
        $this->pushEnabled = $preferences->pushEnabled();

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
        if (! AppPreferences::isValidLocale($this->locale)) {
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

        // syncBrand persistiert die Region und meldet bei echtem Markenwechsel
        // (z. B. DE→HU, nicht DE→AT) `brand-changed` fürs Live-Header-Logo.
        $this->syncBrand($this->country);
        Flux::toast(text: __('Gespeichert.'), variant: 'success');
    }

    /**
     * Auswählbare Anzeige-Zeitzonen als [value => Label mit aktueller
     * UTC-Verschiebung]; eine abweichend gespeicherte Zeitzone wird vorangestellt.
     *
     * @return array<string, string>
     */
    #[Computed]
    public function timezones(): array
    {
        $ids = self::TIMEZONE_OPTIONS;

        if (! in_array($this->timezone, $ids, true)) {
            array_unshift($ids, $this->timezone);
        }

        $now = CarbonImmutable::now();
        $options = [];

        foreach ($ids as $id) {
            $city = str_replace('_', ' ', str_contains($id, '/') ? substr((string) strrchr($id, '/'), 1) : $id);
            $options[$id] = $city.' · UTC'.$now->setTimezone($id)->format('P');
        }

        return $options;
    }

    public function updatedTimezone(AppPreferences $preferences): void
    {
        if (! in_array($this->timezone, timezone_identifiers_list(), true)) {
            $this->timezone = $preferences->timezone();

            return;
        }

        $preferences->setTimezone($this->timezone);
        Flux::toast(text: __('Gespeichert.'), variant: 'success');
    }

    public function updatedDensity(AppPreferences $preferences): void
    {
        if (! in_array($this->density, AppPreferences::DENSITIES, true)) {
            $this->density = $preferences->density();

            return;
        }

        $preferences->setDensity($this->density);
        Flux::toast(text: __('Gespeichert.'), variant: 'success');
    }

    /**
     * Push-Schalter. Beim Einschalten wird zusätzlich die Berechtigung
     * angefragt — ohne POST_NOTIFICATIONS zeigt der Worker stillschweigend
     * nichts an.
     *
     * Das Ein-/Ausplanen des Workers macht der push-sync-Partial im Layout:
     * er braucht den Pubkey aus localStorage, den PHP nicht kennt. Beim
     * AUSschalten stoppt er den Worker, sodass keine Hintergrundaktivität
     * mehr läuft (Akku).
     */
    public function updatedPushEnabled(AppPreferences $preferences): void
    {
        $preferences->setPushEnabled($this->pushEnabled);

        if ($this->pushEnabled) {
            (new Push)->requestNotificationPermission();
        } else {
            (new Push)->sync();
        }

        Flux::toast(text: __('Gespeichert.'), variant: 'success');
    }

    public function openPortal(PortalAuth $portalAuth): void
    {
        $this->openLink($portalAuth->baseUrl());
    }

    /**
     * Der EINE Abmelden-Ort (App-Shell-Verschmelzung §5.4/§6.10): Portal-Token
     * widerrufen + aus dem Keystore löschen, dann die client-seitige Nostr-Session
     * (welshman liest `pubkey`/`sessions` aus localStorage beim Boot) räumen und als
     * Gast zu den Meetups. Der Schlüssel bleibt im Signer (Amber/Bunker) — nur die
     * lokale Sitzung geht. Voller Reload (kein wire:navigate) = sauberer Gast-Start.
     */
    public function logout(PortalAuth $portalAuth): void
    {
        $portalAuth->logout();

        $meetups = route('meetups');
        $this->js(<<<JS
            try { localStorage.removeItem('pubkey'); localStorage.removeItem('sessions'); } catch (e) {}
            window.location.assign('{$meetups}');
        JS);
    }
};
?>

<x-group::app-shell>

    <x-group::app-header title="{{ __('Einstellungen') }}">
        <x-slot:subtitle>
            <flux:text class="text-sm">{{ __('Konto, Space & Darstellung an einem Ort.') }}</flux:text>
        </x-slot:subtitle>
    </x-group::app-header>

    {{-- EINE nostrAuth-Insel für die ganze Seite: die Nostr-Partials (account/space)
         hängen an diesem Scope; die Portal-Prefs sind Livewire-Server-State dieser
         Komponente. Theme und Abmelden erscheinen genau EINMAL (De-Dup, s. IA §3). --}}
    <div class="page-enter space-y-8" x-data="nostrAuth">

        {{-- 1 · Konto & Verbindungen: Nostr-Identität (welshman) + Portal-Verbindung.
             Der npub ist die kanonische Identität → oben; das Portal ist der daran
             gekoppelte Dienst → direkt darunter. --}}
        @include('group::partials.settings.account')
        <livewire:portal.connect/>

        {{-- 2 · Space & Räume (welshman-Insel) --}}
        @include('group::partials.settings.space')

        {{-- 3 · Region & Sprache (Portal-Server-Prefs, wire:model.live → AppPreferences) --}}
        <section aria-labelledby="settings-region">
            <flux:heading id="settings-region" level="2" size="sm" class="mb-2 text-muted">{{ __('Region & Sprache') }}</flux:heading>
            <div class="surface-card flex flex-col gap-5 p-4">
                <x-locale-radio-group wire:model.live="locale"/>

                <flux:field>
                    <flux:label>{{ __('Region') }}</flux:label>
                    <x-country-select :countries="$this->countries" wire:model.live="country"/>
                    <flux:description>{{ __('Standardfilter für Meetups und Termine.') }}</flux:description>
                </flux:field>

                <flux:field>
                    <flux:label>{{ __('Zeitzone') }}</flux:label>
                    <flux:select wire:model.live="timezone">
                        @foreach ($this->timezones as $value => $label)
                            <flux:select.option :value="$value" wire:key="tz-{{ $value }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:description>{{ __('Alle Datums- und Uhrzeitangaben werden in dieser Zeitzone angezeigt.') }}</flux:description>
                </flux:field>
            </div>
        </section>

        {{-- 4 · Darstellung: Theme (der EINE — De-Dup, icon+text) + Listendichte.
             Theme bindet den geteilten $flux.appearance-Store (flackerfrei im <head>). --}}
        <section aria-labelledby="settings-appearance">
            <flux:heading id="settings-appearance" level="2" size="sm" class="mb-2 text-muted">{{ __('Darstellung') }}</flux:heading>
            <div class="surface-card flex flex-col gap-5 p-4">
                <flux:radio.group x-data x-model="$flux.appearance" :label="__('Theme')" variant="segmented">
                    <flux:radio value="light" icon="sun" :label="__('Hell')"/>
                    <flux:radio value="system" icon="computer-desktop" :label="__('Automatisch')"/>
                    <flux:radio value="dark" icon="moon" :label="__('Dunkel')"/>
                </flux:radio.group>

                <flux:radio.group wire:model.live="density" :label="__('Listendichte')" variant="segmented">
                    <flux:radio value="comfortable" :label="__('Normal')"/>
                    <flux:radio value="compact" :label="__('Kompakt')"/>
                </flux:radio.group>
            </div>
        </section>

        {{-- 5 · Benachrichtigungen --}}
        <section aria-labelledby="settings-push">
            <flux:heading id="settings-push" level="2" size="sm" class="mb-2 text-muted">{{ __('Benachrichtigungen') }}</flux:heading>
            <div class="surface-card p-4">
                <flux:switch
                    wire:model.live="pushEnabled"
                    :label="__('Chat-Benachrichtigungen')"
                    :description="__('Prüft im Hintergrund auf neue Nachrichten. Aus = keine Hintergrundaktivität, das schont den Akku.')"
                />
            </div>
        </section>

        {{-- 6 · Erweitert (progressive disclosure): Relays + Medien, read-only Fachjargon
             → standardmäßig eingeklappt. Natives <details> = a11y (aria-expanded) ohne JS. --}}
        <section aria-labelledby="settings-advanced">
            <flux:heading id="settings-advanced" level="2" size="sm" class="mb-2 text-muted">{{ __('Erweitert') }}</flux:heading>
            <details class="surface-card group/adv overflow-hidden [&_summary::-webkit-details-marker]:hidden">
                <summary class="pressable flex min-h-11 cursor-pointer list-none items-center justify-between gap-3 p-4">
                    <span class="text-sm font-medium">{{ __('Netzwerk, Relays & Medien') }}</span>
                    <flux:icon.chevron-down class="size-4 shrink-0 text-muted transition-transform group-open/adv:rotate-180" />
                </summary>
                <div class="space-y-8 border-t border-zinc-100 p-4 dark:border-zinc-800">
                    @include('group::partials.settings.relays')
                    @include('group::partials.settings.blossom')
                </div>
            </details>
        </section>

        {{-- 7 · Über die App --}}
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

        {{-- Abmelden (De-Dup §3.2): der EINE Logout — Portal-Token widerrufen UND lokale
             Nostr-Sitzung räumen (PortalPage::logout). Destruktiv, ganz unten. Der Nostr-
             only-Logout (session-Partial) wird bewusst NICHT eingebunden. --}}
        <section aria-labelledby="settings-session">
            <flux:heading id="settings-session" level="2" size="sm" class="mb-2 text-muted">{{ __('Sitzung') }}</flux:heading>
            <flux:button wire:click="logout"
                         wire:confirm="{{ __('Abmelden? Dein Schlüssel bleibt in deinem Signer (Amber/Bunker) — nur die lokale Sitzung wird beendet.') }}"
                         variant="danger" icon="arrow-right-start-on-rectangle" class="w-full cursor-pointer justify-center">
                {{ __('Abmelden') }}
            </flux:button>
            <flux:text class="mt-1 px-1 text-xs text-muted">{{ __('Dein Schlüssel bleibt in deinem Signer (Amber/Bunker/Erweiterung) — nur die lokale Sitzung endet.') }}</flux:text>
        </section>

    </div>
</x-group::app-shell>
