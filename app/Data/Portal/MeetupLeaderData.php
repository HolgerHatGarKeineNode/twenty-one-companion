<?php

namespace App\Data\Portal;

use Spatie\LaravelData\Data;

/**
 * Ein Leader eines Meetups aus GET /api/meetup/{id}/leaders (data-Wrapper).
 * `is_creator` markiert den Meetup-Ersteller, der nie entzogen werden kann.
 */
final class MeetupLeaderData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $nostr,
        public ?string $avatar,
        public bool $is_creator,
    ) {}
}
