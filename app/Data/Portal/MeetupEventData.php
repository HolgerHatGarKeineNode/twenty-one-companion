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
