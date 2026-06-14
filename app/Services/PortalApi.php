<?php

namespace App\Services;

use App\Data\Portal\BtcMapCommunityData;
use App\Data\Portal\CityData;
use App\Data\Portal\CountryData;
use App\Data\Portal\CourseData;
use App\Data\Portal\CourseDetailData;
use App\Data\Portal\CourseEventData;
use App\Data\Portal\LecturerData;
use App\Data\Portal\LecturerDetailData;
use App\Data\Portal\MapMeetupData;
use App\Data\Portal\MeetupData;
use App\Data\Portal\MeetupEventData;
use App\Data\Portal\MemberMeetupData;
use App\Data\Portal\MyCityData;
use App\Data\Portal\MyLecturerData;
use App\Data\Portal\MyMeetupEventData;
use App\Data\Portal\MyVenueData;
use App\Data\Portal\UserProfileData;
use App\Data\Portal\VenueData;
use App\Http\Integrations\Portal\PortalConnector;
use App\Http\Integrations\Portal\Requests\GetBtcMapCommunitiesRequest;
use App\Http\Integrations\Portal\Requests\GetCitiesRequest;
use App\Http\Integrations\Portal\Requests\GetCountriesRequest;
use App\Http\Integrations\Portal\Requests\GetCourseRequest;
use App\Http\Integrations\Portal\Requests\GetCoursesRequest;
use App\Http\Integrations\Portal\Requests\GetLecturerRequest;
use App\Http\Integrations\Portal\Requests\GetLecturersRequest;
use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMeetupEventsRequest;
use App\Http\Integrations\Portal\Requests\GetMemberMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMyCitiesRequest;
use App\Http\Integrations\Portal\Requests\GetMyCourseEventsRequest;
use App\Http\Integrations\Portal\Requests\GetMyLecturersRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupEventsRequest;
use App\Http\Integrations\Portal\Requests\GetMyMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMyVenuesRequest;
use App\Http\Integrations\Portal\Requests\GetUserRequest;
use App\Http\Integrations\Portal\Requests\GetVenuesRequest;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Native\Mobile\Facades\Network;
use Saloon\Exceptions\Request\FatalRequestException;
use Saloon\Exceptions\Request\RequestException;
use Saloon\Http\Request;
use Saloon\Http\Response;

/**
 * Lesende Fassade über die Portal-API: schickt Saloon-Requests und cached
 * die rohen JSON-Antworten zweistufig (frisch mit TTL + dauerhafte
 * Stale-Kopie), damit die App offline die zuletzt geladenen Daten zeigt.
 * DTO-Mapping passiert erst beim Lesen, damit Schema-Änderungen keinen
 * kaputt serialisierten Cache hinterlassen.
 */
final class PortalApi
{
    private const CACHE_PREFIX = 'portal_api:';

    private const STALE_SUFFIX = ':stale';

    /** Stammdaten (Meetups, Kurse, Orte, Länder): 1 Tag. */
    public const TTL_STATIC_SECONDS = 86400;

    /** Termine: 1 Stunde. */
    public const TTL_EVENTS_SECONDS = 3600;

    /** Eigene Daten (auth): 15 Minuten. */
    public const TTL_MINE_SECONDS = 900;

    private ?bool $online = null;

    /** Mindestens ein Aufruf dieses Renders wurde aus der Stale-Kopie bedient. */
    private bool $servedStale = false;

    /** Mindestens ein Aufruf dieses Renders blieb ohne Daten (kein Netz/Fehler UND keine Stale-Kopie). */
    private bool $missingData = false;

    public function __construct(
        private readonly PortalConnector $connector,
        private readonly PortalAuth $portalAuth,
    ) {}

    /**
     * Hat dieser Render mindestens einmal auf die dauerhafte Stale-Kopie
     * zurückgegriffen? Die Seiten zeigen dann den „nicht aktuell“-Hinweis.
     */
    public function servedStaleData(): bool
    {
        return $this->servedStale;
    }

    /**
     * Blieb dieser Render mindestens einmal komplett ohne Daten (Abruf
     * fehlgeschlagen und keine Stale-Kopie)? Die Seiten zeigen dann den
     * Fehler-State mit „Erneut versuchen“ statt eines leeren Ergebnisses.
     */
    public function hasMissingData(): bool
    {
        return $this->missingData;
    }

    public function isOffline(): bool
    {
        return ! $this->isOnline();
    }

    /**
     * Status-Flags und Online-Memo auf Anfangswerte zurücksetzen — wird von
     * PortalPage::boot() pro Livewire-Request aufgerufen, damit die scoped
     * Instanz nie Status aus einem vorigen Render mitschleppt (relevant in
     * Tests, in denen der Container nicht pro Interaktion geflusht wird)
     * und Verbindungswechsel zwischen Updates erkannt werden.
     */
    public function resetStatus(): void
    {
        $this->online = null;
        $this->servedStale = false;
        $this->missingData = false;
    }

