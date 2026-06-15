<?php

namespace App\Services;

use App\Support\Brand;

/**
 * Löst die aktuelle Marken-Variante aus der gewählten Region (AppPreferences::
 * country()) auf. Pro Request memoisiert (als scoped Singleton registriert),
 * weil Layout, Komponenten und Animation dieselbe Marke mehrfach lesen.
 */
final class BrandResolver
{
    private ?Brand $memoized = null;

    public function __construct(private readonly AppPreferences $preferences) {}

    public function current(): Brand
    {
        return $this->memoized ??= Brand::forCountry($this->preferences->country());
    }

    public function forCountry(?string $code): Brand
    {
        return Brand::forCountry($code);
    }
}
