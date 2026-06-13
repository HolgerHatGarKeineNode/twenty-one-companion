<?php

namespace App\Livewire\Forms;

use App\Data\Portal\MeetupData;
use App\Http\Integrations\Portal\Requests\CreateMeetupRequest;
use Livewire\Attributes\Validate;
use Livewire\Form;

/**
 * Form-Object für das Anlegen/Bearbeiten eines Meetups (Phase 4) — und
 * zugleich die Referenz-Implementierung des Form-Object-Patterns für alle
 * weiteren CRUD-Flows: Validierungsregeln leben als `#[Validate]`-Attribute
 * hier, nicht inline auf der Seite.
 *
 * Die Felder spiegeln die Payload von {@see CreateMeetupRequest}.
 * Die Stadt wird im Flow per Namen gesucht und zu `city_id` aufgelöst
 * (REST-Writes erwarten IDs, siehe Entscheidungs-Log) — `cityName` ist nur
 * die Anzeige-/Suchhilfe und wird nicht an das Portal gesendet.
 */
class MeetupForm extends Form
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|integer')]
    public ?int $city_id = null;

    /** Nur zur Anzeige der gewählten Stadt; nicht Teil der Payload. */
    public string $cityName = '';

    #[Validate('nullable|string|max:5000')]
    public ?string $intro = null;

    #[Validate('nullable|url|max:255')]
    public ?string $telegram_link = null;

    #[Validate('nullable|url|max:255')]
    public ?string $webpage = null;

    #[Validate('nullable|string|max:255')]
    public ?string $twitter_username = null;

    #[Validate('nullable|string|max:255')]
    public ?string $matrix_group = null;

    #[Validate('nullable|string|max:255')]
    public ?string $nostr = null;

    #[Validate('nullable|string|max:255')]
    public ?string $simplex = null;

    #[Validate('nullable|string|max:255')]
    public ?string $signal = null;

    #[Validate('nullable|string|max:255')]
    public ?string $community = null;

    #[Validate('boolean')]
    public bool $visible_on_map = true;

    #[Validate('boolean')]
    public bool $is_active = true;

    /**
     * Bestehendes Meetup zum Bearbeiten in die Form laden.
     */
    public function setMeetup(MeetupData $meetup): void
    {
        $this->name = $meetup->name;
        $this->city_id = $meetup->city_id;
        $this->intro = $meetup->intro;
        $this->telegram_link = $meetup->telegram_link;
        $this->webpage = $meetup->webpage;
        $this->twitter_username = $meetup->twitter_username;
        $this->matrix_group = $meetup->matrix_group;
        $this->nostr = $meetup->nostr;
        $this->simplex = $meetup->simplex;
        $this->signal = $meetup->signal;
        $this->community = $meetup->community;
        $this->visible_on_map = $meetup->visible_on_map;
        $this->is_active = $meetup->is_active;
    }

    /**
     * Validierte Payload für den Portal-Write (snake_case, ohne die
     * reinen Anzeige-Felder).
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $this->validate();

        return [
            'name' => $this->name,
            'city_id' => $this->city_id,
            'intro' => $this->intro,
            'telegram_link' => $this->telegram_link,
            'webpage' => $this->webpage,
            'twitter_username' => $this->twitter_username,
            'matrix_group' => $this->matrix_group,
            'nostr' => $this->nostr,
            'simplex' => $this->simplex,
            'signal' => $this->signal,
            'community' => $this->community,
            'visible_on_map' => $this->visible_on_map,
            'is_active' => $this->is_active,
        ];
    }
}
