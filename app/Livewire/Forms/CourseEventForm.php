<?php

namespace App\Livewire\Forms;

use App\Data\Portal\CourseEventData;
use App\Http\Integrations\Portal\Requests\CreateCourseEventRequest;
use App\Support\Clock;
use Livewire\Attributes\Validate;
use Livewire\Form;

/**
 * Form-Object für das Anlegen/Bearbeiten eines Kurs-Events (Phase 7.3),
 * gespiegelt zu {@see EventForm}. Die Felder spiegeln die Payload von
 * {@see CreateCourseEventRequest}: der per Select gewählte Kurs (`course_id`),
 * der per Namen gesuchte Veranstaltungsort (`venue_id`, anders als beim
 * Meetup-Termin ein echter Fremdschlüssel — Kurs-Events tragen Geo über das
 * Venue), Datum + Start-/Endzeit sowie der Pflicht-Anmelde-Link.
 *
 * Datum und Zeiten werden getrennt erfasst (native Picker) und erst in
 * {@see payload()} zu `from`/`to` ("Y-m-d H:i") zusammengesetzt. Kurs-Events
 * gelten als eintägig (ein Datum, Start- und Endzeit) — das deckt die ganz
 * überwiegende Mehrheit der Kurs-Sessions ab und hält die mobile Maske schlank.
 */
class CourseEventForm extends Form
{
    #[Validate('required|integer')]
    public ?int $course_id = null;

    /** Nur zur Anzeige des gewählten Kurses; nicht Teil der Payload. */
    public string $courseName = '';

    #[Validate('required|integer')]
    public ?int $venue_id = null;

    /** Nur zur Anzeige des gewählten Orts; nicht Teil der Payload. */
    public string $venueName = '';

    #[Validate('required|date_format:Y-m-d')]
    public string $date = '';

    #[Validate('required|date_format:H:i')]
    public string $from_time = '';

    #[Validate('required|date_format:H:i|after:from_time')]
    public string $to_time = '';

    #[Validate('required|url|max:255')]
    public ?string $link = null;

    /**
     * Bestehendes eigenes Kurs-Event zum Bearbeiten in die Form laden. Kurs-
     * und Ort-Name werden vom Aufrufer aufgelöst (netzwerkfrei aus der
     * Kurs-Event-Kurzinfo).
     */
    public function setEvent(CourseEventData $event, string $courseName, string $venueName): void
    {
        // Die API liefert UTC; für die Eingabefelder in die Nutzer-Zeitzone.
        $from = Clock::toDisplay($event->from);
        $to = Clock::toDisplay($event->to);

        $this->course_id = $event->course_id;
        $this->courseName = $courseName;
        $this->venue_id = $event->venue_id;
        $this->venueName = $venueName;
        $this->date = $from->format('Y-m-d');
        $this->from_time = $from->format('H:i');
        $this->to_time = $to->format('H:i');
        $this->link = $event->link;
    }

    /**
     * Validierte Payload für den Portal-Write. Datum + Zeiten werden zu
     * `from`/`to` zusammengesetzt; die reinen Anzeigefelder bleiben außen vor.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $this->validate();

        return [
            'course_id' => $this->course_id,
            'venue_id' => $this->venue_id,
            // Lokale Eingabe (Nutzer-Zeitzone) → UTC, wie das Portal es erwartet.
            'from' => Clock::localToUtc($this->date.' '.$this->from_time),
            'to' => Clock::localToUtc($this->date.' '.$this->to_time),
            'link' => $this->link,
        ];
    }
}
