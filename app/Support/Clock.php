<?php

namespace App\Support;

use App\Services\AppPreferences;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * Brücke zwischen den UTC-Zeiten von DB/Portal-API und der vom Nutzer im Profil
 * gewählten Anzeige-Zeitzone ({@see AppPreferences::timezone()}, Default
 * Europe/Berlin).
 *
 * - Anzeige: {@see toDisplay()} (bzw. das Carbon-Macro `forDisplay()`) rechnet
 *   einen UTC-Zeitpunkt in die Nutzer-Zeitzone um, bevor er formatiert wird.
 * - Eingabe: {@see localToUtc()} rechnet die in den Editoren lokal eingegebene
 *   Zeit zurück nach UTC, wie die Portal-API sie erwartet.
 *
 * Alle Carbon-Zeitpunkte der App gelten als UTC (Laravel app.timezone) — die
 * Umrechnung passiert bewusst erst an der UI-Grenze, damit der geteilte
 * API-Cache zeitzonenneutral bleibt.
 */
final class Clock
{
    public static function timezone(): string
    {
        return app(AppPreferences::class)->timezone();
    }

    /**
     * UTC-Zeitpunkt in die Anzeige-Zeitzone des Nutzers umrechnen.
     */
    public static function toDisplay(CarbonInterface $instant): CarbonImmutable
    {
        return CarbonImmutable::instance($instant)->setTimezone(self::timezone());
    }

    /**
     * Lokal eingegebene Zeit ("Y-m-d H:i" in der Nutzer-Zeitzone) nach UTC
     * umrechnen und im selben Format für den Portal-Write zurückgeben.
     */
    public static function localToUtc(string $localDateTime): string
    {
        return CarbonImmutable::parse($localDateTime, self::timezone())
            ->utc()
            ->format('Y-m-d H:i');
    }

    /**
     * Liegt eine lokal eingegebene Zeit (Nutzer-Zeitzone) in der Vergangenheit?
     */
    public static function localIsPast(string $localDateTime): bool
    {
        return CarbonImmutable::parse($localDateTime, self::timezone())->isPast();
    }
}
