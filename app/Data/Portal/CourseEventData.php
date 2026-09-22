<?php

namespace App\Data\Portal;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Kurs-Event aus GET /api/course-events (eigene Kurs-Events des
 * angemeldeten Referenten, inkl. Kurs- und Venue-Kurzinfo) sowie aus den
 * events der Kurs-Detail-Antwort GET /api/courses/{id} — dort fehlen
 * created_by/created_at/updated_at, daher die null-Defaults.
 *
 * The portal removed its venue model (einundzwanzig-portal 5aba6dc): an event names its
 * place as free-text `location` plus its `city`. No `venue_id`, no `venue` — a required
 * `venue_id` made every course page with a date a 500 (device sighting v1.13.0).
 */
final class CourseEventData extends Data
{
    public function __construct(
        public int $id,
        public int $course_id,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?string $link,
        public ?int $city_id = null,
        public ?int $created_by = null,
        public ?CarbonImmutable $created_at = null,
        public ?CarbonImmutable $updated_at = null,
        public ?CourseData $course = null,
        public ?string $location = null,
        public ?CityRefData $city = null,
    ) {}

    /**
     * The place as „location · city", as much of it as the portal sends; null without
     * either.
     */
    public function locationLabel(): ?string
    {
        $parts = array_values(array_filter(
            [$this->location === null ? null : trim($this->location), $this->city?->name],
            fn (?string $part): bool => $part !== null && $part !== '',
        ));

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
