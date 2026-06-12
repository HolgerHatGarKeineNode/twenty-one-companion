<?php

namespace App\Data\Portal;

use App\Data\Portal\Concerns\RendersMarkdown;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Nächster Termin eines Meetups, verschachtelt in GET /api/meetups
 * (next_event aus dem nextEvent-Attribut des Portal-Meetup-Models).
 */
final class NextEventData extends Data
{
    use RendersMarkdown;

    public function __construct(
        public int $id,
        public CarbonImmutable $start,
        public string $portalLink,
        public ?string $location,
        public ?string $description,
        public ?string $link,
        public int $attendees,
        public int $might_attendees,
        public ?string $nostr_note,
    ) {}

    public function descriptionHtml(): ?string
    {
        return $this->markdownToHtml($this->description);
    }
}
