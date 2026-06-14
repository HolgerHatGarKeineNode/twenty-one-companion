<?php

use App\Data\Portal\CityData;
use App\Data\Portal\MapMeetupData;
use App\Services\PortalApi;
use App\Services\PortalWriter;
use App\Services\WriteStatus;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Native\Mobile\Facades\SecureStorage;

/**
 * Manuelle Integrationstests gegen ein lokal laufendes Portal-Dev.
 *
 * NICHT Teil der Standard-Suite — Start ausschließlich über die eigene
 * Konfiguration:
 *
 *   composer test:integration
 *   # oder:
 *   vendor/bin/pest -c phpunit.integration.xml
 *
 * Die Schreibtests legen echte Datensätze im lokalen Portal an und laufen nur,
 * wenn ein gültiges Sanctum-Token gesetzt ist:
 *
 *   PORTAL_TEST_TOKEN="<token>" composer test:integration
 *
 * Ist das Portal nicht erreichbar, werden die Tests übersprungen (nicht rot).
 */
function portalBaseUrl(): string
{
    return rtrim((string) config('services.portal.url'), '/');
}

function skipUnlessPortalReachable(): void
{
    $base = portalBaseUrl();
    $host = parse_url($base, PHP_URL_HOST) ?: '127.0.0.1';
    $port = parse_url($base, PHP_URL_PORT) ?: (str_starts_with($base, 'https') ? 443 : 80);

    $socket = @fsockopen($host, (int) $port, $errno, $errstr, 1.0);

    if ($socket === false) {
        test()->markTestSkipped("Portal nicht erreichbar unter {$base} ({$errstr}).");
    }

    fclose($socket);
}

beforeEach(function () {
    skipUnlessPortalReachable();
    // Frische Calls statt Treffer aus einem vorherigen Lauf.
    Cache::flush();
});

it('reads live map meetups and maps them to DTOs', function () {
    withoutPortalToken();

    $meetups = app(PortalApi::class)->mapMeetups(withIntro: true, withLogos: true);

    expect($meetups)->toBeInstanceOf(Collection::class)
        ->and(app(PortalApi::class)->isOffline())->toBeFalse();

    if ($meetups->isNotEmpty()) {
        expect($meetups->first())->toBeInstanceOf(MapMeetupData::class)
            ->and($meetups->first()->name)->toBeString();
    }
})->group('integration');

it('reads live cities with details', function () {
    withoutPortalToken();

    $cities = app(PortalApi::class)->cities(withDetails: true);

    expect($cities)->toBeInstanceOf(Collection::class);

    if ($cities->isNotEmpty()) {
        expect($cities->first())->toBeInstanceOf(CityData::class)
            ->and($cities->first()->country->name)->toBeString();
    }
})->group('integration');

it('creates (idempotent) and updates a meetup against the live portal', function () {
    $token = env('PORTAL_TEST_TOKEN');

    if (blank($token)) {
        test()->markTestSkipped('PORTAL_TEST_TOKEN nicht gesetzt — Schreibtest übersprungen.');
    }

    withPortalToken((string) $token);
    SecureStorage::shouldReceive('set')->andReturnTrue();

    // Idempotent: einen festen Test-Datensatz wiederverwenden statt bei jedem
    // Lauf einen neuen anzulegen (sonst wächst die lokale DB unbegrenzt).
    $name = 'Integrationstest Meetup (mobile)';
    $existing = app(PortalApi::class)->myMeetups()->firstWhere('name', $name);

    if ($existing === null) {
        // Stadt OHNE Suche holen (search nutzt portalseitig ilike → bricht auf
        // einer SQLite-Portal-DB; siehe README der Integrationssuite).
        $city = app(PortalApi::class)->cities(withDetails: true)->first();
        expect($city)->not->toBeNull('Keine Stadt im lokalen Portal vorhanden — bitte seeden.');

        $created = app(PortalWriter::class)->createMeetup([
            'name' => $name,
            'city_id' => $city->id,
            'intro' => 'Automatisch durch den Integrationstest angelegt.',
            'visible_on_map' => false,
            'is_active' => true,
        ]);

        expect($created->status)->toBe(WriteStatus::Success)
            ->and($created->successful())->toBeTrue();

        Cache::flush();
        $existing = app(PortalApi::class)->myMeetups()->firstWhere('name', $name);
    }

    expect($existing)->not->toBeNull('Angelegtes Meetup nicht in my-meetups gefunden.');

    $updated = app(PortalWriter::class)->updateMeetup($existing->id, [
        'intro' => 'Aktualisiert durch den Integrationstest am '.now()->toDateTimeString().'.',
    ]);

    expect($updated->status)->toBe(WriteStatus::Success);
})->group('integration');

