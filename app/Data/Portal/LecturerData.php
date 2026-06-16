<?php

namespace App\Data\Portal;

use Carbon\CarbonImmutable;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Optional;

/**
 * Referent aus GET /api/lecturers (öffentliche Picker-Liste:
 * id, name und Avatar-Thumbnail). subtitle, future_events_count und
 * next_event liefert das Portal nur mit dem Presence-Flag withDetails;
 * in der Kurs-Detail-Antwort fehlen sie — daher Optional. next_event ist
 * auch mit withDetails null, wenn keine kommenden Kurs-Events existieren.
 */
final class LecturerData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public string $image,
        public string|Optional|null $subtitle,
        public int|Optional $future_events_count,
        public CarbonImmutable|Optional|null $next_event,
    ) {}

    public function subtitleOrNull(): ?string
    {
        return $this->subtitle instanceof Optional ? null : $this->subtitle;
    }

    public function futureEventsCount(): int
    {
        return $this->future_events_count instanceof Optional ? 0 : $this->future_events_count;
    }

    public function nextEvent(): ?CarbonImmutable
    {
        return $this->next_event instanceof Optional ? null : $this->next_event;
    }
}
