<?php

namespace App\Livewire\Concerns;

use App\Services\WriteResult;
use Flux\Flux;
use Livewire\Component;
use Native\Mobile\Attributes\OnNative;
use Native\Mobile\Events\Camera\PhotoTaken;
use Native\Mobile\Events\Gallery\MediaSelected;
use Native\Mobile\Facades\Camera;
use Native\Mobile\Facades\Network;

/**
 * Gemeinsames Bild-Auswahl-Plumbing der Editoren mit Logo/Avatar (Meetup,
 * Referent, Kurs). Kapselt die native Kamera/Galerie (NativePHP Camera) und
 * legt den lokalen Datei-Pfad des gewählten Bildes ab. Der eigentliche Upload
 * läuft zweistufig: der Editor legt erst die Stammdaten per JSON an (bekommt die
 * ID) und lädt das Bild danach über die jeweilige PortalWriter::upload*-Methode
 * hoch — die der Editor in {@see uploadImage()} bereitstellt.
 *
 * Da alle Editoren global im Layout liegen, hören sie dasselbe native Event.
 * Jeder Editor setzt deshalb seinen eigenen {@see imageUploadKey()} als
 * Korrelations-`id`; nur das Event mit passender id wird übernommen.
 *
 * Auf dem Gerät liefert die Kamera einen `PhotoTaken`-, die Galerie einen
 * `MediaSelected`-Event mit dem Datei-Pfad. In der Web-/Testumgebung gibt es
 * kein `nativephp_call` — `start()` ist dann ein No-Op; getestet wird der
 * Upload-Pfad, indem `imagePath` direkt gesetzt wird.
 *
 * @mixin Component
 */
trait HandlesImageUpload
{
    /** Lokaler Pfad des frisch gewählten Bildes (null = keins gewählt). */
    public ?string $imagePath = null;

    /** URL des bereits vorhandenen Bildes (beim Bearbeiten, zur Vorschau). */
    public ?string $currentImageUrl = null;

    /**
     * Korrelations-id, mit der dieser Editor seine Kamera-/Galerie-Events von
     * den anderen (ebenfalls im Layout liegenden) Editoren unterscheidet. Wird
     * pro Editor überschrieben.
     */
    /**
     * Editor-spezifischer Upload an die passende PortalWriter::upload*-Methode
     * (z. B. `uploadMeetupLogo`). Wird pro Editor implementiert; die gemeinsame
     * Ablauf-Logik lebt in {@see uploadSelectedImage()}.
     */
    abstract protected function uploadImage(int $id, string $filePath): WriteResult;

    /**
     * Lädt das gewählte Bild zum Datensatz $id hoch (zweistufig, nach dem
     * Stammdaten-Write). Ohne Auswahl oder ID ein No-Op. Gibt true zurück, wenn
     * der Upload fehlschlug — der Datensatz selbst bleibt dann gespeichert.
     */
    protected function uploadSelectedImage(?int $id): bool
    {
        if ($this->imagePath === null || $id === null) {
            return false;
        }

        return $this->uploadImage($id, $this->imagePath)->failed();
    }

    /**
     * Warn-Toast, wenn der Stammdaten-Write zwar gelang, aber das Bild nicht
     * hochgeladen werden konnte (vom Editor nach dem Erfolgs-Feedback aufgerufen).
     */
    protected function warnIfImageUploadFailed(bool $failed): void
    {
        if ($failed) {
            Flux::toast(text: __('Gespeichert, aber das Bild konnte nicht hochgeladen werden.'), variant: 'warning');
        }
    }

    /** Vorhandene Bild-URL für die Vorschau setzen (leere Werte → null). */
    protected function setCurrentImageUrl(?string $url): void
    {
        $this->currentImageUrl = filled($url) ? $url : null;
    }

    protected function imageUploadKey(): string
    {
        return 'image';
    }

    /** Native Kamera öffnen (Foto aufnehmen). */
    public function captureImage(): void
    {
        Camera::getPhoto()->id($this->imageUploadKey())->start();
    }

    /** Native Galerie öffnen (ein Bild wählen). */
    public function pickImage(): void
    {
        Camera::pickImages('image', false)->id($this->imageUploadKey())->start();
    }

    #[OnNative(PhotoTaken::class)]
    public function handlePhotoTaken(string $path, string $mimeType = 'image/jpeg', ?string $id = null): void
    {
        if ($id !== $this->imageUploadKey()) {
            return;
        }

        $this->setSelectedImage($path);
    }

    /**
     * @param  array<int, mixed>  $files
     */
    #[OnNative(MediaSelected::class)]
    public function handleMediaSelected(bool $success = false, array $files = [], int $count = 0, ?string $error = null, bool $cancelled = false, ?string $id = null): void
    {
        if ($id !== $this->imageUploadKey() || ! $success) {
            return;
        }

        $path = $this->firstFilePath($files);

        if ($path !== null) {
            $this->setSelectedImage($path);
        }
    }

    /** Auswahl verwerfen (zurück zum vorhandenen Bild bzw. Platzhalter). */
    public function clearSelectedImage(): void
    {
        $this->imagePath = null;
    }

    public function hasSelectedImage(): bool
    {
        return $this->imagePath !== null;
    }

    private function setSelectedImage(string $path): void
    {
        $this->imagePath = $path;
        $this->js("window.haptic && window.haptic('light')");
        $this->warnOnExpensiveNetwork();
    }

    /**
     * Hinweis-Toast, wenn das Bild beim Speichern über eine kostenpflichtige
     * (Mobilfunk) oder eingeschränkte Verbindung (iOS Low-Data-Modus) geladen
     * würde. Rein informativ — der Upload läuft trotzdem beim Speichern. Ohne
     * native Bridge (Web/Test) liefert Network::status() null → No-op.
     */
    private function warnOnExpensiveNetwork(): void
    {
        $status = Network::status();

        if ($status !== null && (($status->isExpensive ?? false) || ($status->isConstrained ?? false))) {
            Flux::toast(
                text: __('Mobile Verbindung — das Bild wird beim Speichern über Mobilfunk hochgeladen.'),
                variant: 'warning',
            );
        }
    }

    /**
     * Setzt den Bild-Zustand zurück (beim Öffnen/Schließen des Editors). Die
     * Vorschau-URL setzt der Editor beim Laden eines bestehenden Datensatzes.
     */
    protected function resetImageState(): void
    {
        $this->imagePath = null;
        $this->currentImageUrl = null;
    }

    /**
     * Der Datei-Pfad des ersten gewählten Eintrags. Die native Galerie liefert
     * je nach Plattform entweder einen String-Pfad oder ein Array mit
     * Pfad-Schlüssel — beides wird defensiv aufgelöst.
     *
     * @param  array<int, mixed>  $files
     */
    private function firstFilePath(array $files): ?string
    {
        $first = $files[0] ?? null;

        if (is_string($first)) {
            return $first;
        }

        if (is_array($first)) {
            return $first['path'] ?? $first['uri'] ?? $first['url'] ?? $first['realPath'] ?? null;
        }

        return null;
    }
}