    /**
     * @return Collection<int, MapMeetupData>
     */
    public function mapMeetups(bool $withIntro = false, bool $withLogos = false): Collection
    {
        $json = $this->remember(
            'map-meetups',
            [$withIntro, $withLogos],
            self::TTL_STATIC_SECONDS,
            new GetMapMeetupsRequest($withIntro, $withLogos),
        );

        return GetMapMeetupsRequest::collectData($json ?? []);
    }

    /**
     * @return Collection<int, MeetupEventData>
     */
    public function meetupEvents(?string $date = null): Collection
    {
        $json = $this->remember(
            'meetup-events',
            [$date],
            self::TTL_EVENTS_SECONDS,
            new GetMeetupEventsRequest($date),
        );

        return GetMeetupEventsRequest::collectData($json ?? []);
    }

    /**
     * @return Collection<int, CourseData>
     */
    public function courses(?string $search = null, ?int $userId = null, bool $withDetails = false): Collection
    {
        $json = $this->remember(
            'courses',
            [$search, $userId, $withDetails],
            self::TTL_STATIC_SECONDS,
            new GetCoursesRequest($search, $userId, withDetails: $withDetails),
        );

        return GetCoursesRequest::collectData($json ?? []);
    }

    /**
     * Kurs-Detail inkl. kommender Kurs-Events; null wenn unbekannt oder
     * offline ohne Stale-Kopie. Kürzere TTL als Stammdaten, weil die
     * Termine im Detail aktuell bleiben sollen.
     */
    public function course(int $id): ?CourseDetailData
    {
        $json = $this->remember(
            'course',
            [$id],
            self::TTL_EVENTS_SECONDS,
            new GetCourseRequest($id),
        );

        return $json === null ? null : CourseDetailData::from($json);
    }

    /**
     * @return Collection<int, LecturerData>
     */
    public function lecturers(?string $search = null, bool $withDetails = false): Collection
    {
        $json = $this->remember(
            'lecturers',
            [$search, $withDetails],
            self::TTL_STATIC_SECONDS,
            new GetLecturersRequest($search, withDetails: $withDetails),
        );

        return GetLecturersRequest::collectData($json ?? []);
    }

    /**
     * Referenten-Profil inkl. seiner Kurse; null wenn unbekannt oder
     * offline ohne Stale-Kopie. Kürzere TTL wegen next_event der Kurse.
     */
    public function lecturer(int $id): ?LecturerDetailData
    {
        $json = $this->remember(
            'lecturer',
            [$id],
            self::TTL_EVENTS_SECONDS,
            new GetLecturerRequest($id),
        );

        return $json === null ? null : LecturerDetailData::from($json);
    }

    /**
     * @return Collection<int, CityData>
     */
    public function cities(?string $search = null, bool $withDetails = false): Collection
    {
        $json = $this->remember(
            'cities',
            [$search, $withDetails],
            self::TTL_STATIC_SECONDS,
            new GetCitiesRequest($search, withDetails: $withDetails),
        );

        return GetCitiesRequest::collectData($json ?? []);
    }

    /**
     * @return Collection<int, VenueData>
     */
    public function venues(?string $search = null, bool $withDetails = false): Collection
    {
        $json = $this->remember(
            'venues',
            [$search, $withDetails],
            self::TTL_STATIC_SECONDS,
            new GetVenuesRequest($search, withDetails: $withDetails),
        );

        return GetVenuesRequest::collectData($json ?? []);
    }

    /**
     * Ohne search/selected begrenzt das Portal auf 10 Einträge; selected
     * (Codes oder IDs) hebt das Limit für genau diese Länder auf.
     *
     * @param  list<int|string>  $selected
     * @return Collection<int, CountryData>
     */
    public function countries(?string $search = null, array $selected = []): Collection
    {
        $json = $this->remember(
            'countries',
            [$search, $selected],
            self::TTL_STATIC_SECONDS,
            new GetCountriesRequest($search, $selected),
        );

        return GetCountriesRequest::collectData($json ?? []);
    }

    /**
     * @return Collection<int, BtcMapCommunityData>
     */
    public function btcMapCommunities(): Collection
    {
        $json = $this->remember(
            'btc-map-communities',
            [],
            self::TTL_STATIC_SECONDS,
            new GetBtcMapCommunitiesRequest,
        );

        return GetBtcMapCommunitiesRequest::collectData($json ?? []);
    }

    /**
     * Vom Nutzer erstellte Meetups. Ohne Portal-Token leer, ohne Request.
     *
     * @return Collection<int, MeetupData>
     */
    public function myMeetups(): Collection
    {
        if (! $this->portalAuth->hasToken()) {
            return new Collection;
        }

        $json = $this->remember(
            'my-meetups',
            [],
            self::TTL_MINE_SECONDS,
            new GetMyMeetupsRequest,
            fn (Response $response): mixed => $response->json('data'),
        );

        return GetMyMeetupsRequest::collectData($json ?? []);
    }

