<?php

use App\Livewire\PortalPage;
use App\Services\AppPreferences;
use App\Services\CountryOptions;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * "Region & Sprache" plus the list density — the app-only display preferences, injected
 * into the package settings hub (P2, `config('group.settings')`).
 *
 * **Why a Livewire component and not a plain partial.** These four values are SERVER state
 * (`AppPreferences`, persisted through the native key store), and they save on change via
 * `wire:model.live`. The package hub is a stateless full-page component; a `wire:model` in a
 * partial included there would bind against nothing. A nested component brings its own
 * state, so the hub stays stateless and this file owns exactly what it writes.
 *
 * Extends `PortalPage` for `syncBrand()`: the region is also the brand (DE/AT/CH/HU each
 * have their own wordmark), and the live header logo listens for `brand-changed`.
 *
 * Everything in here is lifted VERBATIM from `pages/profile/⚡index.blade.php`, which is
 * deleted with this phase — including the two paragraphs of reasoning about the timezone
 * listbox, because that reasoning is a measurement and not a preference.
 */
new class extends PortalPage
{
    /**
     * Curated display timezones (DACH first, then common Bitcoin regions). A stored
     * timezone outside this list is prepended in {@see timezones()}.
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

    public function mount(AppPreferences $preferences): void
    {
        $this->locale = $preferences->locale();
        $this->country = $preferences->country();
        $this->timezone = $preferences->timezone();
        $this->density = $preferences->density();
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

        // `syncBrand` persists the region and, on a real brand change (DE→HU, not DE→AT),
        // reports `brand-changed` for the live header logo.
        $this->syncBrand($this->country);
        Flux::toast(text: __('Gespeichert.'), variant: 'success');
    }

    /**
     * Selectable display timezones as [value => label with the current UTC offset]; a stored
     * timezone outside the curated list is prepended.
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
}; ?>

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
            {{-- A listbox and not a native select (dark theme, see `x-locale-radio-group`).

                 Deliberately WITHOUT `searchable`, even though 26 zones invite scrolling:
                 the search field brings Flux' own strings with it ("Search...", "No results
                 found"), and Flux folds its stubs with `@blaze(fold: true)` — the result of
                 `__()` is baked in at compile time and does not change with the language
                 afterwards. Measured: after one render in `de` the same call still returns
                 „Suchen …" under `en`. In an app with eight languages the search field would
                 be mislabelled for seven of them. --}}
            <flux:select variant="listbox" wire:model.live="timezone">
                @foreach ($this->timezones as $value => $label)
                    <flux:select.option :value="$value" wire:key="tz-{{ $value }}">{{ $label }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:description>{{ __('Alle Datums- und Uhrzeitangaben werden in dieser Zeitzone angezeigt.') }}</flux:description>
        </flux:field>

        {{-- The list density sits here and not in the package's „Darstellung" section: that
             one is the shared theme switch, and this value is an app preference the package
             does not know. Same block, because both answer "how does the surface look". --}}
        <flux:radio.group wire:model.live="density" :label="__('Listendichte')" variant="segmented">
            <flux:radio value="comfortable" :label="__('Normal')"/>
            <flux:radio value="compact" :label="__('Kompakt')"/>
        </flux:radio.group>
    </div>
</section>
