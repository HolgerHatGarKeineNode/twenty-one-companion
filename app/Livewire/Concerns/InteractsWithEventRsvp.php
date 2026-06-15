<?php

namespace App\Livewire\Concerns;

use App\Enums\RsvpStatus;
use App\Services\PortalApi;
use App\Services\PortalAuth;
use App\Services\PortalWriter;
use Flux\Flux;
use Livewire\Component;

/**
 * RSVP für einen Meetup-Termin („Ich komme" / „Vielleicht" / „Kann nicht"),
 * geteilt von allen Komponenten, die einen Termin anzeigen (Meetup-Detail-
 * Karte und Termin-Slide-In). Die konkrete Komponente liefert nur die ID des
 * gerade relevanten Termins über {@see rsvpEventId()}; der Rest — Laden des
 * eigenen Status, Schreiben, Feedback — lebt hier.
 *
 * Status und Zähler werden einmal vom Portal geholt und danach aus der
 * Write-Antwort aktualisiert (kein erneuter Fetch). Ohne Token oder ohne
 * Termin-ID bleibt alles `null` und die Buttons werden ausgeblendet.
 *
 * @mixin Component
 */
trait InteractsWithEventRsvp
{
    use HandlesPortalWriteFeedback;

    public ?string $rsvpStatus = null;

    public ?int $rsvpAttendees = null;

    public ?int $rsvpMightAttendees = null;

    /**
     * ID des Termins, auf den sich das RSVP gerade bezieht (oder null, wenn
     * keiner aktiv/bekannt ist — dann keine Buttons).
     */
    abstract protected function rsvpEventId(): ?int;

    /**
     * Kann der aktuelle Nutzer für den aktiven Termin zu-/absagen? Nur mit
     * verbundenem Portal-Konto und bekannter Termin-ID.
     */
    public function canRsvp(): bool
    {
        return $this->rsvpEventId() !== null && app(PortalAuth::class)->hasToken();
    }

    /**
     * Eigenen RSVP-Status (+ Zähler) für den aktiven Termin laden. Setzt zuerst
     * zurück, damit beim Wechsel zwischen Terminen kein alter Status hängen
     * bleibt; ohne Token/ID passiert nichts weiter.
     */
    protected function loadRsvp(): void
    {
        $this->resetRsvp();

        $id = $this->rsvpEventId();

        if ($id === null || ! app(PortalAuth::class)->hasToken()) {
            return;
        }

        $rsvp = app(PortalApi::class)->meetupEventRsvp($id);

        if ($rsvp !== null) {
            $this->rsvpStatus = $rsvp->status->value;
            $this->rsvpAttendees = $rsvp->attendees;
            $this->rsvpMightAttendees = $rsvp->might_attendees;
        }
    }

    protected function resetRsvp(): void
    {
        $this->rsvpStatus = null;
        $this->rsvpAttendees = null;
        $this->rsvpMightAttendees = null;
    }

    /**
     * Zu-/absagen für den aktiven Termin. Aktualisiert Status und Zähler aus
     * der Write-Antwort, ohne Listen neu zu laden.
     */
    public function setRsvp(string $status): void
    {
        $id = $this->rsvpEventId();

        if ($id === null) {
            return;
        }

        $result = app(PortalWriter::class)->rsvpMeetupEvent($id, $status);

        if ($result->successful()) {
            $this->rsvpStatus = $result->data['status'] ?? $status;
            $this->rsvpAttendees = $result->data['attendees'] ?? $this->rsvpAttendees;
            $this->rsvpMightAttendees = $result->data['might_attendees'] ?? $this->rsvpMightAttendees;

            Flux::toast(text: match (RsvpStatus::tryFrom((string) $this->rsvpStatus)) {
                RsvpStatus::Attending => __('Du bist dabei!'),
                RsvpStatus::Maybe => __('Vielleicht-Zusage gespeichert.'),
                default => __('Abgesagt.'),
            }, variant: 'success');
            $this->js("window.haptic && window.haptic('success')");

            return;
        }

        $this->reportWriteFailure($result, __('Deine Antwort konnte nicht gespeichert werden.'));
    }
}
