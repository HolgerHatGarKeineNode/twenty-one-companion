<?php

namespace App\Http\Integrations\Portal\Requests;

use App\Data\Portal\BtcMapCommunityData;
use App\Http\Integrations\Portal\Requests\Concerns\CollectsDataFromResponse;
use Illuminate\Support\Collection;
use Saloon\Enums\Method;
use Saloon\Http\Request;

/**
 * GET /api/btc-map-communities — EINUNDZWANZIG-Communities im
 * BTC-Map-Format (GeoJSON-Tags).
 */
class GetBtcMapCommunitiesRequest extends Request
{
    /** @use CollectsDataFromResponse<BtcMapCommunityData> */
    use CollectsDataFromResponse;

    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/btc-map-communities';
    }

    /**
     * @param  array<int|string, mixed>  $json
     * @return Collection<int, BtcMapCommunityData>
     */
    public static function collectData(array $json): Collection
    {
        return BtcMapCommunityData::collect($json, Collection::class);
    }
}
