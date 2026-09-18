<?php

namespace App\Data\Portal;

use App\Data\Portal\Concerns\RendersMarkdown;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;

/**
 * Meetup-Termin aus GET /api/meetup-events/{date?}. start kommt im
 * Format "Y-m-d H:i" (siehe config/data.php date_format).
 */
final class MeetupEventData extends Data
{
    use RendersMarkdown;

    public function __construct(
        public CarbonImmutable $start,
        public ?string $location,
        public ?string $description,
        public ?string $link,
        public EventMeetupData $meetup,
        // id/Zähler erst seit dem RSVP-Feature in der API. Nullable/Default,
        // damit vor dem Update gecachte (Stale-)Antworten ohne diese Felder
        // weiterhin sauber gemappt werden — ohne id zeigt der Slide-In dann
        // einfach keine RSVP-Buttons.
        public ?int $id = null,
        // null = Teilnehmerzahl öffentlich verborgen (attendees_public=false).
        public ?int $attendees = null,
        public ?int $might_attendees = null,
        /**
         * The NIP-01 address of the kind 31923 the Portal published for this date — or
         * null (written by the Portal in P1, read here since P5).
         *
         * Nullable and with a default, like the three fields above it: a (stale) response
         * cached before the Portal's deploy does not carry the key, and such a response is
         * meant to keep rendering the date list offline. Without an address the RSVP
         * surface shows its REST arm — exactly the case D12 keeps that path for.
         */
        public ?string $nostr_address = null,
    ) {}

    /**
     * Die API liefert die Meetup-Infos als literale Schlüssel mit Punkt
     * ("meetup.name", "meetup.city", …) auf oberster Ebene. Hier werden
     * sie in ein verschachteltes meetup-Array umgebaut, damit sie als
     * EventMeetupData gemappt werden können.
     *
     * @param  array<string, mixed>  $properties
     * @return array<string, mixed>
     */
    public static function prepareForPipeline(array $properties): array
    {
        foreach ($properties as $key => $value) {
            if (str_starts_with($key, 'meetup.')) {
                $properties['meetup'][substr($key, strlen('meetup.'))] = $value;
                unset($properties[$key]);
            }
        }

        return $properties;
    }

    public function descriptionHtml(): ?string
    {
        return $this->markdownToHtml($this->description);
    }
}
