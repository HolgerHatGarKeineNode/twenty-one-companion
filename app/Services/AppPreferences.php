<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

/**
 * Lokale App-Einstellungen (Sprache, Region, Onboarding-Status) in der
 * geräteeigenen SQLite-Datenbank. Bewusst eine eigene Tabelle statt des
 * Cache-Stores: Einstellungen müssen ein cache:clear bzw. das Verwerfen
 * der API-Caches überleben.
 */
final class AppPreferences
{
    public const DEFAULT_LOCALE = 'de';

    public const DEFAULT_COUNTRY = 'de';

    /** Unterstützte App-Sprachen (deutsche Quell-Strings + lang/en.json). */
    public const SUPPORTED_LOCALES = ['de', 'en'];

    private const KEY_ONBOARDED = 'onboarded_at';

    private const KEY_LOCALE = 'locale';

    private const KEY_COUNTRY = 'country';

    /**
     * Pro Request memoisierte Tabelle (als scoped Singleton registriert),
     * damit Middleware und Seiten nicht mehrfach lesen.
     *
     * @var array<string, string>|null
     */
    private ?array $memoized = null;

    public function isOnboarded(): bool
    {
        return $this->get(self::KEY_ONBOARDED) !== null;
    }

    public function completeOnboarding(string $locale, string $country): void
    {
        $this->setLocale($locale);
        $this->setCountry($country);
        $this->set(self::KEY_ONBOARDED, now()->toIso8601String());
    }

    /**
     * Gewählte App-Sprache; fällt auf Deutsch zurück, solange nichts
     * Unterstütztes gespeichert ist.
     */
    public function locale(): string
    {
        $locale = $this->get(self::KEY_LOCALE);

        return in_array($locale, self::SUPPORTED_LOCALES, true) ? $locale : self::DEFAULT_LOCALE;
    }

    public function setLocale(string $locale): void
    {
        if (in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $this->set(self::KEY_LOCALE, $locale);
        }
    }

    /**
     * Gewählter Länder-Code (lowercase, z. B. "de") als Standardfilter für
     * Meetups & Termine; leerer String = alle Länder.
     */
    public function country(): string
    {
        // Lowercase auch beim Lesen garantieren — die Filter der Seiten
        // verlassen sich darauf (setCountry normalisiert bereits).
        return mb_strtolower($this->get(self::KEY_COUNTRY) ?? self::DEFAULT_COUNTRY);
    }

    public function setCountry(string $country): void
    {
        $this->set(self::KEY_COUNTRY, mb_strtolower(trim($country)));
    }

    public function get(string $key): ?string
    {
        return $this->all()[$key] ?? null;
    }

    public function set(string $key, string $value): void
    {
        DB::table('preferences')->upsert(
            [['key' => $key, 'value' => $value, 'created_at' => now(), 'updated_at' => now()]],
            uniqueBy: ['key'],
            update: ['value', 'updated_at'],
        );

        if ($this->memoized !== null) {
            $this->memoized[$key] = $value;
        }
    }

    /**
     * @return array<string, string>
     */
    private function all(): array
    {
        return $this->memoized ??= DB::table('preferences')
            ->pluck('value', 'key')
            ->map(fn (mixed $value): string => (string) $value)
            ->all();
    }
}
