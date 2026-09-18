{{-- `config('group.portal_rsvp_view')` — the REST arm of the package's RSVP surface
     (D12/P5).

     The package decides on the SERVER that no kind 31925 may be written here: either the
     Portal published no kind 31923 for this date (`nostr_address` null), or the meetup
     keeps its attendance private (D12a). In both cases the Portal still accepts the REST
     answer — and only this app can send it, because only this app holds a Portal token. On
     the web nobody binds this slot; the link into the Portal stands there instead.

     In scope: `$eventId` (the Portal's `meetup_events.id`) and `$portalLink`. Without a
     token nothing is mounted at all: an RSVP bar that answers every press with a 401 is
     worse than none — the way to connect is in the settings, in ONE place. --}}

@if (app(\App\Services\PortalAuth::class)->hasToken() && ($eventId ?? null) !== null)
    <livewire:rest-rsvp :event-id="$eventId" :key="'rest-rsvp-'.$eventId" />
@endif