it('creates (idempotent) and updates a meetup event against the live portal', function () {
    $token = env('PORTAL_TEST_TOKEN');

    if (blank($token)) {
        test()->markTestSkipped('PORTAL_TEST_TOKEN nicht gesetzt — Schreibtest übersprungen.');
    }

    withPortalToken((string) $token);
    SecureStorage::shouldReceive('set')->andReturnTrue();

    // Ein eigenes Meetup als Ziel — den Integrationstest-Datensatz wiederverwenden,
    // den der Meetup-Schreibtest anlegt.
    $meetup = app(PortalApi::class)->myMeetups()->first();
    expect($meetup)->not->toBeNull('Kein eigenes Meetup vorhanden — bitte zuerst den Meetup-Schreibtest laufen lassen oder seeden.');

    // Idempotent: einen vorhandenen eigenen Termin dieses Meetups wiederverwenden,
    // sonst genau einen neuen anlegen (statt bei jedem Lauf einen weiteren).
    $existing = app(PortalApi::class)->myMeetupEvents()
        ->firstWhere('meetup_id', $meetup->id);

    if ($existing === null) {
        $created = app(PortalWriter::class)->createMeetupEvent([
            'meetup_id' => $meetup->id,
            'start' => now()->addMonth()->format('Y-m-d H:i'),
            'location' => 'Integrationstest-Ort',
            'description' => 'Automatisch durch den Integrationstest angelegt.',
        ]);

        expect($created->status)->toBe(WriteStatus::Success)
            ->and($created->successful())->toBeTrue();

        Cache::flush();
        $existing = app(PortalApi::class)->myMeetupEvents()->firstWhere('meetup_id', $meetup->id);
    }

    expect($existing)->not->toBeNull('Angelegter Termin nicht in my-meetup-events gefunden.');

    $updated = app(PortalWriter::class)->updateMeetupEvent($existing->id, [
        'location' => 'Aktualisiert durch den Integrationstest am '.now()->toDateTimeString(),
    ]);

    expect($updated->status)->toBe(WriteStatus::Success);
})->group('integration');

it('creates (idempotent) and updates a city against the live portal', function () {
    $token = env('PORTAL_TEST_TOKEN');

    if (blank($token)) {
        test()->markTestSkipped('PORTAL_TEST_TOKEN nicht gesetzt — Schreibtest übersprungen.');
    }

    withPortalToken((string) $token);
    SecureStorage::shouldReceive('set')->andReturnTrue();

    // Idempotent: einen festen Test-Datensatz wiederverwenden.
    $name = 'Integrationstest Stadt (mobile)';
    $existing = app(PortalApi::class)->myCities()->firstWhere('name', $name);

    if ($existing === null) {
        // Land OHNE Suche holen (search nutzt portalseitig ilike → bricht auf
        // einer SQLite-Portal-DB).
        $country = app(PortalApi::class)->countries()->first();
        expect($country)->not->toBeNull('Kein Land im lokalen Portal vorhanden — bitte seeden.');

        $created = app(PortalWriter::class)->createCity([
            'name' => $name,
            'country_id' => $country->id,
            'latitude' => 49.013432,
            'longitude' => 12.101624,
            'population' => 100000,
        ]);

        expect($created->status)->toBe(WriteStatus::Success)
            ->and($created->successful())->toBeTrue();

        Cache::flush();
        $existing = app(PortalApi::class)->myCities()->firstWhere('name', $name);
    }

    expect($existing)->not->toBeNull('Angelegte Stadt nicht in my-cities gefunden.');

    $updated = app(PortalWriter::class)->updateCity($existing->id, [
        'population' => random_int(50_000, 500_000),
    ]);

    expect($updated->status)->toBe(WriteStatus::Success);
})->group('integration');

