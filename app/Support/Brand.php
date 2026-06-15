<?php

namespace App\Support;

/**
 * Marken-Variante der App. Das Branding folgt dem gewählten LAND (über dessen
 * Hauptsprache) und ist von der UI-Sprache entkoppelt. Jede Variante besitzt
 * eine eigene designte SVG-Wortmarke (resources/views/components/brand/wordmark).
 *
 * Bei neuen Sprachen/Ländern: neuen Case + Label ergänzen, Wortmarke per
 * scripts/brand/generate_wordmarks.py erzeugen und config/brand.php mappen.
 */
enum Brand: string
{
    case TwentyOne = 'twenty-one';
    case Einundzwanzig = 'einundzwanzig';
    case Veintiuno = 'veintiuno';
    case Huszonegy = 'huszonegy';
    case Eenentwintig = 'eenentwintig';
    case DwadziesciaJeden = 'dwadziescia-jeden';
    case VinteEUm = 'vinte-e-um';

    /** Anzeigetext der Wortmarke (immer in Versalien). */
    public function label(): string
    {
        return match ($this) {
            self::TwentyOne => 'TWENTY ONE',
            self::Einundzwanzig => 'EINUNDZWANZIG',
            self::Veintiuno => 'VEINTIUNO',
            self::Huszonegy => 'HUSZONEGY',
            self::Eenentwintig => 'EENENTWINTIG',
            self::DwadziesciaJeden => 'DWADZIEŚCIA JEDEN',
            self::VinteEUm => 'VINTE E UM',
        };
    }

    /**
     * Voller App-Name = Wortmarke + „ Companion". „Companion" bleibt bewusst
     * international (Englisch), siehe App-Umbenennung.
     */
    public function appName(): string
    {
        return $this->label().' Companion';
    }

    /** Name der anonymen Blade-Wortmarken-Komponente (<x-brand.wordmark.…>). */
    public function wordmarkComponent(): string
    {
        return 'brand.wordmark.'.$this->value;
    }

    /**
     * Marken-Variante für einen Ländercode (lowercase ISO-3166-1 alpha-2).
     * Unbekannte/leere Länder fallen auf die Standardmarke zurück.
     */
    public static function forCountry(?string $code): self
    {
        $code = mb_strtolower(trim((string) $code));
        $slug = config('brand.countries')[$code] ?? config('brand.default');

        return self::tryFrom((string) $slug) ?? self::default();
    }

    public static function default(): self
    {
        return self::tryFrom((string) config('brand.default')) ?? self::TwentyOne;
    }
}
