<?php

namespace App\Livewire\Concerns;

use App\Services\IcsBuilder;
use Carbon\CarbonInterface;
use Einundzwanzig\Calendar\Calendar;
use Native\Mobile\Facades\Share;

trait InteractsWithCalendar
{
    /**
     * Einen Termin „zum Kalender hinzufügen": bevorzugt der native
     * „Termin anlegen"-Editor (Android: ACTION_INSERT); fällt sonst (iOS/Web)
     * auf einen .ics-Export über das native Share-Sheet zurück.
     */
    protected function exportToCalendar(
        string $title,
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $location,
        ?string $description,
        string $filename,
    ): void {
        if (app(Calendar::class)->addEvent($title, $start, $end, $location, $description)) {
            return;
        }

        $ics = app(IcsBuilder::class)->event(
            title: $title,
            start: $start,
            end: $end,
            location: $location,
            description: $description,
        );

        $path = storage_path('app/'.$filename.'.ics');
        file_put_contents($path, $ics);

        Share::file(
            title: $title,
            text: __('Termin zum Kalender hinzufügen'),
            filePath: $path,
        );
    }
}
