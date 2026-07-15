<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Push-Diagnose
    |--------------------------------------------------------------------------
    |
    | Schaltet die Route `debug/push-poll` frei, die EINEN Poll-Lauf sofort
    | auslöst (plans/PUSH-NOTIFICATIONS.md). Ohne sie ist der Worker am Gerät
    | kaum zu beobachten: der echte Takt ist 15 Minuten, und Force-Läufe per
    | `cmd jobscheduler run -f` bringen nichts — WorkManager plant dann nur um,
    | statt zu laufen.
    |
    | Muss für Releases false sein (Default). Zum Testen im Emulator in .env:
    | PUSH_DEBUG=true
    |
    */

    'enabled' => env('PUSH_DEBUG', false),
];
