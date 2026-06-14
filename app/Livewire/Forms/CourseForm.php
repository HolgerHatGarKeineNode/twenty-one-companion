<?php

namespace App\Livewire\Forms;

use App\Data\Portal\CourseData;
use App\Http\Integrations\Portal\Requests\CreateCourseRequest;
use Livewire\Attributes\Validate;
use Livewire\Form;
use Spatie\LaravelData\Optional;

/**
 * Form-Object für das Anlegen/Bearbeiten eines Kurses (Phase 7.2). Die Felder
 * spiegeln die Payload von {@see CreateCourseRequest}: Name, der per Namen
 * gesuchte, zu `lecturer_id` aufgelöste Referent (REST-Writes erwarten IDs)
 * sowie die Beschreibung als Markdown. `lecturerName` ist nur Anzeige-/Suchhilfe
 * und wird nicht gesendet.
 *
 * Eine „Kategorie" gibt es bewusst nicht — die Portal-API für Kurse kennt nur
 * name/lecturer_id/description (siehe Offene Fragen).
 */
class CourseForm extends Form
{
    #[Validate('required|string|max:255')]
    public string $name = '';

    #[Validate('required|integer')]
    public ?int $lecturer_id = null;

    /** Nur zur Anzeige des gewählten Referenten; nicht Teil der Payload. */
    public string $lecturerName = '';

    #[Validate('nullable|string|max:5000')]
    public ?string $description = null;

    /**
     * Bestehenden Kurs zum Bearbeiten in die Form laden. Der Referenten-Name
     * wird vom Aufrufer aus dem Kurs (lecturer-Kurzinfo) aufgelöst.
     */
    public function setCourse(CourseData $course, string $lecturerName): void
    {
        $this->name = $course->name;
        $this->lecturer_id = $course->lecturerOrNull()?->id;
        $this->lecturerName = $lecturerName;
        $this->description = $course->description instanceof Optional ? null : $course->description;
    }

    /**
     * Validierte Payload für den Portal-Write (ohne die Anzeige-Felder).
     *
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $this->validate();

        return [
            'name' => $this->name,
            'lecturer_id' => $this->lecturer_id,
            'description' => $this->description,
        ];
    }
}
