<?php

namespace App\Livewire;

use Illuminate\Support\Str;
use Livewire\Component;
use Native\Mobile\Facades\Browser;

/**
 * Basisklasse der Portal-Modulseiten (SFC unter resources/views/pages/):
 * bündelt Actions, die alle Module teilen.
 */
abstract class PortalPage extends Component
{
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
