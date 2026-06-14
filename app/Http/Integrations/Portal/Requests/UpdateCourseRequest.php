<?php

namespace App\Http\Integrations\Portal\Requests;

use Saloon\Enums\Method;

/**
 * PATCH /api/courses/{id} — aktualisiert einen eigenen Kurs. Teil-Payload
 * genügt („sometimes"). Nur Ersteller/Super-Admin (403).
 *
 * Payload-Shape: wie CreateCourseRequest, alle Felder optional.
 */
class UpdateCourseRequest extends PortalWriteRequest
{
    protected Method $method = Method::PATCH;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly int $id, array $payload)
    {
        parent::__construct($payload);
    }

    public function resolveEndpoint(): string
    {
        return "/courses/{$this->id}";
    }
}
