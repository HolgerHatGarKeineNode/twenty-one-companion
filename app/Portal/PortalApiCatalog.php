<?php

namespace App\Portal;

use App\Data\Portal\CourseData;
use App\Data\Portal\CourseEventData;
use App\Data\Portal\LecturerData;
use App\Data\Portal\MapMeetupData;
use App\Data\Portal\MeetupEventData;
use App\Data\Portal\MobileMeetupData;
use App\Services\PortalApi;
use Carbon\CarbonImmutable;
use Einundzwanzig\Group\Portal\BuildsPortalIndex;
use Einundzwanzig\Group\Portal\PortalCatalog;
use Einundzwanzig\Group\Portal\PortalCourse;
use Einundzwanzig\Group\Portal\PortalCourseDetail;
use Einundzwanzig\Group\Portal\PortalCourseEvent;
use Einundzwanzig\Group\Portal\PortalEvent;
use Einundzwanzig\Group\Portal\PortalLecturer;
use Einundzwanzig\Group\Portal\PortalLecturerDetail;
use Einundzwanzig\Group\Portal\PortalMeetup;
use Einundzwanzig\Group\Portal\PortalMeetupDetail;
use Einundzwanzig\Group\Portal\PortalStatus;
use Illuminate\Support\Collection;

/**
 * The app's binding of {@see PortalCatalog} (P4/D9): it puts the package's READ-ONLY Portal
 * pages onto the existing {@see PortalApi}.
 *
 * ── Why not the package's HTTP binding ────────────────────────────────────────────
 * Because this app has to work offline. `PortalApi` caches in two tiers — fresh with a TTL
 * plus a PERMANENT stale copy — and answers a read in a tunnel out of that copy instead of
 * showing an empty page. `HttpPortalCatalog` has a seven-day stale window and a server that
 * is always online; here the normal case is a device on a train. On top of that comes the
 * Portal TOKEN: the same facade carries the authenticated endpoints, and a second HTTP path
 * next to it would be a second cached copy of the same data.
 *
 * ── What does NOT happen here ─────────────────────────────────────────────────────
 * No writing. This app's editors stay its own surfaces and hang on
 * `config('group.portal_detail_actions')`; this catalog is the READING seam, exactly as the
 * contract says.
 */
final class PortalApiCatalog implements PortalCatalog
{
    use BuildsPortalIndex;

    /**
     * Memoised per request — page, filter and index read the same list.
     *
     * @var Collection<int, MobileMeetupData>|null
     */
    private ?Collection $meetupCache = null;

    /** @var Collection<int, MapMeetupData>|null */
    private ?Collection $mapCache = null;

    public function __construct(private readonly PortalApi $api) {}

    /**
     * The status of THIS request, translated from the three flags of `PortalApi`.
     *
     * The order is meaning: "no data at all" (not even from the stale copy) is `offline`,
     * "served from the stale copy" is `stale`. An expired Portal TOKEN (`hasAuthExpired`)
     * does NOT count as offline here — it only affects the authenticated endpoints, and none
     * of those is part of this contract; the read-only pages are unaffected and must
     * therefore not claim "unreachable".
     */
    public function status(): PortalStatus
    {
        if ($this->api->hasMissingData()) {
            return PortalStatus::Offline;
        }

        return $this->api->servedStaleData() ? PortalStatus::Stale : PortalStatus::Fresh;
    }

    /** @return list<PortalMeetup> */
    public function meetups(): array
    {
        /** @var list<PortalMeetup> $meetups */
        $meetups = $this->mobileMeetups()
            ->map(fn (MobileMeetupData $meetup): PortalMeetup => new PortalMeetup(
                slug: $meetup->slug,
                name: $meetup->name,
                city: $meetup->city,
                country: mb_strtoupper($meetup->country),
                logo: $meetup->logo,
                nextEventStart: $this->immutable($meetup->next_event_start),
                latitude: $meetup->latitude,
                longitude: $meetup->longitude,
            ))
            ->values()
            ->all();

        return $meetups;
    }

