<?php

namespace App\Http\Integrations\Portal\Requests;

use Saloon\Enums\Method;

/**
 * PATCH /api/user — ändert den eigenen Anzeigenamen. Rollen
 * (is_lecturer/is_leader) sind serverseitig nicht änderbar; der Body
 * trägt nur `name`. Die Antwort ist das frische Profil (gleiche Shape
 * wie GET /api/user).
 *
 * Payload-Shape: array{ name: string }
 */
class UpdateUserProfileRequest extends PortalWriteRequest
{
    protected Method $method = Method::PATCH;

    public function resolveEndpoint(): string
    {
        return '/user';
    }
}
