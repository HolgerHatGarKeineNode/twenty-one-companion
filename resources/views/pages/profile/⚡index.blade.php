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

new #[Layout('layouts::mobile', ['title' => 'Einstellungen', 'heading' => 'Einstellungen'])] class extends PortalPage {
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

        // syncBrand persistiert die Region und löst bei echtem Markenwechsel
        // (z. B. DE→HU, nicht DE→AT) die Vollbild-Zelebrierung aus.
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

<div class="flex flex-col gap-6">
    <livewire:portal.connect/>

    {{-- Nostr-Identität, Räume & Relays leben im welshman-Kontext (group.settings,
         Chat-Layout) — die Mobile-Shell lädt kein welshman. Ein prominenter Shortcut
         hält den Einstieg an EINEM Ort (§Settings-Hub); der Nostr-Login gatet server-
         seitig. Kein wire:navigate: group.* wechselt das Vollbild-Chat-Layout. --}}
    <a href="{{ route('group.settings') }}"
       class="flex items-center gap-3 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
        <span class="flex size-10 items-center justify-center rounded-xl bg-brand-500/10">
            <flux:icon.key variant="solid" class="size-5 text-brand-500"/>
        </span>
        <span class="min-w-0 flex-1">
            <span class="block font-semibold">{{ __('Nostr-Identität, Räume & Relays') }}</span>
            <flux:text class="text-sm">{{ __('Schlüssel, Space & Netzwerk verwalten') }}</flux:text>
        </span>
        <flux:icon.chevron-right class="size-4 shrink-0 text-zinc-400"/>
    </a>

    <section class="flex flex-col gap-3">
        <flux:heading size="lg" level="2">{{ __('Einstellungen') }}</flux:heading>

        <div class="flex flex-col gap-5 rounded-2xl border border-zinc-200 bg-white p-4 dark:border-zinc-800 dark:bg-zinc-900">
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

            <flux:radio.group x-data x-model="$flux.appearance" :label="__('Darstellung')" variant="segmented">
                <flux:radio value="dark" :label="__('Dunkel')"/>
                <flux:radio value="light" :label="__('Hell')"/>
                <flux:radio value="system" :label="__('System')"/>
            </flux:radio.group>

            <flux:radio.group wire:model.live="density" :label="__('Listendichte')" variant="segmented">
                <flux:radio value="comfortable" :label="__('Normal')"/>
                <flux:radio value="compact" :label="__('Kompakt')"/>
            </flux:radio.group>

            <flux:separator variant="subtle"/>

            <flux:switch
                wire:model.live="pushEnabled"
                :label="__('Chat-Benachrichtigungen')"
                :description="__('Prüft im Hintergrund auf neue Nachrichten. Aus = keine Hintergrundaktivität, das schont den Akku.')"
            />
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

    {{-- Abmelden (§5.4/§6.10): der EINE Ort, ganz unten, destruktiv. Portal-Token +
         lokale Nostr-Sitzung weg; der Schlüssel bleibt im Signer. --}}
    <section class="flex flex-col gap-3">
        <flux:heading size="lg" level="2">{{ __('Sitzung') }}</flux:heading>

        <flux:button wire:click="logout"
                     wire:confirm="{{ __('Abmelden? Dein Schlüssel bleibt in deinem Signer (Amber/Bunker) — nur die lokale Sitzung wird beendet.') }}"
                     variant="danger" icon="arrow-right-start-on-rectangle" class="cursor-pointer justify-center">
            {{ __('Abmelden') }}
        </flux:button>
    </section>
</div>
