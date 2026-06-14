<?php

namespace App\Livewire\Concerns;

use App\Services\WriteResult;
use App\Services\WriteStatus;
use Flux\Flux;
use Livewire\Component;

/**
 * Gemeinsames Fehler-Feedback der Editor-Komponenten (Meetup/Termin/Venue/
 * Stadt): übersetzt ein {@see WriteResult} eines fehlgeschlagenen Portal-Writes
 * in Toast + Haptik und mappt 422-Feldfehler zurück an die Form-Felder.
 *
 * Die beiden editorspezifischen Stellen sind parametrisiert: der Forbidden-Text
 * (403) und ein optionales Feld-Mapping (z. B. das Portal-Feld `start` auf das
 * Datumsfeld `date` beim Termin-Editor).
 *
 * @mixin Component
 */
trait HandlesPortalWriteFeedback
{
    /**
     * Erfolgs-Feedback eines Portal-Writes: Sheet schließen, Erfolgs-Toast,
     * Listen-Refresh-Event und Erfolgs-Haptik. Gegenstück zu
     * {@see reportWriteFailure()}; den komponentenspezifischen State-Reset
     * macht der Aufrufer danach selbst.
     */
    protected function reportWriteSuccess(string $modal, string $toastText, string $event = 'meetup-saved'): void
    {
        Flux::modal($modal)->close();
        Flux::toast(text: $toastText, variant: 'success');
        $this->dispatch($event);
        $this->js("window.haptic && window.haptic('success')");
    }

    /**
     * @param  array<string, string>  $fieldMap  Portal-Feld → Form-Feld (für 422-Mapping).
     */
    protected function reportWriteFailure(WriteResult $result, string $forbiddenMessage, array $fieldMap = []): void
    {
        match ($result->status) {
            WriteStatus::ValidationError => $this->applyServerErrors($result, $fieldMap),
            WriteStatus::Forbidden => Flux::toast(text: $forbiddenMessage, variant: 'danger'),
            WriteStatus::Unauthorized => Flux::toast(text: __('Bitte verbinde zuerst dein Portal-Konto.'), variant: 'danger'),
            default => Flux::toast(text: __('Senden fehlgeschlagen. Bitte prüfe deine Verbindung und versuche es erneut.'), variant: 'danger'),
        };

        $this->js("window.haptic && window.haptic('error')");
    }

    /**
     * Server-Validierungsfehler (422) auf die einzelnen Form-Felder mappen,
     * zusätzlich zu einem zusammenfassenden Toast.
     *
     * @param  array<string, string>  $fieldMap  Portal-Feld → Form-Feld.
     */
    protected function applyServerErrors(WriteResult $result, array $fieldMap = []): void
    {
        foreach ($result->errors as $field => $messages) {
            $target = $fieldMap[$field] ?? $field;
            $this->addError("form.{$target}", $messages[0] ?? __('Ungültige Eingabe.'));
        }

        Flux::toast(text: __('Bitte prüfe die markierten Felder.'), variant: 'warning');
    }
}
