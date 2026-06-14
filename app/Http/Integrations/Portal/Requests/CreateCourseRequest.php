<?php

namespace App\Http\Integrations\Portal\Requests;

use Saloon\Enums\Method;

/**
 * POST /api/courses — legt einen Kurs an. Nur authentifizierte Referenten
 * (is_lecturer) dürfen anlegen (403 sonst). `lecturer_id` ist die ID eines
 * beliebigen bestehenden Referenten. Die Antwort ist das frische Kurs-Modell
 * (ohne data-Wrapper).
 *
 * Payload-Shape:
 * array{
 *   name: string,
 *   lecturer_id: int,
 *   description?: ?string,
 * }
 */
class CreateCourseRequest extends PortalWriteRequest
{
    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/courses';
    }
}
