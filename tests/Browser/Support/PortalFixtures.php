<?php

declare(strict_types=1);

namespace Tests\Browser\Support;

use App\Http\Integrations\Portal\Requests\GetCourseRequest;
use App\Http\Integrations\Portal\Requests\GetCoursesRequest;
use App\Http\Integrations\Portal\Requests\GetLecturerRequest;
use App\Http\Integrations\Portal\Requests\GetLecturersRequest;
use App\Http\Integrations\Portal\Requests\GetMapMeetupsRequest;
use App\Http\Integrations\Portal\Requests\GetMeetupEventsRequest;
use App\Http\Integrations\Portal\Requests\GetMobileMeetupsRequest;
use Saloon\Http\Faking\MockClient;
use Saloon\Http\Faking\MockResponse;

/**
 * Establishes the FILLED state of the portal-HTTP pages (meetups, dates, courses,
 * lecturers) — the four surfaces the DoD names explicitly.
 *
 * **Not `Http::fake()`** — the first attempt with it had no effect (finding: the surface
 * kept rendering "Portal nicht erreichbar" even though the fake was registered). Reason:
 * this app does NOT bind `PortalCatalog` to the package's HTTP default
 * (`Einundzwanzig\Group\Portal\HttpPortalCatalog`, which uses `Illuminate\Http\Client`),
 * but to its OWN `App\Portal\PortalApiCatalog`
 * (`app/Providers/AppServiceProvider.php:81`) — justified by offline operation on a device
 * (a two-tier cache instead of a seven-day window). This catalog reads through
 * `App\Services\PortalApi`, and THAT speaks Saloon (`App\Http\Integrations\Portal\
 * Requests\*`), not the Laravel `Http` client — Saloon has its OWN fake system
 * (`Saloon\Http\Faking\MockClient`), which `Http::fake()` neither sees nor serves. This
 * exactly reuses the existing pattern from `tests/Feature/PortalCatalogBindingTest.php`
 * (`MockClient::global([...])`), not a new invention.
 *
 * NOT covered: articles, forge and rooms depend on a Nostr RELAY (`NOSTR_WORKSPACE_URL`,
 * WebSocket) instead of the portal HTTP API — neither fake mechanism can simulate that,
 * and a relay mock is its own infrastructure that this assignment did not commission.
 * Those surfaces stay in the empty state in EVERY pass (see report, "Portal state"
 * section).
 */
final class PortalFixtures
{
    /**
     * Registers the three portal HTTP endpoints that `HttpPortalCatalog` (package
     * `einundzwanzig/group`) actually calls — paths and shapes read 1:1 from its source,
     * not assumed:
     *
     *   - `/api/mobile/meetups`        list shape, `bereich/meetups`
     *   - `/api/meetups`               map shape (intro/logos), `bereich/meetups/{slug}`
     *   - `/api/meetup-events/{date}`  one call per month, dates view + detail
     *   - `/api/courses` / `/api/courses/{id}`
     *   - `/api/lecturers` / `/api/lecturers/{id}`
     *
     * Order is load-bearing: `Http::fake()` matches patterns in order, so the MORE
     * SPECIFIC path pattern (with a trailing `/…`) must stand before the general list
     * pattern, or `api/courses*` would also swallow `api/courses/5`.
     */
    public static function filled(): void
    {
        \withoutPortalToken();

        MockClient::global([
            GetMobileMeetupsRequest::class => MockResponse::make([\mobileMeetupFixture()]),
            GetMapMeetupsRequest::class => MockResponse::make([\mapMeetupFixture(), \wienMapFixture()]),
            GetMeetupEventsRequest::class => MockResponse::make([\meetupEventFixture()]),
            GetCoursesRequest::class => MockResponse::make([\detailedCourseFixture()]),
            GetCourseRequest::class => MockResponse::make(\courseDetailFixture()),
            GetLecturersRequest::class => MockResponse::make([\detailedLecturerFixture()]),
            GetLecturerRequest::class => MockResponse::make(\lecturerDetailFixture()),
        ]);
    }

    /**
     * Must run after every filled case — `MockClient::global()` is global, static state
     * that would otherwise leak into the NEXT (empty) sighting route of the same run.
     */
    public static function cleanUp(): void
    {
        MockClient::destroyGlobal();
    }
}
