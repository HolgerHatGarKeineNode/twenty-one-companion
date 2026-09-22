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
 * {@see CreateCourseEventRequest}: the course picked from a select (`course_id`), the
 * city searched by name (`city_id`), the place as free text (`location`), date plus
 * start and end time, and the required registration link.
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

    /**
     * The portal's contract since the venue model left it (einundzwanzig-portal 5aba6dc,
     * `StoreCourseEventRequest`): a course date names its CITY by id (required) and its
     * place as free text (`location`, optional). `venue_id` is no longer a field there —
     * create answered 422 for a missing `city_id`, and update dropped the venue silently.
     */
    #[Validate('required|integer')]
    public ?int $city_id = null;

    /** Display only: the name of the chosen city; not part of the payload. */
    public string $cityName = '';

    #[Validate('nullable|string|max:255')]
    public ?string $location = null;

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
    public function setEvent(CourseEventData $event, string $courseName): void
    {
        // Die API liefert UTC; für die Eingabefelder in die Nutzer-Zeitzone.
        $from = Clock::toDisplay($event->from);
        $to = Clock::toDisplay($event->to);

        $this->course_id = $event->course_id;
        $this->courseName = $courseName;
        $this->city_id = $event->city_id ?? $event->city?->id;
        $this->cityName = $event->city->name ?? '';
        $this->location = $event->location;
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
            'city_id' => $this->city_id,
            'location' => filled($this->location) ? trim($this->location) : null,
            // Lokale Eingabe (Nutzer-Zeitzone) → UTC, wie das Portal es erwartet.
            'from' => Clock::localToUtc($this->date.' '.$this->from_time),
            'to' => Clock::localToUtc($this->effectiveEndDate().' '.$this->to_time),
            'link' => $this->link,
        ];
    }
}
