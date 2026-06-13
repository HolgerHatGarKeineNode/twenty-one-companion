<?php

namespace App\Services;

/**
 * Ausgang eines schreibenden Portal-Aufrufs. Trennt die fachlich
 * unterschiedlichen Fehlerklassen, damit die UI gezielt reagieren kann:
 * Feldfehler an die Form (422), „Bitte verbinden“ (401), „Keine Rechte“
 * (403) und „später erneut versuchen“ (Netz-/Serverfehler).
 */
enum WriteStatus: string
{
    case Success = 'success';

    case ValidationError = 'validation_error';

    case Unauthorized = 'unauthorized';

    case Forbidden = 'forbidden';

    case NetworkFailure = 'network_failure';
}
