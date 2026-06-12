<?php

namespace App\Data\Portal;

use App\Data\Portal\Concerns\HasPortalLink;
use Spatie\LaravelData\Data;

/**
 * Meetup-Kurzinfo eines Termins aus GET /api/meetup-events. Die API
 * liefert die Felder als literale "meetup.*"-Schlüssel auf oberster
 * Ebene; MeetupEventData::prepareForPipeline() schachtelt sie hierher um.
 */
final class EventMeetupData extends Data
{
    use HasPortalLink;

    public function __construct(
        public string $name,
        public string $portalLink,
        public ?string $url,
        public string $country,
        public string $city,
        public float $longitude,
        public float $latitude,
        public ?string $twitter_username,
        public ?string $website,
        public ?string $simplex,
        public ?string $signal,
        public ?string $nostr,
        public ?string $logo,
    ) {}
}
