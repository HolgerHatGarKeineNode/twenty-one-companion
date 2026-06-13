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
 * Meetup-Termin von GET /api/meetup-events/{date?} (literale meetup.*-Schlüssel).
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function meetupEventFixture(array $overrides = []): array
{
    return array_merge([
        'start' => '2022-12-17 19:00',
        'location' => 'Fürth',
        'description' => 'Einundzwanzig Franken Meetup',
        'link' => 'https://t.me/Einundzwanzig_FRANKEN',
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
 * Profil-Cache von PortalAuth füllen, damit Seiten id/is_lecturer ohne
 * HTTP-Call lesen können.
 *
 * @param  array<string, mixed>  $overrides
 */
function withCachedPortalProfile(array $overrides = []): void
{
    Cache::put(PortalAuth::PROFILE_CACHE_KEY, array_merge([
        'id' => 7,
        'name' => 'Satoshi',
        'email' => 'satoshi@example.com',
        'nostr' => 'npub1xyz',
        'is_lecturer' => false,
        'is_leader' => false,
        'avatar' => null,
    ], $overrides));
}