it('creates (idempotent) and updates a venue against the live portal', function () {
    $token = env('PORTAL_TEST_TOKEN');

    if (blank($token)) {
        test()->markTestSkipped('PORTAL_TEST_TOKEN nicht gesetzt — Schreibtest übersprungen.');
    }

    withPortalToken((string) $token);
    SecureStorage::shouldReceive('set')->andReturnTrue();

    // Idempotent: einen festen Test-Datensatz wiederverwenden.
    $name = 'Integrationstest Ort (mobile)';
    $existing = app(PortalApi::class)->myVenues()->firstWhere('name', $name);

    if ($existing === null) {
        $city = app(PortalApi::class)->cities(withDetails: true)->first();
        expect($city)->not->toBeNull('Keine Stadt im lokalen Portal vorhanden — bitte seeden.');

        $created = app(PortalWriter::class)->createVenue([
            'name' => $name,
            'street' => 'Integrationstest-Straße 21',
            'city_id' => $city->id,
        ]);

        expect($created->status)->toBe(WriteStatus::Success)
            ->and($created->successful())->toBeTrue();

        Cache::flush();
        $existing = app(PortalApi::class)->myVenues()->firstWhere('name', $name);
    }

    expect($existing)->not->toBeNull('Angelegter Ort nicht in my-venues gefunden.');

    $updated = app(PortalWriter::class)->updateVenue($existing->id, [
        'street' => 'Aktualisiert am '.now()->toDateTimeString(),
    ]);

    expect($updated->status)->toBe(WriteStatus::Success);
})->group('integration');

it('rejects an invalid create with structured 422 field errors', function () {
    $token = env('PORTAL_TEST_TOKEN');

    if (blank($token)) {
        test()->markTestSkipped('PORTAL_TEST_TOKEN nicht gesetzt — Schreibtest übersprungen.');
    }

    withPortalToken((string) $token);

    // Ohne Name/Stadt muss das Portal mit 422 + Feldfehlern antworten.
    $result = app(PortalWriter::class)->createMeetup([]);

    expect($result->status)->toBe(WriteStatus::ValidationError)
        ->and($result->hasValidationErrors())->toBeTrue()
        ->and($result->errors)->not->toBeEmpty();
})->group('integration');

it('creates (idempotent) and updates a lecturer against the live portal', function () {
    $token = env('PORTAL_TEST_TOKEN');

    if (blank($token)) {
        test()->markTestSkipped('PORTAL_TEST_TOKEN nicht gesetzt — Schreibtest übersprungen.');
    }

    withPortalToken((string) $token);
    SecureStorage::shouldReceive('set')->andReturnTrue();

    // Idempotent: einen festen Test-Datensatz wiederverwenden.
    $name = 'Integrationstest Referent (mobile)';
    $existing = app(PortalApi::class)->myLecturers()->firstWhere('name', $name);

    if ($existing === null) {
        $created = app(PortalWriter::class)->createLecturer([
            'name' => $name,
            'subtitle' => 'Bitcoin-Educator',
            'description' => 'Automatisch durch den Integrationstest angelegt.',
            'active' => true,
        ]);

        expect($created->status)->toBe(WriteStatus::Success)
            ->and($created->successful())->toBeTrue();

        Cache::flush();
        $existing = app(PortalApi::class)->myLecturers()->firstWhere('name', $name);
    }

    expect($existing)->not->toBeNull('Angelegter Referent nicht in my-lecturers gefunden.');

    $updated = app(PortalWriter::class)->updateLecturer($existing->id, [
        'subtitle' => 'Aktualisiert am '.now()->toDateTimeString(),
    ]);

    expect($updated->status)->toBe(WriteStatus::Success);
})->group('integration');