    public function meetup(string $slug): ?PortalMeetupDetail
    {
        $found = $this->mapMeetups()->first(
            fn (MapMeetupData $meetup): bool => $meetup->slug() === $slug,
        );

        if ($found === null) {
            return null;
        }

        $meetup = new PortalMeetup(
            slug: $slug,
            name: $found->name,
            city: $found->city,
            country: mb_strtoupper($found->country),
            logo: $found->logo,
            nextEventStart: $this->immutable($found->next_event?->start),
            id: $found->id,
            latitude: $found->latitude,
            longitude: $found->longitude,
        );

        // The dates of this meetup out of the same window the Termine list reads — one
        // source for one fact.
        [$von, $bis] = $this->indexEventWindow();
        /** @var list<PortalEvent> $termine */
        $termine = array_values(array_filter(
            $this->events($von, $bis),
            static fn (PortalEvent $event): bool => $event->meetupSlug === $slug,
        ));

        return new PortalMeetupDetail(
            meetup: $meetup,
            intro: $found->intro,
            links: $found->socialLinks(),
            nextEvent: $termine[0] ?? null,
            upcoming: array_slice($termine, 1),
            rsvpEnabled: $found->rsvp_enabled,
            attendeesPublic: $found->attendees_public,
            hasRoom: $found->has_room,
            portalLink: $found->portalLink,
        );
    }

    /** @return list<PortalEvent> */
    public function events(string $from, string $to): array
    {
        $start = CarbonImmutable::parse($from)->startOfDay();
        $end = CarbonImmutable::parse($to)->endOfDay();
        if ($end->lessThan($start)) {
            return [];
        }

        $events = [];
        /*
         * One request per MONTH, like the HTTP binding: `/api/meetup-events/{Y-m-d}` answers
         * from that day to the end of ITS month. The full list is 4.6 MB — on a device that
         * is not only bandwidth but also cache space.
         */
        for ($monat = $start->startOfMonth(); $monat->lessThanOrEqualTo($end); $monat = $monat->addMonth()->startOfMonth()) {
            $tag = $monat->lessThan($start) ? $start : $monat;
            foreach ($this->api->meetupEvents($tag->toDateString()) as $event) {
                $mapped = $this->toEvent($event);
                if ($mapped === null || $mapped->start->lessThan($start) || $mapped->start->greaterThan($end)) {
                    continue;
                }
                $events[] = $mapped;
            }
        }

        usort($events, static fn (PortalEvent $a, PortalEvent $b): int => $a->start->getTimestamp() <=> $b->start->getTimestamp());

        return $events;
    }

    /** @return list<PortalCourse> */
    public function courses(): array
    {
        /** @var list<PortalCourse> $kurse */
        $kurse = $this->api->courses(withDetails: true)
            ->map(fn (CourseData $course): PortalCourse => $this->toCourse($course))
            ->values()
            ->all();

        return $kurse;
    }

    public function course(int $id): ?PortalCourseDetail
    {
        $detail = $this->api->course($id);

        if ($detail === null) {
            return null;
        }

        return new PortalCourseDetail(
            id: $detail->id,
            name: $detail->name,
            description: $detail->description,
            image: $this->bildOderNull($detail->image),
            portalLink: $detail->portalLink,
            lecturer: $detail->lecturer === null ? null : $this->toLecturer($detail->lecturer),
            events: array_values(array_map(
                fn (CourseEventData $event): PortalCourseEvent => new PortalCourseEvent(
                    id: $event->id,
                    from: $this->immutable($event->from) ?? CarbonImmutable::now(),
                    to: $this->immutable($event->to),
                    link: $event->link,
                    location: $event->locationLabel(),
                ),
                $detail->events,
            )),
        );
    }

    /** @return list<PortalLecturer> */
    public function lecturers(): array
    {
        /** @var list<PortalLecturer> $referenten */
        $referenten = $this->api->lecturers(withDetails: true)
            ->map(fn (LecturerData $lecturer): PortalLecturer => $this->toLecturer($lecturer))
            ->values()
            ->all();

        return $referenten;
    }

