<?php

namespace App\Data\Portal;

use App\Enums\RsvpStatus;
use Spatie\LaravelData\Data;

/**
 * RSVP-Antwort des Portals für einen Meetup-Termin: der eigene Status des
 * Token-Inhabers plus die aktuellen Zähler. Antwort von GET/POST
 * /api/meetup-events/{id}/rsvp.
 */
final class MeetupEventRsvpData extends Data
{
    public function __construct(
        public RsvpStatus $status,
        // null = Teilnehmerzahl für den Betrachter verborgen (nicht öffentlich, kein Leader).
        public ?int $attendees,
        public ?int $might_attendees,
    ) {}
}
