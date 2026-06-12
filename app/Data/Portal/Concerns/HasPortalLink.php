<?php

namespace App\Data\Portal\Concerns;

use Illuminate\Support\Str;

/**
 * Leitet den Meetup-Slug aus dem portalLink ab. Die Karten- und
 * Event-Endpunkte liefern keinen eigenen slug, aber der portalLink
 * endet immer auf das letzte Pfadsegment /meetup/{slug}.
 */
trait HasPortalLink
{
    private ?string $memoizedSlug = null;

    public function slug(): string
    {
        if ($this->memoizedSlug === null) {
            $path = rtrim((string) parse_url($this->portalLink, PHP_URL_PATH), '/');

            $this->memoizedSlug = Str::afterLast($path, '/');
        }

        return $this->memoizedSlug;
    }
}
