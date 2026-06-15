<?php

namespace App\Enums;

/**
 * RSVP-Status des Nutzers für einen Meetup-Termin, gespiegelt vom Portal.
 * `None` = in keiner Liste (abgesagt / nie zugesagt) und zugleich der Wert
 * zum Austragen.
 */
enum RsvpStatus: string
{
    case Attending = 'attending';
    case Maybe = 'maybe';
    case None = 'none';
}
