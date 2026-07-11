<?php

namespace App\Data\Portal;

use App\Data\Portal\Concerns\RendersMarkdown;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Kurs aus GET /api/courses. Verschachtelt in Kurs-Events
 * (GET /api/course-events) fehlt das image, daher Optional.
 * description, lecturer und next_event liefert das Portal nur mit dem
 * Presence-Flag withDetails (bzw. in den Kursen eines Referenten-Profils
 * ohne description/lecturer), daher ebenfalls Optional.
 */
final class CourseData extends Data
{
    use RendersMarkdown;

    public function __construct(
        public int $id,
        public string $name,
        public string|Optional $image,
        public string|Optional|null $description,
        public LecturerData|Optional|null $lecturer,
        public CarbonImmutable|Optional|null $next_event,
    ) {}

    public function imageOrNull(): ?string
    {
        $image = $this->image instanceof Optional ? '' : (string) $this->image;

        // Portal liefert für Kurse OHNE eigenes Bild die Default-URL
        // /img/einundzwanzig.png (existiert nicht → 404). Als „kein Bild" behandeln,
        // damit die Avatar-Komponente ihren Initialen-Fallback statt eines kaputten
        // Bildes zeigt.
        if ($image === '' || str_contains($image, '/img/einundzwanzig')) {
            return null;
        }

        return $image;
    }

    public function descriptionHtml(): ?string
    {
        return $this->markdownToHtml($this->description instanceof Optional ? null : $this->description);
    }

    public function nextEvent(): ?CarbonImmutable
    {
        return $this->next_event instanceof Optional ? null : $this->next_event;
    }

    public function lecturerOrNull(): ?LecturerData
    {
        return $this->lecturer instanceof Optional ? null : $this->lecturer;
    }
}
