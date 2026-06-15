<?php

namespace App\Services;

use App\Http\Integrations\Portal\PortalConnector;
use App\Http\Integrations\Portal\Requests\AddMeetupToMineRequest;
use App\Http\Integrations\Portal\Requests\CreateCityRequest;
use App\Http\Integrations\Portal\Requests\CreateCourseEventRequest;
use App\Http\Integrations\Portal\Requests\CreateCourseRequest;
use App\Http\Integrations\Portal\Requests\CreateLecturerRequest;
use App\Http\Integrations\Portal\Requests\CreateMeetupEventRequest;
use App\Http\Integrations\Portal\Requests\CreateMeetupRequest;
use App\Http\Integrations\Portal\Requests\CreateVenueRequest;
use App\Http\Integrations\Portal\Requests\UpdateCityRequest;
use App\Http\Integrations\Portal\Requests\UpdateCourseEventRequest;
use App\Http\Integrations\Portal\Requests\UpdateCourseRequest;
use App\Http\Integrations\Portal\Requests\UpdateLecturerRequest;
use App\Http\Integrations\Portal\Requests\UpdateMeetupEventRequest;
use App\Http\Integrations\Portal\Requests\UpdateMeetupRequest;
use App\Http\Integrations\Portal\Requests\UpdateVenueRequest;
use App\Http\Integrations\Portal\Requests\UploadCourseLogoRequest;
use App\Http\Integrations\Portal\Requests\UploadLecturerAvatarRequest;
use App\Http\Integrations\Portal\Requests\UploadMeetupLogoRequest;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Request;
use Saloon\Http\Response;

/**
 * Schreibende Fassade über die Portal-API — das Gegenstück zur lesenden
 * {@see PortalApi}. Schickt authentifizierte POST/PATCH-Requests, übersetzt
 * jede Antwort in ein typisiertes {@see WriteResult} (Erfolg, Feldfehler,
 * Auth, Netzfehler) und invalidiert nach Erfolg die betroffenen Lese-Caches,
 * damit Listen den neuen Stand zeigen.
 *
 * Anders als beim Lesen werden Writes NICHT wiederholt (connector tries = 1):
 * ein wiederholter POST nach Server-/Validierungsfehler würde Duplikate
 * anlegen. Transiente Netzfehler fängt der Aufrufer über das WriteResult ab
 * (klare Fehlermeldung + manueller Retry; Offline-Outbox als Stretch).
 */
