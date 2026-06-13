<?php

namespace App\Data\Portal;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Vollständiges Meetup aus der MeetupResource des Portals
 * (GET /api/my-meetups, dort in einen data-Wrapper verpackt).
 */
final class MeetupData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $slug,
        public int $city_id,
        public ?string $intro,
        public ?string $telegram_link,
        public ?string $webpage,
        public ?string $twitter_username,
        public ?string $matrix_group,
        public ?string $nostr,
        public ?string $simplex,
        public ?string $signal,
        public ?string $community,
        public bool $visible_on_map,
        public bool $is_active,
        public ?CarbonImmutable $last_event_at,
        public ?int $created_by,
        public ?CarbonImmutable $created_at,
        public ?CarbonImmutable $updated_at,
        public ?string $logo = null,
    ) {}
}
