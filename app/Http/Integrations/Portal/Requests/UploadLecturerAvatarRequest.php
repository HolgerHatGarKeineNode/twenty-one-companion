<?php

namespace App\Http\Integrations\Portal\Requests;

/**
 * POST /api/lecturers/{id}/avatar — lädt einen Referenten-Avatar (Feld `file`,
 * multipart) in die singleFile-Collection „avatar". Nur für den Ersteller/
 * Super-Admin (Portal-Policy). Antwort: LecturerResource (data-Wrapper).
 */
class UploadLecturerAvatarRequest extends UploadMediaRequest
{
    public function resolveEndpoint(): string
    {
        return "/lecturers/{$this->id}/avatar";
    }
}
