<?php

namespace App\Http\Integrations\Portal\Requests;

/**
 * POST /api/courses/{id}/logo — lädt ein Kurs-Logo (Feld `file`, multipart) in
 * die singleFile-Collection „logo". Nur für den Ersteller/Super-Admin
 * (Portal-Policy). Antwort: CourseResource (data-Wrapper).
 */
class UploadCourseLogoRequest extends UploadMediaRequest
{
    public function resolveEndpoint(): string
    {
        return "/courses/{$this->id}/logo";
    }
}
