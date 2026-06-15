<?php

namespace App\Livewire\Forms;

use App\Data\Portal\MyMeetupEventData;
use App\Http\Integrations\Portal\Requests\CreateMeetupEventRequest;
use App\Support\Clock;
use Livewire\Attributes\Validate;
use Livewire\Form;

/**
 * Form-Object für das Anlegen/Bearbeiten eines Meetup-Termins (Phase 5),
 * gespiegelt zu {@see MeetupForm}. Validierungsregeln leben als
 * `#[Validate]`-Attribute hier, nicht inline auf der Seite.
 *
 * Datum und Uhrzeit werden getrennt erfasst (zwei native Picker auf dem Gerät)
 * und erst in {@see payload()} zum `start`-String "Y-m-d H:i" zusammengesetzt,
 * den {@see CreateMeetupEventRequest} erwartet. Der „Ort" ist beim Meetup-Termin
 * portalseitig ein Freitextfeld (`location`), kein Venue-Fremdschlüssel — Venues
 * mit Geo-Koordinaten gehören erst zu den Kurs-Events (Phase 7).
 */
class EventForm extends Form
{
    #[Validate('required|integer')]
    public ?int $meetup_id = null;

    /** Nur zur Anzeige des gewählten Meetups; nicht Teil der Payload. */
    public string $meetupName = '';

    #[Validate('required|date_format:Y-m-d')]
    public string $date = '';

    #[Validate('required|date_format:H:i')]
    public string $time = '';

    #[Validate('nullable|string|max:255')]
    public ?string $location = null;

    #[Validate('nullable|string|max:5000')]
    public ?string $description = null;

    #[Validate('nullable|url|max:255')]
    public ?string $link = null;

    /**
     * Wiederkehrenden Termin als Serie anlegen (nur beim Anlegen). Spiegelt den
     * Serien-Modus des Web-Editors: das Portal expandiert die Regel über die
     * gemeinsame ExpandRecurrenceSeries-Action in einzelne Termine.
     */
    public bool $repeats = false;

    /** Wiederhol-Typ: daily | weekly | monthly | yearly | custom (RecurrenceType). */
    public string $recurrence_type = '';

    /** Wochentag (für weekly/custom), z. B. „monday". */
    public string $recurrence_day_of_week = '';

    /** Position im Monat (nur custom): first|second|third|fourth|last. */
    public string $recurrence_day_position = '';

    /** Enddatum der Serie (Pflicht im Serien-Modus, ≥ Startdatum). */
    public string $recurrence_end_date = '';

    /**
     * Bestehenden eigenen Termin zum Bearbeiten in die Form laden. Der
     * Meetup-Name wird vom Aufrufer aus myMeetups() aufgelöst (netzwerkfrei).
     */
    public function setEvent(MyMeetupEventData $event, string $meetupName): void
    {
        // Die API liefert UTC; für die Eingabefelder in die Nutzer-Zeitzone.
        $local = Clock::toDisplay($event->start);

        $this->meetup_id = $event->meetup_id;
        $this->meetupName = $meetupName;
        $this->date = $local->format('Y-m-d');
        $this->time = $local->format('H:i');
        $this->location = $event->location;
        $this->description = $event->description;
        $this->link = $event->link;
    }

    /**
     * Validierte Payload für den Portal-Write. Datum + Uhrzeit werden zum
     * `start`-String zusammengesetzt; die reinen Anzeigefelder bleiben außen vor.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $this->validate();

        $payload = [
            'meetup_id' => $this->meetup_id,
            // Lokale Eingabe (Nutzer-Zeitzone) → UTC, wie das Portal es erwartet.
            'start' => Clock::localToUtc($this->date.' '.$this->time),
            'location' => $this->location,
            'description' => $this->description,
            'link' => $this->link,
        ];

        // Serie nur, wenn beide Pflichtfelder gesetzt sind — exakt die Bedingung,
        // unter der das Portal eine Serie statt eines Einzeltermins erzeugt. Leere
        // optionale Felder (Wochentag/Position) fallen über array_filter raus.
        if ($this->repeats && $this->recurrence_type !== '' && $this->recurrence_end_date !== '') {
            $payload += array_filter([
                'recurrence_type' => $this->recurrence_type,
                'recurrence_end_date' => $this->recurrence_end_date,
                'recurrence_day_of_week' => $this->recurrence_day_of_week,
                'recurrence_day_position' => $this->recurrence_day_position,
            ], fn (string $value): bool => $value !== '');
        }

        return $payload;
    }
}
