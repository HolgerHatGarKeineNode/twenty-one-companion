<?php

namespace App\Http\Integrations\Portal\Requests;

use Saloon\Enums\Method;

/**
 * POST /api/meetup/{id}/leaders — setzt einen weiteren Leader per npub ein.
 * Body: { npub }. Nur Leader dürfen das (403); ungültiger npub → 422.
 */
class AddMeetupLeaderRequest extends PortalWriteRequest
{
    protected Method $method = Method::POST;

    public function __construct(private readonly int $meetupId, string $npub)
    {
        parent::__construct(['npub' => $npub]);
    }

    public function resolveEndpoint(): string
    {
        return "/meetup/{$this->meetupId}/leaders";
    }
}
