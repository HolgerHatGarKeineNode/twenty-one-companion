# BACKLOG — Additional features 🚀

> **Origin:** split out of `VERSION_1_2_0.md` (formerly "Phase 8 — Additional features").
> Deliberately **removed from the v1.2.0 scope** so that v1.2.0 can be closed cleanly
> with the QA wrap-up (Phase 8 there).
>
> **Status:** unplanned / not tied to a version. Items get pulled into a concrete
> release plan when prioritized. The order of the IDs is historical only — not a ranking.
>
> **When an item is built, the phase-completion ritual from v1.2.0 applies:**
> (a) the opt-in integration suite (`scripts/run-integration.sh`) against the local portal dev,
> where write endpoints exist, and (b) Playwright validation of the new flow against the
> live portal API (functional + visual in the web renderer). Both are captured as the last
> two backlog rows (B.12/B.13).

---

## Feature ideas (individually checkable, MVP set in bold)

- [ ] **B.1 Favorites/watchlist** — bookmark meetups, events, courses locally (SQLite); dedicated "Saved" filter.
- [ ] **B.2 Add-to-calendar / ICS** — export an event to the native calendar (NativePHP) or share an `.ics` file.
- [x] **B.3 Share** — share a meetup/event/course via the native share sheet (deep link into the portal/app). *(Already shipped: `Share::url()` on meetup, event and course detail views.)*
- [ ] **B.4 QR code** — generate a QR per meetup/event (directions/join) + in-app QR scanner (NativePHP Scanner) to join.
- [x] **B.5 RSVP/attend** — "I'm coming" / "maybe" / "can't make it" status for the next event on the meetup detail. New portal API (`GET`/`POST /api/meetup-events/{id}/rsvp`, Sanctum); display name taken from the profile automatically. App: `RsvpMeetupEventRequest` + `PortalWriter::rsvpMeetupEvent()`, status hydrated once and updated from the write response (no map refetch).
- [ ] **B.6 Push notifications** — reminders before events of your own/saved meetups; new events in your region (NativePHP Push, permission priming from onboarding 3.5 already built).
- [ ] **B.7 "Nearby"** — geo-sorting of meetups/events by device location (NativePHP Location, opt-in).
- [x] **B.8 Profile expansion** — avatar, own contributions, connection status, "Log out / renew token" plus role badges (`is_lecturer`/`is_leader`, read-only), a tappable npub (opens njump), and inline **display-name editing** via a new portal write endpoint (`PATCH /api/user`, name only — roles are not user-settable; app: `UpdateUserProfileRequest` + `PortalWriter::updateUserProfile()`, cached profile refreshed from the response). *Bio dropped: the portal has no bio field/column.*
- [ ] **B.9 Pull-to-refresh & cache status** — a unified refresh gesture + a visible "last updated / offline" hint (builds on `servedStaleData`).
- [ ] **B.10 Widget/quick action** — Android shortcut "Next event". *(Stretch.)*
- [ ] **B.11 Review localization** — all new strings via `__()` + `lang/` files (de/en).
- [ ] **B.12** Extend and run the integration suite: secure the write/portal-bound features (e.g. RSVP B.5, Push B.6) against the local portal dev, where endpoints exist (`scripts/run-integration.sh`, opt-in).
- [ ] **B.13** Playwright validation of the implemented additional features (favorites, share, pull-to-refresh …) against the live portal API (functional + visual acceptance in the web renderer).

**Recommended MVP set for a later version:** B.1, B.2, B.3, B.6, B.9.

---

## Open questions (carried over, relevant to this backlog)

- [x] **RSVP/attend route** (B.5): no route existed — added `GET`/`POST /api/meetup-events/{id}/rsvp` to the portal (mirrors the web `attendees`/`might_attendees` JSON arrays, entry format `id_<userId>|<name>`).
- [ ] **Push infrastructure** (B.6): does the portal use its own push provider, or purely client-side local notifications?
