<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    // Standard: Onboarding abgeschlossen, Region „Alle Länder“ — Tests zum
    // Onboarding selbst setzen den Zustand mit resetOnboarding() zurück.
    ->beforeEach(fn () => completeOnboarding())
    ->in('Feature');

// Manuelle Integrationstests (echte Calls gegen ein lokales Portal-Dev) laufen
// nur über phpunit.integration.xml — die Standard-phpunit.xml referenziert
// tests/Integration nicht, daher werden sie hier nie automatisch eingesammelt.
// Kein completeOnboarding-Hook: diese Tests arbeiten auf Service-Ebene.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Integration');

// Browser-Smoke-Tests (echter Chromium via pest-plugin-browser) laufen nur
// über phpunit.browser.xml — die Standard-phpunit.xml referenziert tests/Browser
// nicht, damit der schnelle Unit/Feature-Loop und CI ohne Playwright nicht
// brechen. Onboarding wird geseedet, damit EnsureOnboarded nicht aufs
// Onboarding umleitet; das Portal ist im Browser-Env unerreichbar gesetzt
// (PORTAL_URL), sodass die Seiten ihre Offline-/Leer-Zustände rendern.
pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->beforeEach(fn () => completeOnboarding())
    ->in('Browser');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

use App\Services\AppPreferences;
use App\Services\PortalAuth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Native\Mobile\Facades\SecureStorage;

function completeOnboarding(string $locale = 'de', string $country = ''): void
{
    app(AppPreferences::class)->completeOnboarding($locale, $country);
    // Wer den aktuellen Pager durchläuft, wurde zwangsläufig nach
    // Benachrichtigungen gefragt (STEP_NOTIFICATIONS liegt vor finish()).
    // Ohne das schickt die Nachhol-Weiche in EnsureOnboarded jeden Seiten-
    // Test ins Onboarding. Den Nachhol-Fall prüft OnboardingTest gezielt.
    app(AppPreferences::class)->markNotificationsAsked();
    // Tests laufen deterministisch in UTC (= API-Zeiten), damit Datums-/
    // Zeit-Assertions nicht von der Default-Anzeige-Zeitzone (Europe/Berlin)
    // bzw. der Sommerzeit abhängen. Die Zeitzonen-Umrechnung selbst prüft
    // TimezoneTest gezielt mit gesetzter Berlin-Zeitzone.
    withTimezone('UTC');
}

function withTimezone(string $timezone): void
{
    app(AppPreferences::class)->setTimezone($timezone);
}

function resetOnboarding(): void
{
    DB::table('preferences')->delete();
    app()->forgetInstance(AppPreferences::class);
}

function withPortalToken(string $token = '12|secrettoken'): void
{
    SecureStorage::shouldReceive('get')->with('portal_api_token')->andReturn($token);
}

function withoutPortalToken(): void
{
    SecureStorage::shouldReceive('get')->with('portal_api_token')->andReturnNull();
}