final class PortalWriter
{
    public function __construct(
        private readonly PortalConnector $connector,
        private readonly PortalAuth $portalAuth,
        private readonly PortalApi $portalApi,
    ) {
        // Writes nie automatisch wiederholen (Duplikat-Schutz für POST).
        $this->connector->tries = 1;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createMeetup(array $payload): WriteResult
    {
        return $this->send(new CreateMeetupRequest($payload), ['my-meetups', 'map-meetups']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateMeetup(int $id, array $payload): WriteResult
    {
        return $this->send(new UpdateMeetupRequest($id, $payload), ['my-meetups', 'map-meetups']);
    }

    /**
     * Fügt ein bestehendes Meetup zu „Meine Meetups" hinzu (Discovery-First:
     * statt Duplikate anzulegen, übernimmt der Nutzer ein vorhandenes Meetup).
     * Invalidiert die eigene Meetup-Liste, damit es sofort im „Meine"-Tab erscheint.
     */
    public function addMeetupToMine(string $slug): WriteResult
    {
        return $this->send(new AddMeetupToMineRequest($slug), ['my-meetups']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createMeetupEvent(array $payload): WriteResult
    {
        return $this->send(new CreateMeetupEventRequest($payload), ['meetup-events', 'map-meetups', 'my-meetup-events']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateMeetupEvent(int $id, array $payload): WriteResult
    {
        return $this->send(new UpdateMeetupEventRequest($id, $payload), ['meetup-events', 'map-meetups', 'my-meetup-events']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createVenue(array $payload): WriteResult
    {
        return $this->send(new CreateVenueRequest($payload), ['venues', 'my-venues']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateVenue(int $id, array $payload): WriteResult
    {
        return $this->send(new UpdateVenueRequest($id, $payload), ['venues', 'my-venues']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createCity(array $payload): WriteResult
    {
        return $this->send(new CreateCityRequest($payload), ['cities', 'my-cities']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateCity(int $id, array $payload): WriteResult
    {
        return $this->send(new UpdateCityRequest($id, $payload), ['cities', 'my-cities']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createLecturer(array $payload): WriteResult
    {
        return $this->send(new CreateLecturerRequest($payload), ['lecturers', 'my-lecturers']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateLecturer(int $id, array $payload): WriteResult
    {
        return $this->send(new UpdateLecturerRequest($id, $payload), ['lecturers', 'my-lecturers']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createCourse(array $payload): WriteResult
    {
        return $this->send(new CreateCourseRequest($payload), ['courses', 'my-courses']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateCourse(int $id, array $payload): WriteResult
    {
        return $this->send(new UpdateCourseRequest($id, $payload), ['courses', 'my-courses']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function createCourseEvent(array $payload): WriteResult
    {
        return $this->send(new CreateCourseEventRequest($payload), ['my-course-events', 'courses']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function updateCourseEvent(int $id, array $payload): WriteResult
    {
        return $this->send(new UpdateCourseEventRequest($id, $payload), ['my-course-events', 'courses']);
    }

    /**
     * Lädt ein Meetup-Logo hoch (multipart, Feld `file`). Zweistufig: das Meetup
     * existiert bereits (per create/update angelegt), das Bild kommt separat an
     * `…/{id}/logo`. Invalidiert die Meetup-Listen, damit das neue Logo erscheint.
     */
    public function uploadMeetupLogo(int $id, string $filePath): WriteResult
    {
        return $this->upload(new UploadMeetupLogoRequest($id, $filePath), $filePath, ['my-meetups', 'map-meetups']);
    }

    /**
     * Lädt einen Referenten-Avatar hoch (multipart, Feld `file`).
     */
    public function uploadLecturerAvatar(int $id, string $filePath): WriteResult
    {
        return $this->upload(new UploadLecturerAvatarRequest($id, $filePath), $filePath, ['my-lecturers', 'lecturers']);
    }

    /**
     * Lädt ein Kurs-Logo hoch (multipart, Feld `file`).
     */
    public function uploadCourseLogo(int $id, string $filePath): WriteResult
    {
        return $this->upload(new UploadCourseLogoRequest($id, $filePath), $filePath, ['my-courses', 'courses']);
    }

    /**
     * Gemeinsamer Upload-Pfad: prüft, dass die Datei lokal lesbar ist (der
     * Pfad stammt von der nativen Kamera/Galerie), bevor der multipart-Request
     * rausgeht — sonst ein klarer NetworkFailure statt einer Saloon-Exception.
     *
     * @param  list<string>  $invalidates
     */
    private function upload(Request $request, string $filePath, array $invalidates): WriteResult
    {
        if (! is_file($filePath) || ! is_readable($filePath)) {
            return WriteResult::networkFailure(__('Die gewählte Datei konnte nicht gelesen werden.'));
        }

        return $this->send($request, $invalidates);
    }

    /**
     * Schickt einen schreibenden Request und übersetzt die Antwort in ein
     * WriteResult. Bei Erfolg werden die übergebenen Lese-Endpunkte aus dem
     * frischen Cache verworfen (Stale-Kopie bleibt als Offline-Netz).
     *
     * @param  list<string>  $invalidates  Lese-Endpunkt-Keys, die nach Erfolg frisch geladen werden müssen.
     */
    private function send(Request $request, array $invalidates = []): WriteResult
    {
        if (! $this->portalAuth->hasToken()) {
            return WriteResult::unauthorized();
        }

        try {
            $response = $this->connector->send($request);
        } catch (FatalRequestException $exception) {
            return WriteResult::networkFailure($exception->getMessage());
        } catch (RequestException $exception) {
            // Falls throwOnMaxTries doch greift: die Antwort selbst auswerten.
            $response = $exception->getResponse();
        }

        return $this->interpret($response, $invalidates);
    }

    /**
     * @param  list<string>  $invalidates
     */
    private function interpret(Response $response, array $invalidates): WriteResult
    {
        if ($response->successful()) {
            foreach ($invalidates as $endpoint) {
                $this->portalApi->forget($endpoint);
            }

            // json() ohne Key liefert immer ein Array (leerer Body → []).
            return WriteResult::success($response->json());
        }

        $message = $this->messageFrom($response);

        return match ($response->status()) {
            422 => WriteResult::validationErrors($this->errorsFrom($response), $message),
            401 => WriteResult::unauthorized($message),
            403 => WriteResult::forbidden($message),
            default => WriteResult::networkFailure($message),
        };
    }

    /**
     * Laravel-Validierungsfehler im Standard-Format (Feld → Meldungen).
     *
     * @return array<string, list<string>>
     */
    private function errorsFrom(Response $response): array
    {
        $errors = $response->json('errors');

        return is_array($errors) ? $errors : [];
    }

    private function messageFrom(Response $response): ?string
    {
        $message = $response->json('message');

        return is_string($message) ? $message : null;
    }
}
