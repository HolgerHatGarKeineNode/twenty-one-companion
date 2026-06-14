<?php

namespace App\Http\Integrations\Portal\Requests;

use App\Data\Portal\MyLecturerData;
use Illuminate\Support\Collection;
use Saloon\Enums\Method;
use Saloon\Http\Request;
use Saloon\Http\Response;

/**
 * GET /api/my-lecturers — vom Nutzer ERSTELLTE Referenten-Profile
 * (auth:sanctum). Die Antwort ist eine LecturerResource-Collection mit
 * data-Wrapper und trägt — anders als die öffentliche {@see GetLecturersRequest}
 * — alle Felder, die der Editor zum Bearbeiten braucht (subtitle/intro/
 * description/Links).
 */
class GetMyLecturersRequest extends Request
{
    protected Method $method = Method::GET;

    public function resolveEndpoint(): string
    {
        return '/my-lecturers';
    }

    /**
     * @param  array<int|string, mixed>  $json
     * @return Collection<int, MyLecturerData>
     */
    public static function collectData(array $json): Collection
    {
        return MyLecturerData::collect($json, Collection::class);
    }

    /**
     * @return Collection<int, MyLecturerData>
     */
    public function createDtoFromResponse(Response $response): Collection
    {
        return static::collectData($response->json('data') ?? []);
    }
}