    public function lecturer(int $id): ?PortalLecturerDetail
    {
        $detail = $this->api->lecturer($id);

        if ($detail === null) {
            return null;
        }

        return new PortalLecturerDetail(
            lecturer: new PortalLecturer(
                id: $detail->id,
                name: $detail->name,
                image: $this->bildOderNull($detail->image),
                subtitle: $detail->subtitle,
            ),
            intro: $detail->intro,
            description: $detail->description,
            active: $detail->active,
            links: $detail->socialLinks(),
            courses: array_values(array_map(fn (CourseData $course): PortalCourse => $this->toCourse($course), $detail->courses)),
            lightningAddress: $detail->lightning_address,
            portalLink: rtrim((string) config('group.portal_url'), '/').'/de/lecturer/'.$detail->id,
        );
    }

    // ── Mapping ─────────────────────────────────────────────────────────────────

    private function toEvent(MeetupEventData $event): ?PortalEvent
    {
        $slug = $event->meetup->slug();

        if ($slug === '') {
            return null;
        }

        return new PortalEvent(
            start: $this->immutable($event->start) ?? CarbonImmutable::now(),
            meetupName: $event->meetup->name,
            meetupSlug: $slug,
            id: $event->id,
            meetupCity: $event->meetup->city,
            meetupCountry: mb_strtoupper($event->meetup->country),
            meetupLogo: $event->meetup->logo,
            location: $event->location,
            description: $event->description,
            link: $event->link,
            attendees: $event->attendees,
            mightAttendees: $event->might_attendees,
            // P5: the 31923 coordinate the Portal published for this date (or null). The first
            // condition of the RSVP rule — without it this client never publishes an answer and
            // the surface shows its REST arm instead (D12).
            nostrAddress: $event->nostr_address,
            rsvpEnabled: $event->meetup->rsvp_enabled,
            /*
             * `attendees_public` is not a field of the date payload: the Portal expresses it by
             * sending the counters as `null` (`MeetupEventController`, read 2026-09-18). Derived
             * once here, so the rule reads a flag instead of inferring one — and identical to
             * what the web binding derives from the same signal.
             */
            attendeesPublic: $event->attendees !== null,
        );
    }

    private function toCourse(CourseData $course): PortalCourse
    {
        $lecturer = $course->lecturerOrNull();

        return new PortalCourse(
            id: $course->id,
            name: $course->name,
            image: $course->imageOrNull(),
            description: $course->descriptionHtml() === null ? null : $this->rohBeschreibung($course),
            nextEvent: $this->immutable($course->nextEvent()),
            lecturerName: $lecturer?->name,
            lecturerId: $lecturer?->id,
        );
    }

    private function toLecturer(LecturerData $lecturer): PortalLecturer
    {
        return new PortalLecturer(
            id: $lecturer->id,
            name: $lecturer->name,
            image: $this->bildOderNull($lecturer->image),
            subtitle: $lecturer->subtitleOrNull(),
            futureEventsCount: $lecturer->futureEventsCount(),
            nextEvent: $this->immutable($lecturer->nextEvent()),
        );
    }

    /**
     * The RAW description — the package pages render Portal markdown as TEXT with its line
     * breaks and never as HTML (foreign input, no HTML sink in the package), so
     * `descriptionHtml()` would be exactly the wrong shape here.
     */
    private function rohBeschreibung(CourseData $course): ?string
    {
        $raw = $course->description;

        return is_string($raw) && trim($raw) !== '' ? $raw : null;
    }

    /**
     * For courses/lecturers WITHOUT an own image the Portal answers its placeholder URL
     * (`/img/einundzwanzig.png`, which does not exist → 404). Treated as "no image" so the
     * surface shows its initial instead of a broken picture — the same rule as
     * `CourseData::imageOrNull()` and as the package's HTTP binding.
     */
    private function bildOderNull(?string $image): ?string
    {
        if ($image === null || trim($image) === '' || str_contains($image, '/img/einundzwanzig')) {
            return null;
        }

        return $image;
    }

    private function immutable(mixed $value): ?CarbonImmutable
    {
        if ($value === null) {
            return null;
        }

        return $value instanceof CarbonImmutable ? $value : CarbonImmutable::parse((string) $value);
    }

    /** @return Collection<int, MobileMeetupData> */
    private function mobileMeetups(): Collection
    {
        return $this->meetupCache ??= $this->api->mobileMeetups();
    }

    /** @return Collection<int, MapMeetupData> */
    private function mapMeetups(): Collection
    {
        return $this->mapCache ??= $this->api->mapMeetups(withIntro: true, withLogos: true);
    }
}
