<?php

namespace App\Data\Portal;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Kurs-Event aus GET /api/course-events (eigene Kurs-Events des
 * angemeldeten Referenten, inkl. Kurs- und Venue-Kurzinfo) sowie aus den
 * events der Kurs-Detail-Antwort GET /api/courses/{id} — dort fehlen
 * created_by/created_at/updated_at, daher die null-Defaults.
 *
 * The course detail no longer carries a venue at all (measured against the
 * live portal on 2026-09-22, courses 6, 36, 38, 44–47): an event names its
 * place as free-text `location` plus a nested `city`. `venue_id` is therefore
 * nullable, and a required one made every course page with a date a 500.
 */
final class CourseEventData extends Data
{
    public function __construct(
        public int $id,
        public int $course_id,
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public ?string $link,
        public ?int $venue_id = null,
        public ?int $city_id = null,
        public ?int $created_by = null,
        public ?CarbonImmutable $created_at = null,
        public ?CarbonImmutable $updated_at = null,
        public ?CourseData $course = null,
        public ?VenueData $venue = null,
        public ?string $location = null,
        public ?CityRefData $city = null,
    ) {}

    /**
     * Ort als „Venue · Stadt“, je nachdem wie viel die API mitliefert.
     * Without a venue, the event's own `location` and `city` stand in.
     */
    public function locationLabel(): ?string
    {
        if ($this->venue === null) {
            return $this->joinLabel($this->location, $this->city?->name);
        }

        $city = $this->venue->city instanceof Optional ? null : $this->venue->city;

        return $city === null ? $this->venue->name : $this->venue->name.' · '.$city->name;
    }

    private function joinLabel(?string $place, ?string $cityName): ?string
    {
        $parts = array_values(array_filter(
            [$place === null ? null : trim($place), $cityName],
            fn (?string $part): bool => $part !== null && $part !== '',
        ));

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
