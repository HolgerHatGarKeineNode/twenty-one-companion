<?php

namespace App\Http\Integrations\Portal\Requests;

use App\Data\Portal\MeetupEventRsvpData;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;

/**
 * GET /api/meetup-events/{id}/rsvp — eigener RSVP-Status (auth:sanctum) plus
 * aktuelle Zähler. Nutzerspezifisch und damit ungecacht (siehe PortalApi).
 */
class GetMeetupEventRsvpRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly int $id) {}

    public function resolveEndpoint(): string
    {
        return "/meetup-events/{$this->id}/rsvp";
    }

    public function createDtoFromResponse(Response $response): MeetupEventRsvpData
    {
        return MeetupEventRsvpData::from($response->json());
    }
}
