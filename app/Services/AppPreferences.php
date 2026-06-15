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

    /**
     * Standard-Zeitzone für die Anzeige (DB/API liefern UTC). Der Nutzer kann
     * sie im Profil überschreiben; bis dahin gilt Mitteleuropa.
     */
    public const DEFAULT_TIMEZONE = 'Europe/Berlin';

    /**
     * Unterstützte App-Sprachen. Deutsche Quell-Strings + je eine lang/*.json
     * pro Locale (de nutzt die Quell-Strings direkt). Von der Region/Marke
     * entkoppelt — die Sprache ist eine eigenständige Nutzerwahl.
     */
    public const SUPPORTED_LOCALES = ['de', 'en', 'es', 'hu', 'lv', 'nl', 'pl', 'pt'];

    /**
     * Schritte des geführten Onboarding-Pagers (Phase 3.1). Hier zentral,
     * weil onboardingStep() denselben Index persistiert und Seiten/Tests
     * sich darauf beziehen.
     */
    public const STEP_WELCOME = 0;

    public const STEP_LANGUAGE = 1;

    public const STEP_REGION = 2;

    public const STEP_PORTAL = 3;

    public const STEP_NOTIFICATIONS = 4;

    public const STEP_DONE = 5;

    public const LAST_STEP = self::STEP_DONE;

    private const KEY_ONBOARDED = 'onboarded_at';

    private const KEY_LOCALE = 'locale';

    private const KEY_COUNTRY = 'country';

    private const KEY_TIMEZONE = 'timezone';

    private const KEY_ONBOARDING_STEP = 'onboarding_step';

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
     * Zuletzt erreichter Onboarding-Schritt (0-basiert), damit ein App-
     * Neustart mitten im Pager dort wieder aufsetzt (Phase 3.6/3.7).
     * Vor dem Abschluss noch nicht gesetzt → 0 (Welcome-Step).
     */
    public function onboardingStep(): int
    {
        return (int) ($this->get(self::KEY_ONBOARDING_STEP) ?? 0);
    }

    public function setOnboardingStep(int $step): void
    {
        $this->set(self::KEY_ONBOARDING_STEP, (string) max(0, $step));
    }

    /**
     * Zielroute nach einem Portal-Login-Callback: mitten im Onboarding
     * zurück in den Pager, sonst aufs Profil (Phase 3.4). Hier zentral,
     * weil die Onboarding-State-Logik ohnehin diesem Service gehört.
     */
    public function targetAfterPortalAuth(): string
    {
        return $this->isOnboarded() ? 'profile' : 'onboarding';
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

    /**
     * Anzeige-Zeitzone des Nutzers (IANA-Identifier, z. B. "Europe/Berlin").
     * DB/API-Zeiten sind UTC und werden erst für die Anzeige hierhin
     * umgerechnet; Eingaben in den Editoren werden von hier nach UTC
     * zurückgerechnet. Fällt auf {@see DEFAULT_TIMEZONE} zurück, solange nichts
     * Gültiges gespeichert ist.
     */
    public function timezone(): string
    {
        $timezone = $this->get(self::KEY_TIMEZONE);

        return $timezone !== null && in_array($timezone, timezone_identifiers_list(), true)
            ? $timezone
            : self::DEFAULT_TIMEZONE;
    }

    public function setTimezone(string $timezone): void
    {
        if (in_array($timezone, timezone_identifiers_list(), true)) {
            $this->set(self::KEY_TIMEZONE, $timezone);
        }
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
