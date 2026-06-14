<?php

namespace App\Http\Integrations\Portal\Requests;

use Saloon\Enums\Method;

/**
 * POST /api/course-events — legt ein datiertes Kurs-Event an. Nur
 * authentifizierte Referenten (is_lecturer) dürfen anlegen (403 sonst).
 * `venue_id` ist Pflicht (anders als der Meetup-Termin, der nur Freitext
 * kennt), `to` muss >= `from` sein, `link` ist eine Pflicht-URL (Anmeldung).
 * Die Antwort ist das frische Kurs-Event-Modell (ohne data-Wrapper).
 *
 * Payload-Shape:
 * array{
 *   course_id: int,
 *   venue_id: int,
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
