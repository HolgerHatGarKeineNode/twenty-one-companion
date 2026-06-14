<?php

namespace App\Livewire\Forms;

use App\Data\Portal\MyLecturerData;
use App\Http\Integrations\Portal\Requests\CreateLecturerRequest;
use Livewire\Attributes\Validate;
use Livewire\Form;

/**
 * Form-Object für das Anlegen/Bearbeiten eines Referenten-Profils (Phase 7.1).
 * Die Felder spiegeln die Payload von {@see CreateLecturerRequest}: Name, eine
 * Kurzbeschreibung (subtitle), die Bio als Markdown (intro + description), der
 * Aktiv-Status sowie die externen Links.
 *
 * Ein Avatar-Upload gibt es bewusst nicht — die Portal-API für Referenten kennt
 * (wie beim Meetup-Logo, Phase 4.6) kein Datei-Feld; Uploads bleiben blockiert,
 * bis das Portal multipart unterstützt (siehe Offene Fragen).
 */
class LecturerForm extends Form
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('nullable|string|max:255')]
    public ?string $subtitle = null;

    #[Validate('nullable|string|max:5000')]
    public ?string $intro = null;

    #[Validate('nullable|string|max:5000')]
    public ?string $description = null;

    #[Validate('boolean')]
    public bool $active = true;

    #[Validate('nullable|url|max:255')]
    public ?string $website = null;

    #[Validate('nullable|string|max:255')]
    public ?string $twitter_username = null;

    #[Validate('nullable|string|max:255')]
    public ?string $nostr = null;

    #[Validate('nullable|string|max:255')]
    public ?string $lightning_address = null;

    /**
     * Bestehendes Referenten-Profil zum Bearbeiten in die Form laden.
     */
    public function setLecturer(MyLecturerData $lecturer): void
    {
        $this->name = $lecturer->name;
        $this->subtitle = $lecturer->subtitle;
        $this->intro = $lecturer->intro;
        $this->description = $lecturer->description;
        $this->active = $lecturer->active;
        $this->website = $lecturer->website;
        $this->twitter_username = $lecturer->twitter_username;
        $this->nostr = $lecturer->nostr;
        $this->lightning_address = $lecturer->lightning_address;
    }

    /**
     * Validierte Payload für den Portal-Write.
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $this->validate();

        return [
            'name' => $this->name,
            'subtitle' => $this->subtitle,
            'intro' => $this->intro,
            'description' => $this->description,
            'active' => $this->active,
            'website' => $this->website,
            'twitter_username' => $this->twitter_username,
            'nostr' => $this->nostr,
            'lightning_address' => $this->lightning_address,
        ];
    }
}