    /**
     * Vom Nutzer erstellte Kurse (read-only „Meine Kurse“ für Referenten),
     * über den öffentlichen Endpunkt mit user_id-Filter. Ohne Token oder
     * ohne lokal gecachtes Profil leer, ohne Request — die user-id stammt
     * aus PortalAuth::cachedProfile(), damit hier kein zweiter
     * Profil-HTTP-Call nötig ist.
     *
     * @return Collection<int, CourseData>
     */
    public function myCourses(): Collection
    {
        if (! $this->portalAuth->hasToken()) {
            return new Collection;
        }

        $userId = $this->portalAuth->cachedProfile()['id'] ?? null;

        if (! is_int($userId)) {
            return new Collection;
        }

        $json = $this->remember(
            'my-courses',
            [$userId],
            self::TTL_MINE_SECONDS,
            new GetCoursesRequest(userId: $userId, withDetails: true),
        );

        return GetCoursesRequest::collectData($json ?? []);
    }

    /**
     * Eigene Kurs-Events. Ohne Portal-Token leer, ohne Request.
     *
     * @return Collection<int, CourseEventData>
     */
    public function myCourseEvents(?int $courseId = null): Collection
    {
        if (! $this->portalAuth->hasToken()) {
            return new Collection;
        }

        $json = $this->remember(
            'my-course-events',
            [$courseId],
            self::TTL_MINE_SECONDS,
            new GetMyCourseEventsRequest($courseId),
        );

        return GetMyCourseEventsRequest::collectData($json ?? []);
    }

    /**
     * Eigene Referenten-Profile (vom Nutzer erstellt). Ohne Portal-Token leer,
     * ohne Request. Trägt die editierbaren Felder (inkl. roher Markdown-Texte),
     * die der Referenten-Editor zum Bearbeiten braucht.
     *
     * @return Collection<int, MyLecturerData>
     */
    public function myLecturers(): Collection
    {
        if (! $this->portalAuth->hasToken()) {
            return new Collection;
        }

        $json = $this->remember(
            'my-lecturers',
            [],
            self::TTL_MINE_SECONDS,
            new GetMyLecturersRequest,
            fn (Response $response): mixed => $response->json('data'),
        );

        return GetMyLecturersRequest::collectData($json ?? []);
    }

    /**
     * Eigene Meetup-Termine (vom Nutzer erstellt, alle Meetups). Ohne
     * Portal-Token leer, ohne Request. Das Portal filtert nicht nach Meetup —
     * die Zuordnung übernimmt der Aufrufer in-memory über meetup_id.
     *
     * @return Collection<int, MyMeetupEventData>
     */
    public function myMeetupEvents(): Collection
    {
        if (! $this->portalAuth->hasToken()) {
            return new Collection;
        }

        $json = $this->remember(
            'my-meetup-events',
            [],
            self::TTL_MINE_SECONDS,
            new GetMyMeetupEventsRequest,
            fn (Response $response): mixed => $response->json('data'),
        );

        return GetMyMeetupEventsRequest::collectData($json ?? []);
    }

    /**
     * Eigene Veranstaltungsorte (vom Nutzer erstellt). Ohne Portal-Token leer,
     * ohne Request. Der Stadt-Anzeigename wird vom Aufrufer über die city_id
     * aufgelöst (die VenueResource liefert nur die id).
     *
     * @return Collection<int, MyVenueData>
     */
    public function myVenues(): Collection
    {
        if (! $this->portalAuth->hasToken()) {
            return new Collection;
        }

        $json = $this->remember(
            'my-venues',
            [],
            self::TTL_MINE_SECONDS,
            new GetMyVenuesRequest,
            fn (Response $response): mixed => $response->json('data'),
        );

        return GetMyVenuesRequest::collectData($json ?? []);
    }

    /**
     * Eigene Städte (vom Nutzer erstellt). Ohne Portal-Token leer, ohne
     * Request. Der Landes-Anzeigename wird vom Aufrufer über die country_id
     * aufgelöst (die CityResource liefert nur die id).
     *
     * @return Collection<int, MyCityData>
     */
    public function myCities(): Collection
    {
        if (! $this->portalAuth->hasToken()) {
            return new Collection;
        }

        $json = $this->remember(
            'my-cities',
            [],
            self::TTL_MINE_SECONDS,
            new GetMyCitiesRequest,
            fn (Response $response): mixed => $response->json('data'),
        );

        return GetMyCitiesRequest::collectData($json ?? []);
    }

