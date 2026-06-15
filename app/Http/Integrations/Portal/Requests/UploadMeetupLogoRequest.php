<?php

namespace App\Http\Integrations\Portal\Requests;

/**
 * POST /api/meetup/{id}/logo — lädt ein Meetup-Logo (Feld `file`, multipart) in
 * die singleFile-Collection „logo" und ersetzt ein vorhandenes. Nur für den
 * Ersteller/Super-Admin (Portal-Policy). Antwort: MeetupResource (data-Wrapper).
 */
class UploadMeetupLogoRequest extends UploadMediaRequest
{
    public function resolveEndpoint(): string
    {
        return "/meetup/{$this->id}/logo";
    }
}
