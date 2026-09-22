<?php

namespace App\Data\Portal;

use Spatie\LaravelData\Data;

/**
 * The city of a course event: only `id` and `name` are certain. The portal's own list
 * (GET /api/course-events) loads `city:id,name` and sends nothing else, and the course
 * detail may send a city whose country is null — both would break the stricter
 * {@see CityData}, whose country is required.
 */
final class CityRefData extends Data
{
    public function __construct(
        public int $id,
        public string $name,
    ) {}
}
