<?php

namespace App\Http\Integrations\Portal\Requests;

use App\Data\Portal\MeetupLeaderData;
use Illuminate\Support\Collection;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;

/**
 * GET /api/meetup/{id}/leaders — Leader eines eigenen Meetups (auth:sanctum).
 * Antwort ist eine Collection im data-Wrapper. Nur für Leader sichtbar (403).
 */
class GetMeetupLeadersRequest extends Request
{
    protected Method $method = Method::GET;

    public function __construct(private readonly int $meetupId) {}

    public function resolveEndpoint(): string
    {
        return "/meetup/{$this->meetupId}/leaders";
    }

    /**
     * @param  array<int|string, mixed>  $json
     * @return Collection<int, MeetupLeaderData>
     */
    public static function collectData(array $json): Collection
    {
        return MeetupLeaderData::collect($json, Collection::class);
    }

    /**
     * @return Collection<int, MeetupLeaderData>
     */
    public function createDtoFromResponse(Response $response): Collection
    {
        return static::collectData($response->json('data') ?? []);
    }
}
