<?php

namespace App\Data\Portal;

use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Veranstaltungsort aus GET /api/venues. Verschachtelt in Kurs-Events
 * (GET /api/course-events) liefert das Portal nur id und name, daher
 * sind die übrigen Felder Optional.
 */
final class VenueData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public int|Optional $city_id,
        public string|Optional $flag,
        public string|Optional $description,
        public CityData|Optional $city,
    ) {}

    /**
     * Ländercode (lowercase) der Stadt für den Regionsfilter; null, wenn
     * die Stadt (course-events liefern nur id/name) oder ihr Ländercode fehlt.
     */
    public function countryCode(): ?string
    {
        return $this->city instanceof CityData ? $this->city->countryCode() : null;
    }

    /**
     * description („Stadt, Straße") ohne den hängenden Trenner, den das
     * Portal bei leerer Straße liefert; null wenn nichts übrig bleibt.
     */
    public function locationLabel(): ?string
    {
        if (! is_string($this->description)) {
            return null;
        }

        $label = trim($this->description, ' ,');

        return $label === '' ? null : $label;
    }
}
