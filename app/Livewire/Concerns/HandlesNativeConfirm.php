<?php

namespace App\Livewire\Concerns;

use Livewire\Component;
use Native\Mobile\Attributes\OnNative;
use Native\Mobile\Events\Alert\ButtonPressed;
use Native\Mobile\Facades\Dialog;

/**
 * Native Ja/Nein-Bestätigung vor destruktiven Aktionen. Ersetzt das
 * web-typische `wire:confirm` (window.confirm mit „Die Seite … sagt“) durch
 * einen nativen Dialog (NativePHP {@see Dialog::alert()} mit Buttons). Ablauf:
 *
 *  1. Der Button ruft {@see confirmAction()} mit einem Schlüssel + Nutzlast auf;
 *     der native Dialog wird angezeigt.
 *  2. Tippt der Nutzer den Bestätigen-Button (Index > 0, „Abbrechen“ ist 0),
 *     feuert NativePHP einen {@see ButtonPressed}-Event.
 *  3. {@see handleConfirmButton()} ruft dann die eigentliche Aktion über
 *     {@see onConfirmed()} auf — die jede nutzende Komponente selbst umsetzt.
 *
 * Da Sheets/Editoren global im Layout liegen, hört jede Komponente denselben
 * Event; der Schlüssel ({@see $confirmKey}) korreliert Dialog und Handler,
 * sodass nur die auslösende Komponente reagiert.
 *
 * Im Web-/Testkontext ohne native Bridge zeigt der Dialog nichts an; getestet
 * wird der Bestätigungs-Pfad über direktes Auslösen von {@see handleConfirmButton()}.
 *
 * @mixin Component
 */
trait HandlesNativeConfirm
{
    /** Schlüssel der aktuell offenen Bestätigung (null = keine offen). */
    public ?string $confirmKey = null;

    /**
     * Nutzlast, die bei Bestätigung an {@see onConfirmed()} zurückgereicht wird.
     *
     * @var array<string, mixed>
     */
    public array $confirmPayload = [];

    /**
     * Führt die bestätigte Aktion aus. Von der nutzenden Komponente
     * implementiert; $key unterscheidet mehrere Bestätigungstypen.
     *
     * @param  array<string, mixed>  $payload
     */
    abstract protected function onConfirmed(string $key, array $payload): void;

    /**
     * Nativen Bestätigungsdialog öffnen. „Abbrechen“ ist Index 0, der
     * Bestätigen-Button der letzte.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function confirmAction(string $key, string $title, string $message, string $confirmLabel, array $payload = []): void
    {
        $this->confirmKey = $key;
        $this->confirmPayload = $payload;

        Dialog::alert($title, $message, [__('Abbrechen'), $confirmLabel])
            ->id($key)
            ->event(ButtonPressed::class);
    }

    /**
     * Reagiert auf den nativen Button-Tap. Nur der zuletzt geöffnete Dialog
     * dieser Komponente (passender $id) und nur der Bestätigen-Button
     * (Index > 0) lösen die Aktion aus.
     */
    #[OnNative(ButtonPressed::class)]
    public function handleConfirmButton(int $index, string $label = '', ?string $id = null): void
    {
        if ($id === null || $id !== $this->confirmKey) {
            return;
        }

        $key = $this->confirmKey;
        $payload = $this->confirmPayload;

        $this->confirmKey = null;
        $this->confirmPayload = [];

        if ($index > 0) {
            $this->onConfirmed($key, $payload);
        }
    }
}
