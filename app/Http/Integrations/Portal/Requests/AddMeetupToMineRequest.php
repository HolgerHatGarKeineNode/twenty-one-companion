<?php

namespace App\Http\Integrations\Portal\Requests;

use Saloon\Enums\Method;

/**
 * POST /api/my-meetups/{slug} — fügt ein bereits bestehendes Meetup zu den
 * „Meine Meetups" des Token-Inhabers hinzu (meetup_user-Pivot als Mitglied,
 * is_leader=false). Idempotent: ein bereits hinzugefügtes Meetup liefert 200
 * statt 201, die Stammdaten bleiben dem Ersteller vorbehalten.
 *
 * Per Slug gebunden, weil die gecachte Karten-Liste (MapMeetupData) keine
 * numerische ID exponiert, wohl aber den Slug. Body-frei — der Slug steckt
 * im Endpoint.
 */
class AddMeetupToMineRequest extends PortalWriteRequest
{
    protected Method $method = Method::POST;

    public function __construct(private readonly string $slug)
    {
        parent::__construct([]);
    }

    public function resolveEndpoint(): string
    {
        return "/my-meetups/{$this->slug}";
    }
}
