<?php

namespace App\Services;

use App\Support\Brand;

/**
 * Löst die aktuelle Marken-Variante aus der gewählten Region (AppPreferences::
 * country()) auf. Bewusst NICHT memoisiert: NativePHP läuft als langlebiger
 * Prozess, scoped Singletons überleben Requests — ein gecachter Brand würde bei
 * einem Regionswechsel einfrieren. Die Auflösung ist ohnehin billig (country()
 * ist selbst memoisiert, forCountry() nur ein Config-Lookup + Enum-Cast).
 */
final class BrandResolver
{
    public function __construct(private readonly AppPreferences $preferences) {}

    public function current(): Brand
    {
        return Brand::forCountry($this->preferences->country());
    }

    public function forCountry(?string $code): Brand
    {
        return Brand::forCountry($code);
    }
}
