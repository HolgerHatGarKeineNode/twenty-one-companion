<?php

namespace App\Http\Integrations\Portal\Requests;

use Saloon\Enums\Method;

/**
 * DELETE /api/meetup/{id}/leaders/{userId} — entzieht einem Nutzer die
 * Leader-Rolle (Demote; bleibt Mitglied). Body-frei. Nur Leader dürfen das
 * (403); der Ersteller des Meetups ist serverseitig geschützt (403).
 */
class RemoveMeetupLeaderRequest extends PortalWriteRequest
{
    protected Method $method = Method::DELETE;

    public function __construct(private readonly int $meetupId, private readonly int $userId)
    {
        parent::__construct([]);
    }

    public function resolveEndpoint(): string
    {
        return "/meetup/{$this->meetupId}/leaders/{$this->userId}";
    }
}
