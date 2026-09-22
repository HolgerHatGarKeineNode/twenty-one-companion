<?php

namespace App\Http\Integrations\Portal\Requests;

use Saloon\Enums\Method;

/**
 * POST /api/course-events — creates a dated course event. The contract is the portal's
 * `StoreCourseEventRequest` (einundzwanzig-portal, since the venue model was removed in
 * 5aba6dc): `city_id` is required, the place is free text in `location` (optional), the
 * `osm_*` fields are an optional map pin this client does not send; `to` must be at or
 * after `from`, and `link` is a required URL (registration). A `venue_id` is no field of
 * that contract any more. The answer is the fresh course event (CourseEventResource).
 *
 * Payload-Shape:
 * array{
 *   course_id: int,
 *   city_id: int,
 *   location: string|null,
 *   from: string,
 *   to: string,
 *   link: string,
 * }
 */
class CreateCourseEventRequest extends PortalWriteRequest
{
    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/course-events';
    }
}
