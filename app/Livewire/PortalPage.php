<?php

namespace App\Livewire;

use App\Services\AppPreferences;
use App\Services\PortalApi;
use App\Support\Brand;
use Illuminate\Support\Str;
use Livewire\Attributes\On;
use Livewire\Component;
use Native\Mobile\Facades\Browser;
use Native\Mobile\Facades\Device;
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
     * Manueller Refresh über den Header-Button bzw. Pull-to-Refresh. Das
     * Layout-Chrome (Header/Main) liegt AUSSERHALB der Seiten-Komponente,
     * `wire:click` würde dort nicht binden — daher löst die Geste einen
     * GLOBALEN Livewire-Event aus, den nur die aktuelle Seite (als einzige
     * PortalPage mit diesem Listener) behandelt.
     *
     * refresh() erhöht die Cache-Generation (verwirft den Fresh-Cache); der
     * Re-Render holt frisch. `portal-refreshed` bestätigt dem Layout-Alpine
     * den Abschluss (Spinner stoppen). Das native Feedback („Aktualisiert.“
     * bzw. Offline-/Fehler-Hinweis) läuft über denselben dehydrate()-Pfad
     * wie retry().
     */
    #[On('portal-refresh')]
    public function refreshPortalData(): void
    {
        app(PortalApi::class)->refresh();
        $this->retrying = true;
        $this->dispatch('portal-refreshed');
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
            $this->vibrate();
            Dialog::alert(
                __('Keine Verbindung'),
                __('Du bist offline. Stelle eine Internetverbindung her und versuche es erneut.'),
            );
        } elseif ($portalApi->hasMissingData()) {
            $this->vibrate();
            Dialog::alert(
                __('Portal nicht erreichbar'),
                __('Das EINUNDZWANZIG-Portal ist gerade nicht erreichbar. Bitte versuche es später noch einmal.'),
            );
        } else {
            $this->vibrate();
            Dialog::toast(__('Aktualisiert.'));
        }
    }

    /**
     * Natives haptisches Feedback bei Schlüssel-Aktionen (Phase 1.3). Ergänzt
     * das sofortige clientseitige Tap-Feedback (window.haptic) um eine
     * bestätigende Vibration, sobald die serverseitige Aktion durch ist.
     * Im Web-/Test-Kontext ist Device::vibrate() ein geguardeter No-op.
     */
    protected function vibrate(): void
    {
        Device::vibrate();
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
     * Den Länder-Filter mit der App-Region verdrahten: speichert die Wahl als
     * Region und löst bei einem echten Markenwechsel die Vollbild-Zelebrierung
     * (`brand-changed`) aus — dieselbe wie im Profil/Onboarding. Das Top-Bar-
     * Logo (x-brand-wordmark-live) lauscht auf dasselbe Event.
     */
    protected function syncBrand(string $country): void
    {
        $preferences = app(AppPreferences::class);
        $previous = Brand::forCountry($preferences->country());

        $preferences->setCountry($country);

        $brand = Brand::forCountry($country);

        if ($brand !== $previous) {
            $this->dispatch('brand-changed', slug: $brand->value, label: $brand->label());
        }
    }

    /**
     * Hosts mit eigener nativer App: solche Links im System-Browser öffnen,
     * damit der Intent an die App durchgereicht wird (Telegram/Signal/… gehen
     * dann direkt auf) statt im In-App-Tab zu stranden.
     *
     * @var list<string>
     */
    private const EXTERNAL_APP_HOSTS = [
        't.me', 'telegram.me', 'telegram.dog',
        'signal.me', 'signal.group',
        'matrix.to',
        'wa.me', 'chat.whatsapp.com', 'api.whatsapp.com',
    ];

    /**
     * Portal-/Web-Links im In-App-Browser (Custom Tab / SFSafariViewController)
     * öffnen: der Nutzer bleibt in der App, Zurück-Wisch führt zurück. Nur
     * http(s) wird geöffnet — die URLs stammen aus Portal-Daten, andere Schemes
     * (nostrsigner:, intent: …) wären Intent-Injection. Messenger-Hosts mit
     * eigener App ({@see self::EXTERNAL_APP_HOSTS}) gehen weiter über den
     * System-Browser, damit ihre App den Link übernehmen kann.
     */
    public function openLink(string $url): void
    {
        if (! Str::startsWith($url, ['https://', 'http://'])) {
            return;
        }

        $host = Str::chopStart(mb_strtolower((string) parse_url($url, PHP_URL_HOST)), 'www.');

        if (in_array($host, self::EXTERNAL_APP_HOSTS, true)) {
            Browser::open($url);

            return;
        }

        Browser::inApp($url);
    }
}
