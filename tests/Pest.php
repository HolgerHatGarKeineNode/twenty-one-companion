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

use Native\Mobile\Facades\SecureStorage;

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
        'last_event_at' => '2026-06-01T18:00:00.000000Z',
        'created_by' => 7,
        'created_at' => '2022-01-01T00:00:00.000000Z',
        'updated_at' => '2026-06-01T00:00:00.000000Z',
    ], $overrides);
}
