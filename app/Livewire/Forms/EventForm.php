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

        return [
            'meetup_id' => $this->meetup_id,
            // Lokale Eingabe (Nutzer-Zeitzone) → UTC, wie das Portal es erwartet.
            'start' => Clock::localToUtc($this->date.' '.$this->time),
            'location' => $this->location,
            'description' => $this->description,
            'link' => $this->link,
        ];
    }
}
