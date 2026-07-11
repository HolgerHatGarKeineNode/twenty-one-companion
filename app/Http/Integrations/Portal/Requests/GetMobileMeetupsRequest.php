<?php

namespace App\Http\Integrations\Portal\Requests;

use App\Data\Portal\MobileMeetupData;
use App\Http\Integrations\Portal\Requests\Concerns\CollectsDataFromResponse;
use Illuminate\Support\Collection;
use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/mobile/meetups — schlanke, schnelle Meetup-Liste für die App.
 * Getrennt von GET /api/meetups (Website-Karte), um Regressionen bei anderen
 * Konsumenten zu vermeiden.
 */
class GetMobileMeetupsRequest extends Request
{
    /** @use CollectsDataFromResponse<MobileMeetupData> */
    use CollectsDataFromResponse;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/mobile/meetups';
    }

    /**
     * @param  array<int|string, mixed>  $json
     * @return Collection<int, MobileMeetupData>
     */
    public static function collectData(array $json): Collection
    {
        return MobileMeetupData::collect($json, Collection::class);
    }
}
