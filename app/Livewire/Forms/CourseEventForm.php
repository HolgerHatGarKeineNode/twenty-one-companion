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
 * {@see payload()} zu `from`/`to` ("Y-m-d H:i") zusammengesetzt. Ein Kurs-Event
 * ist standardmäßig eintägig (Start-Datum + Start-/Endzeit); für mehrtägige
 * Events lässt sich optional ein abweichendes End-Datum (`to_date`) erfassen —
 * leer bedeutet „selber Tag". Das Portal trägt `from`/`to` als volle DateTimes.
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

    /**
     * Optionales End-Datum für mehrtägige Events; leer = selber Tag wie `date`.
     * Bewusst ohne `#[Validate]`: ein leerer nativer date-Input sendet `''`
     * (nicht `null`), woran `date_format` scheitern würde. Die Reihenfolge
     * (Ende nach Beginn, inkl. `to_date` < `date`) prüft {@see endsBeforeOrAtStart()}.
     */
    public string $to_date = '';

    #[Validate('required|date_format:H:i')]
    public string $from_time = '';

    #[Validate('required|date_format:H:i')]
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
        // Nur bei abweichendem End-Datum füllen — leer hält die Maske eintägig.
        $this->to_date = $to->format('Y-m-d') !== $from->format('Y-m-d') ? $to->format('Y-m-d') : '';
        $this->from_time = $from->format('H:i');
        $this->to_time = $to->format('H:i');
        $this->link = $event->link;
    }

    /** Das effektive End-Datum: das optionale `to_date`, sonst der Starttag. */
    public function effectiveEndDate(): string
    {
        return $this->to_date !== '' ? $this->to_date : $this->date;
    }

    /**
     * Liegt das zusammengesetzte Ende vor oder gleichauf mit dem Beginn? Deckt
     * sowohl eine zu frühe Endzeit am selben Tag als auch ein `to_date` vor dem
     * Start-Datum ab. Der Vergleich der "Y-m-d H:i"-Strings ist chronologisch
     * korrekt (nullgepolstert) und damit zeitzonenneutral.
     */
    public function endsBeforeOrAtStart(): bool
    {
        return $this->effectiveEndDate().' '.$this->to_time <= $this->date.' '.$this->from_time;
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
            'to' => Clock::localToUtc($this->effectiveEndDate().' '.$this->to_time),
            'link' => $this->link,
        ];
    }
}