it('creates (idempotent) and updates a course against the live portal', function () {
    $token = env('PORTAL_TEST_TOKEN');

    if (blank($token)) {
        test()->markTestSkipped('PORTAL_TEST_TOKEN nicht gesetzt — Schreibtest übersprungen.');
    }

    withPortalToken((string) $token);
    SecureStorage::shouldReceive('set')->andReturnTrue();

    // Einen Referenten als Ziel — den Integrationstest-Datensatz wiederverwenden.
    $lecturer = app(PortalApi::class)->myLecturers()->first();
    expect($lecturer)->not->toBeNull('Kein eigener Referent vorhanden — bitte zuerst den Referenten-Schreibtest laufen lassen.');

    $name = 'Integrationstest Kurs (mobile)';
    $existing = app(PortalApi::class)->myCourses()->firstWhere('name', $name);

    if ($existing === null) {
        $created = app(PortalWriter::class)->createCourse([
            'name' => $name,
            'lecturer_id' => $lecturer->id,
            'description' => 'Automatisch durch den Integrationstest angelegt.',
        ]);

        // Kurse anlegen erfordert is_lecturer — sonst sauber überspringen.
        if ($created->status === WriteStatus::Forbidden) {
            test()->markTestSkipped('Test-User ist kein Referent (is_lecturer) — Kurs-Schreibtest übersprungen.');
        }

        expect($created->status)->toBe(WriteStatus::Success);

        Cache::flush();
        $existing = app(PortalApi::class)->myCourses()->firstWhere('name', $name);
    }

    expect($existing)->not->toBeNull('Angelegter Kurs nicht in my-courses gefunden.');

    $updated = app(PortalWriter::class)->updateCourse($existing->id, [
        'description' => 'Aktualisiert am '.now()->toDateTimeString(),
    ]);

    expect($updated->status)->toBe(WriteStatus::Success);
})->group('integration');

it('creates (idempotent) and updates a course event against the live portal', function () {
    $token = env('PORTAL_TEST_TOKEN');

    if (blank($token)) {
        test()->markTestSkipped('PORTAL_TEST_TOKEN nicht gesetzt — Schreibtest übersprungen.');
    }

    withPortalToken((string) $token);
    SecureStorage::shouldReceive('set')->andReturnTrue();

    $course = app(PortalApi::class)->myCourses()->firstWhere('name', 'Integrationstest Kurs (mobile)')
        ?? app(PortalApi::class)->myCourses()->first();
    expect($course)->not->toBeNull('Kein eigener Kurs vorhanden — bitte zuerst den Kurs-Schreibtest laufen lassen.');

    $venue = app(PortalApi::class)->venues(withDetails: true)->first();
    expect($venue)->not->toBeNull('Kein Ort im lokalen Portal vorhanden — bitte seeden.');

    // Idempotent: einen vorhandenen eigenen Kurs-Termin dieses Kurses wiederverwenden,
    // sonst genau einen neuen anlegen.
    $existing = app(PortalApi::class)->myCourseEvents()->firstWhere('course_id', $course->id);

    if ($existing === null) {
        $created = app(PortalWriter::class)->createCourseEvent([
            'course_id' => $course->id,
            'venue_id' => $venue->id,
            'from' => now()->addMonth()->format('Y-m-d').' 18:00',
            'to' => now()->addMonth()->format('Y-m-d').' 20:00',
            'link' => 'https://example.com/integrationstest-anmeldung',
        ]);

        if ($created->status === WriteStatus::Forbidden) {
            test()->markTestSkipped('Test-User ist kein Referent (is_lecturer) — Kurs-Event-Schreibtest übersprungen.');
        }

        expect($created->status)->toBe(WriteStatus::Success);

        Cache::flush();
        $existing = app(PortalApi::class)->myCourseEvents()->firstWhere('course_id', $course->id);
    }

    expect($existing)->not->toBeNull('Angelegtes Kurs-Event nicht in course-events gefunden.');

    $updated = app(PortalWriter::class)->updateCourseEvent($existing->id, [
        'link' => 'https://example.com/aktualisiert-'.now()->format('His'),
    ]);

    expect($updated->status)->toBe(WriteStatus::Success);
})->group('integration');
