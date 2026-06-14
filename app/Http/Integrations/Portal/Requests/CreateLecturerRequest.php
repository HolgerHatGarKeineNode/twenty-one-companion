<?php

namespace App\Http\Integrations\Portal\Requests;

use Saloon\Enums\Method;

/**
 * POST /api/lecturers — legt ein Referenten-Profil an. Jeder authentifizierte
 * Nutzer darf anlegen; der Ersteller (created_by) wird serverseitig gesetzt.
 * Die Antwort ist eine LecturerResource im data-Wrapper.
 *
 * Payload-Shape:
 * array{
 *   name: string,
 *   subtitle?: ?string,
 *   intro?: ?string,
 *   description?: ?string,
 *   active?: bool,
 *   website?: ?string,
 *   twitter_username?: ?string,
 *   nostr?: ?string,
 *   lightning_address?: ?string,
 * }
 */
class CreateLecturerRequest extends PortalWriteRequest
{
    protected Method $method = Method::POST;

    public function resolveEndpoint(): string
    {
        return '/lecturers';
    }
}
