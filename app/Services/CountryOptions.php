<?php

namespace App\Services;

use App\Data\Portal\MobileMeetupData;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * Wählbare Regionen für Onboarding und Profil: nur Länder, in denen es
 * Meetups gibt, mit Klarnamen aus dem Countries-Endpunkt. Beide Quellen
 * sind gecacht/stale und damit meist auch offline verfügbar; ohne Daten
 * fällt die Auswahl auf DACH zurück.
 *
 * ⚠️ Casing der Portal-API ist gemischt: Meetups/Termine liefern
 * Ländercodes in GROSSBUCHSTABEN ("DE"), /api/countries in
 * Kleinbuchstaben ("de"). Intern gilt überall lowercase.
 */
final class CountryOptions
{
    /**
     * Pro Request memoisiert (als scoped Singleton registriert), weil
     * Render und Validierung derselben Livewire-Interaktion die Liste
     * mehrfach lesen — jedes all() wäre sonst ein Cache-Read + Mapping.
     *
     * @var Collection<int, array{code: string, name: string}>|null
     */
    private ?Collection $memoizedAll = null;

    public function __construct(private readonly PortalApi $portalApi) {}

    /**
     * @return Collection<int, array{code: string, name: string}>
     */
    public function all(): Collection
    {
        return $this->memoizedAll ??= $this->build();
    }

    /**
     * @return Collection<int, array{code: string, name: string}>
     */
    private function build(): Collection
    {
        // Dieselbe schlanke Liste wie Meetup-Liste/Karte, damit alle denselben
        // Cache-Eintrag teilen und kein zweiter API-Call nötig ist.
        $meetupCountries = $this->portalApi->mobileMeetups()
            ->map(fn (MobileMeetupData $meetup): string => mb_strtolower($meetup->country))
            ->filter()
            ->unique();

        if ($meetupCountries->isEmpty()) {
            return collect([
                ['code' => 'de', 'name' => 'Deutschland'],
                ['code' => 'at', 'name' => 'Österreich'],
                ['code' => 'ch', 'name' => 'Schweiz'],
            ]);
        }

        $names = $this->names(array_values($meetupCountries->all()));

        return $meetupCountries
            ->map(fn (string $code): array => ['code' => $code, 'name' => $names[$code] ?? mb_strtoupper($code)])
            // Str::ascii statt roher Byte-Order, damit „Österreich“ nicht
            // hinter „Zypern“ landet (Collator wäre intl-abhängig).
            ->sortBy(fn (array $country): string => Str::ascii($country['name']))
            ->values();
    }

    /**
     * Anzeigenamen je Ländercode: bevorzugt lokalisiert über die
     * intl-Extension (offline, in App-Sprache); ohne intl — NativePHPs
     * statisches PHP bündelt sie nicht verlässlich — über den
     * Countries-Endpunkt (selected hebt dessen 10er-Limit auf).
     *
     * @param  list<string>  $codes
     * @return array<string, string>
     */
    private function names(array $codes): array
    {
        $names = [];

        if (function_exists('locale_get_display_region')) {
            foreach ($codes as $code) {
                $name = locale_get_display_region('-'.mb_strtoupper($code), app()->getLocale());
                $names[$code] = $name === false || $name === '' ? mb_strtoupper($code) : $name;
            }

            return $names;
        }

        foreach ($this->portalApi->countries(selected: $codes) as $country) {
            if (is_string($country->code) && $country->code !== '') {
                $names[mb_strtolower($country->code)] = $country->name;
            }
        }

        return $names;
    }

    /**
     * Gültige Auswahlwerte inklusive „Alle Länder“ (leerer String).
     *
     * @return list<string>
     */
    public function validCodes(): array
    {
        return array_values($this->all()
            ->map(fn (array $country): string => $country['code'])
            ->push('')
            ->all());
    }

    /**
     * Geteilte Filter-Pipeline der Seiten-Selects: Codes lowercase
     * normalisieren (API liefert Großbuchstaben), die aktuell gewählte
     * Region ergänzen (damit das Select nie einen unbekannten Wert zeigt),
     * deduplizieren und sortieren.
     *
     * @param  Collection<int, string>  $codes
     * @return list<string>
     */
    public static function filterCodes(Collection $codes, string $selected): array
    {
        return array_values($codes
            ->map(fn (string $code): string => mb_strtolower($code))
            ->push(mb_strtolower($selected))
            ->filter()
            ->unique()
            ->sort()
            ->all());
    }

    /** Flaggen-Emoji aus dem Ländercode (Regional-Indicator-Symbole). */
    public static function flagEmoji(string $code): string
    {
        return implode('', array_map(
            fn (string $letter): string => (string) mb_chr(0x1F1E6 + ord($letter) - ord('a')),
            str_split(mb_strtolower($code)),
        ));
    }
}
