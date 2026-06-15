<?php

namespace App\Http\Integrations\Portal\Requests;

use Saloon\Enums\Method;

/**
 * DELETE /api/my-meetups/{slug} — entfernt ein Meetup wieder aus den
 * „Meine Meetups" des Token-Inhabers (löst die meetup_user-Pivot). Die
 * Stammdaten bleiben erhalten — Gegenstück zu {@see AddMeetupToMineRequest}.
 * Idempotent: ein nicht (mehr) zugeordnetes Meetup liefert ebenfalls 200.
 *
 * Per Slug gebunden, konsistent mit dem Hinzufügen. Body-frei — der Slug
 * steckt im Endpoint.
 */
class RemoveMeetupFromMineRequest extends PortalWriteRequest
{
    protected Method $method = Method::DELETE;

    public function __construct(private readonly string $slug)
    {
        parent::__construct([]);
    }

    public function resolveEndpoint(): string
    {
        return "/my-meetups/{$this->slug}";
    }
}
