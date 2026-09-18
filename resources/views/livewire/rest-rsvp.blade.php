<?php

use App\Livewire\Concerns\InteractsWithEventRsvp;
use Livewire\Component;

/**
 * The REST RSVP of a Portal date (D12/D15, P5) — the app's arm of the package's
 * RSVP surface.
 *
 * ── When this component appears at all ───────────────────────────────────────
 *
 * Only when the package includes it, and it does so in exactly two cases
 * (`components/rsvp-termin.blade.php`, decided on the SERVER):
 *
 *   · The Portal published no kind 31923 for this date (`nostr_address` is
 *     null) — the case D12 expressly keeps the REST path for.
 *   · The meetup keeps its attendance private (`attendees_public=false`).
 *     D12a forbids the NOSTR answer there, because a kind 31925 is public and
 *     permanent and one query against the coordinate returns the whole guest
 *     list. Neither property holds for the REST answer, and the Portal keeps
 *     accepting it there (`MeetupEventController::rsvp` checks `rsvp_enabled`
 *     only) — removing it would mean deleting a working capability under the
 *     heading of a rule about a different mechanism.
 *
 * ── Why a component of its own and not the code of the old pages ────────────
 *
 * Until P5 the same RSVP hung on this app's Portal pages (`pages/events`,
 * `pages/meetups/show`). Those are deleted with P5 — the Portal pages have
 * lived in the package since P4 (D9) — and the package holds no Portal token.
 * This component is the narrowest remainder of them: it carries the trait that
 * already had the status and the counters, and nothing else.
 *
 * `eventId` comes from the slot parameter and not from an event: the component
 * stands right at its date, and an event would be a second way to information
 * that is already in the call.
 */
new class extends Component
{
    use InteractsWithEventRsvp;

    public ?int $eventId = null;

    public function mount(?int $eventId = null): void
    {
        $this->eventId = $eventId;
        $this->loadRsvp();
    }

    protected function rsvpEventId(): ?int
    {
        return $this->eventId;
    }
};
?>

<div data-portal-rest-rsvp="{{ $eventId }}">
    {{-- Without a date id there is nothing to answer: the Portal response carried
         none (older cached versions do not know the field), and a button without a
         target would be worse than no button. --}}
    @if ($eventId !== null)
        <x-rsvp-controls
            :status="$rsvpStatus"
            :attendees="$rsvpAttendees ?? 0"
            :might-attendees="$rsvpMightAttendees ?? 0"
            :can-rsvp="$this->canRsvp()"
        />
    @endif
</div>