/**
 * Meetup im Karten-Format von GET /api/meetups.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mapMeetupFixture(array $overrides = []): array
{
    return array_merge([
        'name' => 'Einundzwanzig Aschaffenburg',
        'portalLink' => 'https://portal.einundzwanzig.space/de/meetup/aschaffenburg',
        'url' => 'https://t.me/einundzwanzig_aschaffenburg',
        'top' => null,
        'left' => null,
        'country' => 'DE',
        'state' => null,
        'city' => 'Aschaffenburg',
        'longitude' => 9.146998,
        'latitude' => 49.977159,
        'twitter_username' => null,
        'website' => null,
        'simplex' => null,
        'signal' => null,
        'nostr' => null,
        'next_event' => [
            'id' => 2982,
            'start' => '2026-06-19T16:30:00.000000Z',
            'portalLink' => 'https://portal.einundzwanzig.space/de/meetup/aschaffenburg/event/2982',
            'location' => 'Mainaschaff',
            'description' => 'Mainhatten in Mainaschaff.',
            'link' => 'https://t.me/einundzwanzig_aschaffenburg',
            'attendees' => 1,
            'might_attendees' => 0,
            'nostr_note' => '',
        ],
        'intro' => null,
        'logo' => null,
    ], $overrides);
}

/**
 * Meetup im schlanken Format von GET /api/mobile/meetups (App-Liste/Karte).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function mobileMeetupFixture(array $overrides = []): array
{
    return array_merge([
        'name' => 'Einundzwanzig Aschaffenburg',
        'slug' => 'aschaffenburg',
        'city' => 'Aschaffenburg',
        'country' => 'DE',
        'latitude' => 49.977159,
        'longitude' => 9.146998,
        'logo' => null,
        'next_event_start' => '2026-06-19 16:30',
    ], $overrides);
}

/**
 * Wien im vollen Karten-Format (GET /api/meetups) — für Konsumenten, die noch
 * MapMeetupData nutzen (Meetup-Picker/-Editor, Detail).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function wienMapFixture(array $overrides = []): array
{
    return mapMeetupFixture(array_merge([
        'name' => 'Einundzwanzig Wien',
        'portalLink' => 'https://portal.einundzwanzig.space/at/meetup/wien',
        'country' => 'AT',
        'city' => 'Wien',
        'next_event' => null,
    ], $overrides));
}

/**
 * Meetup-Termin von GET /api/meetup-events/{date?} (literale meetup.*-Schlüssel).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function meetupEventFixture(array $overrides = []): array
{
    return array_merge([
        'id' => 555,
        'start' => '2022-12-17 19:00',
        'location' => 'Fürth',
        'description' => 'Einundzwanzig Franken Meetup',
        'link' => 'https://t.me/Einundzwanzig_FRANKEN',
        'attendees' => 0,
        'might_attendees' => 0,
        'meetup.name' => 'Einundzwanzig Franken',
        'meetup.portalLink' => 'https://portal.einundzwanzig.space/de/meetup/einundzwanzig-franken',
        'meetup.url' => 'https://t.me/Einundzwanzig_FRANKEN',
        'meetup.country' => 'DE',
        'meetup.city' => 'Franken',
        'meetup.longitude' => 11.011961,
        'meetup.latitude' => 49.589674,
        'meetup.twitter_username' => 'einundzwanzigFR',
        'meetup.website' => null,
        'meetup.simplex' => null,
        'meetup.signal' => null,
        'meetup.nostr' => null,
        'meetup.logo' => 'https://portal.einundzwanzig.space/storage/896/logo.jpg',
    ], $overrides);
}

/**
 * Eigener Meetup-Termin von GET /api/my-meetup-events (MeetupEventResource,
 * flache Schreib-/Eigentums-Sicht mit id + meetup_id, im data-Wrapper).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function myMeetupEventFixture(array $overrides = []): array
{
    return array_merge([
        'id' => 55,
        'meetup_id' => 21,
        'start' => '2026-12-31T19:00:00.000000Z',
        'location' => 'Bitcoin-Bar',
        'description' => 'Jahresabschluss-Stammtisch',
        'link' => 'https://t.me/einundzwanzig_aschaffenburg',
    ], $overrides);
}

/**
 * Eigenes Meetup von GET /api/my-meetups (MeetupResource, ohne data-Wrapper).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function myMeetupFixture(array $overrides = []): array
{
    return array_merge([
        'id' => 21,
        'name' => 'Einundzwanzig Aschaffenburg',
        'slug' => 'aschaffenburg',
        'city_id' => 5,
        'intro' => 'Hallo zusammen!',
        'telegram_link' => 'https://t.me/einundzwanzig_aschaffenburg',
        'webpage' => null,
        'twitter_username' => null,
        'matrix_group' => null,
        'nostr' => null,
        'simplex' => null,
        'signal' => null,
        'community' => 'einundzwanzig',
        'visible_on_map' => true,
        'is_active' => true,
        'logo' => 'https://portal.einundzwanzig.space/storage/meetups/21/conversions/logo-thumb.jpg',
        'last_event_at' => '2026-06-01T18:00:00.000000Z',
        'created_by' => 7,
        'created_at' => '2022-01-01T00:00:00.000000Z',
        'updated_at' => '2026-06-01T00:00:00.000000Z',
        // Token-Inhaber ist standardmäßig Leader des eigenen Meetups (darf
        // bearbeiten + Leader verwalten). Tests für Nicht-Leader überschreiben.
        'is_leader' => true,
    ], $overrides);
}

/**
 * Ein Leader aus GET /api/meetup/{id}/leaders (data-Wrapper).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function meetupLeaderFixture(array $overrides = []): array
{
    return array_merge([
        'id' => 7,
        'name' => 'Satoshi',
        'nostr' => 'npub1satoshixxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx',
        'avatar' => null,
        'is_creator' => true,
    ], $overrides);
}

/**
 * Kurs aus GET /api/courses?withDetails (Liste inkl. lecturer/next_event).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function detailedCourseFixture(array $overrides = []): array
{
    return array_merge([
        'id' => 5,
        'name' => 'Bitcoin, Blockchain und Geld',
        'image' => 'https://portal.einundzwanzig.space/storage/5/conversions/logo-thumb.jpg',
        'description' => 'Grundlagen zu **Bitcoin** und Geld.',
        'next_event' => '2026-07-01 18:00:00',
        'lecturer' => [
            'id' => 3,
            'name' => 'Toni Stack',
            'image' => 'https://portal.einundzwanzig.space/storage/3/conversions/avatar-thumb.jpg',
        ],
    ], $overrides);
}

/**
 * Kurs-Detail aus GET /api/courses/{id}.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function courseDetailFixture(array $overrides = []): array
{
    return array_merge([
        'id' => 5,
        'name' => 'Bitcoin, Blockchain und Geld',
        'description' => 'Grundlagen zu **Bitcoin** und Geld.',
        'image' => 'https://portal.einundzwanzig.space/storage/5/logo.jpg',
        'portalLink' => 'https://portal.einundzwanzig.space/de/course/5',
        'lecturer' => [
            'id' => 3,
            'name' => 'Toni Stack',
            'subtitle' => 'Bitcoin-Educator',
            'image' => 'https://portal.einundzwanzig.space/storage/3/conversions/avatar-thumb.jpg',
        ],
        'events' => [
            [
                'id' => 9,
                'course_id' => 5,
                'venue_id' => 3,
                'from' => '2026-07-01T18:00:00.000000Z',
                'to' => '2026-07-01T20:00:00.000000Z',
                'link' => 'https://example.com/kurs-anmeldung',
                'venue' => [
                    'id' => 3,
                    'name' => 'Volkshochschule',
                    'city' => [
                        'id' => 80,
                        'name' => 'Regensburg',
                        'country_id' => 1,
                        'country' => ['id' => 1, 'name' => 'Germany', 'code' => 'de'],
                    ],
                ],
            ],
        ],
    ], $overrides);
}

/**
 * Referent aus GET /api/lecturers?withDetails (Liste inkl. subtitle/Zähler).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function detailedLecturerFixture(array $overrides = []): array
{
    return array_merge([
        'id' => 3,
        'name' => 'Toni Stack',
        'subtitle' => 'Bitcoin-Educator',
        'image' => 'https://portal.einundzwanzig.space/storage/3/conversions/avatar-thumb.jpg',
        'future_events_count' => 2,
        'next_event' => '2026-07-01 18:00:00',
    ], $overrides);
}

/**
 * Referenten-Profil aus GET /api/lecturers/{id}.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function lecturerDetailFixture(array $overrides = []): array
{
    return array_merge([
        'id' => 3,
        'name' => 'Toni Stack',
        'subtitle' => 'Bitcoin-Educator',
        'intro' => 'Ich halte Kurse zu **Bitcoin**.',
        'description' => 'Seit 2017 unterwegs.',
        'image' => 'https://portal.einundzwanzig.space/storage/3/avatar.jpg',
        'active' => true,
        'nostr' => 'npub1tonistack',
        'website' => 'https://tonistack.example',
        'twitter_username' => 'tonistack',
        'lightning_address' => 'toni@stack.example',
        'courses' => [
            [
                'id' => 5,
                'name' => 'Bitcoin, Blockchain und Geld',
                'image' => 'https://portal.einundzwanzig.space/storage/5/conversions/logo-thumb.jpg',
                'next_event' => '2026-07-01 18:00:00',
            ],
        ],
    ], $overrides);
}

/**
 * Stadt aus GET /api/cities?withDetails (inkl. country.code und Flaggen-URL).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function cityFixture(array $overrides = []): array
{
    return array_merge([
        'id' => 80,
        'name' => 'Regensburg',
        'country_id' => 1,
        'country' => ['id' => 1, 'name' => 'Germany', 'code' => 'de'],
        'flag' => 'https://portal.einundzwanzig.space/vendor/blade-flags/country-de.svg',
    ], $overrides);
}

/**
 * Veranstaltungsort aus GET /api/venues (inkl. Stadt/Land, Flagge, Beschreibung).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function venueFixture(array $overrides = []): array
{
    return array_merge([
        'id' => 131,
        'name' => 'AfueraFest 2025',
        'city_id' => 80,
        'flag' => 'https://portal.einundzwanzig.space/vendor/blade-flags/country-de.svg',
        'description' => 'Regensburg, Hauptstraße 1',
        'city' => [
            'id' => 80,
            'name' => 'Regensburg',
            'country_id' => 1,
            'country' => ['id' => 1, 'name' => 'Germany', 'code' => 'de'],
        ],
    ], $overrides);
}

/**
 * Eigener Veranstaltungsort aus GET /api/my-venues (VenueResource, flache
 * Schreib-/Eigentums-Sicht mit id + city_id + street, im data-Wrapper).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function myVenueFixture(array $overrides = []): array
{
    return array_merge([
        'id' => 131,
        'city_id' => 80,
        'name' => 'Bitcoin-Bar',
        'slug' => 'bitcoin-bar',
        'street' => 'Hauptstraße 1',
        'created_by' => 7,
        'created_at' => '2026-01-01T00:00:00.000000Z',
        'updated_at' => '2026-06-01T00:00:00.000000Z',
    ], $overrides);
}

/**
 * Eigene Stadt aus GET /api/my-cities (CityResource, flache Schreib-/
 * Eigentums-Sicht mit id + country_id + Geo, im data-Wrapper).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function myCityFixture(array $overrides = []): array
{
    return array_merge([
        'id' => 80,
        'country_id' => 1,
        'name' => 'Regensburg',
        'slug' => 'regensburg',
        'longitude' => 12.101624,
        'latitude' => 49.013432,
        'population' => 152610,
        'created_by' => 7,
        'created_at' => '2026-01-01T00:00:00.000000Z',
        'updated_at' => '2026-06-01T00:00:00.000000Z',
    ], $overrides);
}

/**
 * Land aus GET /api/countries (id, name, code, flag).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function countryFixture(array $overrides = []): array
{
    return array_merge([
        'id' => 1,
        'name' => 'Germany',
        'code' => 'de',
        'flag' => 'https://portal.einundzwanzig.space/vendor/blade-flags/country-de.svg',
    ], $overrides);
}

/**
 * Eigener Referent aus GET /api/my-lecturers (LecturerResource, flache
 * Schreib-/Eigentums-Sicht mit allen editierbaren Feldern, im data-Wrapper).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function myLecturerFixture(array $overrides = []): array
{
    return array_merge([
        'id' => 3,
        'name' => 'Toni Stack',
        'slug' => 'toni-stack',
        'subtitle' => 'Bitcoin-Educator',
        'intro' => 'Ich halte Kurse zu **Bitcoin**.',
        'description' => 'Seit 2017 unterwegs.',
        'active' => true,
        'website' => 'https://tonistack.example',
        'twitter_username' => 'tonistack',
        'nostr' => 'npub1tonistack',
        'lightning_address' => 'toni@stack.example',
        'lnurl' => null,
        'node_id' => null,
        'paynym' => null,
        'team_id' => null,
        'created_by' => 7,
        'created_at' => '2026-01-01T00:00:00.000000Z',
        'updated_at' => '2026-06-01T00:00:00.000000Z',
    ], $overrides);
}

/**
 * Eigenes Kurs-Event aus GET /api/course-events (CourseEvent mit Kurs-/Venue-
 * Kurzinfo, ohne data-Wrapper).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function myCourseEventFixture(array $overrides = []): array
{
    return array_merge([
        'id' => 9,
        'course_id' => 5,
        'venue_id' => 3,
        'from' => '2026-07-01T18:00:00.000000Z',
        'to' => '2026-07-01T20:00:00.000000Z',
        'link' => 'https://example.com/kurs-anmeldung',
        'created_by' => 7,
        'created_at' => '2026-01-01T00:00:00.000000Z',
        'updated_at' => '2026-06-01T00:00:00.000000Z',
        'course' => ['id' => 5, 'name' => 'Bitcoin, Blockchain und Geld'],
        'venue' => ['id' => 3, 'name' => 'Volkshochschule'],
    ], $overrides);
}

/**
 * Erzeugt eine lokale Wegwerf-Datei als Stellvertreter für ein Bild aus der
 * nativen Kamera/Galerie (die in der Testumgebung nicht verfügbar ist). Der
 * Pfad wird im Editor als `imagePath` gesetzt und vom Upload-Request gelesen.
 */
function fakeImagePath(string $name = 'image.jpg'): string
{
    $path = tempnam(sys_get_temp_dir(), 'img').'-'.$name;
    file_put_contents($path, 'fake-binary-image');

    return $path;
}

/**
 * Profil-Shape von GET /api/user (Token-Inhaber).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function userProfileFixture(array $overrides = []): array
{
    return array_merge([
        'id' => 7,
        'name' => 'Satoshi',
        'email' => 'satoshi@example.com',
        'nostr' => 'npub1xyz',
        'is_lecturer' => false,
        'is_leader' => false,
        'avatar' => null,
    ], $overrides);
}

/**
 * Profil-Cache von PortalAuth füllen, damit Seiten id/is_lecturer ohne
 * HTTP-Call lesen können.
 *
 * @param  array<string, mixed>  $overrides
 */
function withCachedPortalProfile(array $overrides = []): void
{
    Cache::put(PortalAuth::PROFILE_CACHE_KEY, userProfileFixture($overrides));
}
