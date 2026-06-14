<?php

namespace App\Http\Integrations\Portal\Requests;

use Saloon\Enums\Method;

/**
 * PATCH /api/lecturers/{id} — aktualisiert ein eigenes Referenten-Profil.
 * Teil-Payload genügt („sometimes"). Nur Ersteller/Super-Admin (403).
 *
 * Payload-Shape: wie CreateLecturerRequest, alle Felder optional.
 */
class UpdateLecturerRequest extends PortalWriteRequest
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
        return "/lecturers/{$this->id}";
    }
}
