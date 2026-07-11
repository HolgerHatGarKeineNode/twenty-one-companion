<?php

namespace App\Data\Portal;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Schlankes Meetup aus GET /api/mobile/meetups — der schnelle, eigens für die
 * App gebaute Endpunkt (kein Intro/Socials/RSVP, eine Query statt N+1). Deckt
 * die App-Liste, die App-Karte und den Länderfilter ab.
 *
 * Das Detail (Intro, Links, RSVP) lädt weiterhin über {@see MapMeetupData}
 * (GET /api/meetups), das die vollen Felder liefert.
 */
final class MobileMeetupData extends Data
{
    public function __construct(
        public string $name,
        public string $slug,
        public string $city,
        public string $country,
        public float $latitude,
        public float $longitude,
        public ?string $logo,
        // Start des nächsten Termins („Y-m-d H:i"), null wenn keiner ansteht.
        public ?CarbonImmutable $next_event_start,
    ) {}

    /** Ländercode (lowercase) für den Regionsfilter. */
    public function countryCode(): string
    {
        return mb_strtolower($this->country);
    }
}
