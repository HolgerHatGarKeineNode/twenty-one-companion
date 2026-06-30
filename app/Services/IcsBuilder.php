<?php

namespace App\Services;

use Carbon\CarbonInterface;

final class IcsBuilder
{
    /**
     * Erzeugt ein RFC-5545-konformes VCALENDAR mit genau einem VEVENT.
     *
     * Ohne $end wird das Ende aus $durationMinutes berechnet (Meetups liefern
     * kein Ende). Kurs-Events haben ein echtes Ende und übergeben $end.
     */
    public function event(
        string $title,
        CarbonInterface $start,
        ?CarbonInterface $end = null,
        ?string $location = null,
        ?string $description = null,
        int $durationMinutes = 120,
    ): string {
        $startUtc = $start->copy()->utc();
        $endUtc = $end !== null ? $end->copy()->utc() : $startUtc->copy()->addMinutes($durationMinutes);
        $uid = bin2hex(random_bytes(16)).'@einundzwanzig';

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//Einundzwanzig//Mobile//DE',
            'BEGIN:VEVENT',
            'UID:'.$uid,
            'DTSTAMP:'.$startUtc->format('Ymd\THis\Z'),
            'DTSTART:'.$startUtc->format('Ymd\THis\Z'),
            'DTEND:'.$endUtc->format('Ymd\THis\Z'),
            'SUMMARY:'.$this->escape($title),
        ];

        if ($location !== null && $location !== '') {
            $lines[] = 'LOCATION:'.$this->escape($location);
        }

        if ($description !== null && $description !== '') {
            $lines[] = 'DESCRIPTION:'.$this->escape($description);
        }

        $lines[] = 'END:VEVENT';
        $lines[] = 'END:VCALENDAR';

        // ponytail: kein RFC-5545-Line-Folding (75 Oktette); Kalender-Apps tolerieren
        // lange Zeilen. Falten erst nachrüsten, wenn eine App eine Beschreibung ablehnt.
        return implode("\r\n", $lines)."\r\n"; // RFC 5545 verlangt CRLF
    }

    private function escape(string $value): string
    {
        // RFC 5545 §3.3.11: Backslash ZUERST, dann , ; und Zeilenumbrüche.
        // CR/CRLF/LF werden zum literalen \n normalisiert (kein bare CR im Wert).
        // Kein strip_tags: der Text ist Markdown, kein HTML — strip_tags würde bei
        // einem '<' gefolgt von Nicht-Whitespace (z. B. "<3", "<10€") alles bis zum
        // nächsten '>' verschlucken und so den Eintrag stillschweigend verstümmeln.
        return str_replace(
            ['\\', ',', ';', "\r\n", "\r", "\n"],
            ['\\\\', '\\,', '\\;', '\\n', '\\n', '\\n'],
            $value,
        );
    }
}
