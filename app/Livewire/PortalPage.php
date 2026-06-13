<?php

namespace App\Livewire;

use App\Services\AppPreferences;
use App\Services\PortalApi;
use Illuminate\Support\Str;
use Livewire\Component;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Dialog;

/**
 * Basisklasse der Portal-Modulseiten (SFC unter resources/views/pages/):
 * bündelt Actions, die alle Module teilen.
 */
abstract class PortalPage extends Component
{
    /**
     * Nur innerhalb des Requests gesetzt (nicht zum Client synchronisiert):
     * markiert einen manuellen „Erneut versuchen“-Klick, damit dehydrate()
     * nach dem Re-Render natives Feedback geben kann.
     */
    protected bool $retrying = false;

    /**
     * Läuft bei jedem Livewire-Request (mount + Updates): Status-Flags der
     * scoped PortalApi zurücksetzen, damit Banner/Fehler-States immer den
     * aktuellen Request widerspiegeln.
     */
    public function boot(): void
    {
        app(PortalApi::class)->resetStatus();
    }

    /**
     * „Erneut versuchen“ aus dem Fehler-State: der Re-Render läuft ohnehin
     * gegen die API (Fehlschläge werden nie gecacht) — hier wird nur das
     * Feedback nach dem Render angestoßen.
     */
    public function retry(): void
    {
        $this->retrying = true;
    }

    /**
     * Natives Feedback nach einem manuellen Retry: dehydrate() läuft nach
     * dem Re-Render, der Ausgang des Versuchs ist hier also bekannt.
     */
    public function dehydrate(): void
    {
        if (! $this->retrying) {
            return;
        }

        $portalApi = app(PortalApi::class);

        if ($portalApi->isOffline()) {
            Dialog::alert(
                __('Keine Verbindung'),
                __('Du bist offline. Stelle eine Internetverbindung her und versuche es erneut.'),
            );
        } elseif ($portalApi->hasMissingData()) {
            Dialog::alert(
                __('Portal nicht erreichbar'),
                __('Das Einundzwanzig-Portal ist gerade nicht erreichbar. Bitte versuche es später noch einmal.'),
            );
        } else {
            Dialog::toast(__('Aktualisiert.'));
        }
    }

    /**
     * Startwert für die `$country`-Url-Property der Seite: ein expliziter
     * country-Query-Param gewinnt (geteilte/gespeicherte Links mit Filter),
     * sonst gilt die Onboarding-Region.
     */
    protected function defaultCountry(): string
    {
        if (request()->query->has('country')) {
            return (string) request()->query('country');
        }

        return app(AppPreferences::class)->country();
    }

    /**
     * Externe Links im System-Browser öffnen, damit z. B. Telegram-Links
     * direkt in der passenden App landen. Nur http(s) wird geöffnet — die
     * URLs stammen aus Portal-Daten, andere Schemes (nostrsigner:,
     * intent: …) wären Intent-Injection.
     */
    public function openLink(string $url): void
    {
        if (Str::startsWith($url, ['https://', 'http://'])) {
            Browser::open($url);
        }
    }
}