    /**
     * Meetups, denen der Nutzer beigetreten ist. Ohne Portal-Token leer.
     * ⚠️ Liefert derzeit 401, bis das Portal die Route auf auth:sanctum
     * umstellt (siehe GetMemberMeetupsRequest).
     *
     * @return Collection<int, MemberMeetupData>
     */
    public function memberMeetups(?string $search = null): Collection
    {
        if (! $this->portalAuth->hasToken()) {
            return new Collection;
        }

        $json = $this->remember(
            'member-meetups',
            [$search],
            self::TTL_MINE_SECONDS,
            new GetMemberMeetupsRequest($search),
        );

        return GetMemberMeetupsRequest::collectData($json ?? []);
    }

    /**
     * Profil des Token-Inhabers — ungecacht; die Offline-Kopie verwaltet
     * bereits PortalAuth::profile().
     */
    public function user(): ?UserProfileData
    {
        if (! $this->portalAuth->hasToken()) {
            return null;
        }

        try {
            $response = $this->connector->send(new GetUserRequest);
        } catch (FatalRequestException|RequestException) {
            return null;
        }

        if ($response->failed()) {
            return null;
        }

        /** @var UserProfileData */
        return $response->dtoOrFail();
    }

    /**
     * Verwirft den frischen Cache-Eintrag eines Endpunkts, damit der
     * nächste Lesezugriff (online) frische Daten zieht — vom PortalWriter
     * nach erfolgreichen Schreiboperationen aufgerufen. Die dauerhafte
     * Stale-Kopie bleibt standardmäßig erhalten (Offline-Sicherheitsnetz);
     * nur mit $includeStale wird auch sie verworfen.
     *
     * Hinweis: Endpunkte mit Parametern (z. B. meetup-events nach Datum)
     * haben pro Parametersatz einen eigenen Key — invalidiert wird nur der
     * hier übergebene Satz (Default: parameterloser Basis-Key).
     *
     * @param  list<mixed>  $params
     */
    public function forget(string $endpoint, array $params = [], bool $includeStale = false): void
    {
        $key = $this->cacheKey($endpoint, $params);

        Cache::forget($key);

        if ($includeStale) {
            Cache::forget($key.self::STALE_SUFFIX);
        }
    }

    /**
     * Frisch gecachte Antwort liefern oder den Endpunkt abrufen; bei
     * Netzwerk-/Serverfehlern (oder offline) fällt der Aufruf auf die
     * dauerhaft gespeicherte Stale-Kopie zurück.
     *
     * @param  list<mixed>  $params
     * @param  (Closure(Response): mixed)|null  $extract
     * @return array<int|string, mixed>|null
     */
    private function remember(string $endpoint, array $params, int $ttlSeconds, Request $request, ?Closure $extract = null): ?array
    {
        $key = $this->cacheKey($endpoint, $params);

        $cached = Cache::get($key);

        if (is_array($cached)) {
            return $cached;
        }

        if (! $this->isOnline()) {
            return $this->stale($key);
        }

        try {
            $response = $this->connector->send($request);
        } catch (FatalRequestException|RequestException) {
            return $this->stale($key);
        }

        // 404 ist eine verbindliche Antwort („existiert nicht“), kein
        // Verbindungsproblem — weder Stale-Kopie noch Fehler-Status.
        if ($response->status() === 404) {
            return null;
        }

        if ($response->failed()) {
            return $this->stale($key);
        }

        $json = $extract !== null ? $extract($response) : $response->json();

        if (! is_array($json)) {
            return $this->stale($key);
        }

        Cache::put($key, $json, $ttlSeconds);
        Cache::forever($key.self::STALE_SUFFIX, $json);

        return $json;
    }

    /**
     * @return array<int|string, mixed>|null
     */
    private function stale(string $key): ?array
    {
        $stale = Cache::get($key.self::STALE_SUFFIX);

        if (is_array($stale)) {
            $this->servedStale = true;

            return $stale;
        }

        $this->missingData = true;

        return null;
    }

    /**
     * @param  list<mixed>  $params
     */
    private function cacheKey(string $endpoint, array $params): string
    {
        $key = self::CACHE_PREFIX.$endpoint;

        if (array_filter($params, fn (mixed $param): bool => $param !== null && $param !== false) !== []) {
            $key .= ':'.md5((string) json_encode($params));
        }

        return $key;
    }

    /**
     * Offline-Erkennung über das NativePHP-Network-Plugin; ohne Bridge
     * (Tests, lokale Entwicklung) gilt die App als online. Memoisiert pro
     * Instanz, weil jeder Status-Check ein Bridge-Call in den nativen
     * Layer ist und eine PortalApi nur einen Request/Render lang lebt.
     */
    private function isOnline(): bool
    {
        return $this->online ??= $this->checkOnline();
    }

    private function checkOnline(): bool
    {
        $status = Network::status();

        return $status === null || (bool) ($status->connected ?? true);
    }
}
