<?php

namespace App\Data\Portal;

use Spatie\LaravelData\Data;

/**
 * Eigener Referent aus GET /api/my-lecturers (LecturerResource, flache
 * Schreib-/Eigentums-Sicht im data-Wrapper). Anders als das öffentliche
 * {@see LecturerData} (nur id/name/image für die Picker-Liste) und das
 * {@see LecturerDetailData} (Profil-Lese-Sicht inkl. Kurse) trägt diese Sicht
 * genau die editierbaren Felder, die der Referenten-Editor zum Bearbeiten
 * lädt — inkl. der rohen Markdown-Texte (intro/description), die das Portal
 * hier unverändert ausliefert.
 */
final class MyLecturerData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $subtitle,
        public ?string $intro,
        public ?string $description,
        public bool $active,
        public ?string $website,
        public ?string $twitter_username,
        public ?string $nostr,
        public ?string $lightning_address,
        public ?string $avatar = null,
    ) {}
}
