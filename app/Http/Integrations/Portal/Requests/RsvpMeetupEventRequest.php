<?php

namespace App\Http\Integrations\Portal\Requests;

use Saloon\Enums\Method;

/**
 * POST /api/meetup-events/{id}/rsvp — sagt für einen Termin zu („attending"),
 * vielleicht („maybe") oder ab („none", trägt aus). Der Anzeigename wird
 * serverseitig aus dem Profil übernommen; der Body trägt nur den Status.
 * Idempotent: derselbe Status mehrfach verändert nichts.
 *
 * Payload-Shape: array{ status: string }
 */
class RsvpMeetupEventRequest extends PortalWriteRequest
{
    protected Method $method = Method::POST;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(private readonly int $id, array $payload)
    {
        parent::__construct($payload);
    }

    public function resolveEndpoint(): string
    {
        return "/meetup-events/{$this->id}/rsvp";
    }
}
