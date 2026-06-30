<?php

namespace Einundzwanzig\Calendar;

use Carbon\CarbonInterface;
use Throwable;

class Calendar
{
    /**
     * Öffnet den nativen „Termin anlegen"-Editor (Android: ACTION_INSERT auf
     * CalendarContract), vorbefüllt mit Titel/Zeit/Ort/Beschreibung.
     *
     * Gibt true zurück, wenn der native Intent ausgelöst wurde. Auf Plattformen
     * ohne diese Bridge-Funktion (iOS) bzw. außerhalb der nativen Laufzeit
     * (Web/Tests) wird false geliefert — der Aufrufer fällt dann auf den
     * .ics-Export via Share-Sheet zurück.
     */
    public function addEvent(
        string $title,
        CarbonInterface $start,
        CarbonInterface $end,
        ?string $location = null,
        ?string $description = null,
    ): bool {
        if (! function_exists('nativephp_call')) {
            return false;
        }

        $params = [
            'title' => $title,
            'beginTime' => $start->getTimestampMs(),
            'endTime' => $end->getTimestampMs(),
            'location' => $location ?? '',
            'description' => $description ?? '',
        ];

        try {
            $raw = nativephp_call('Calendar.Insert', json_encode($params, JSON_THROW_ON_ERROR));
        } catch (Throwable) {
            return false;
        }

        $decoded = json_decode((string) $raw, true);

        return is_array($decoded) && ($decoded['added'] ?? false) === true;
    }
}
