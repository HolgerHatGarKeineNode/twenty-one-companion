<?php

namespace Einundzwanzig\Calendar;

use Illuminate\Support\ServiceProvider;

/**
 * Vom NativePHP-Plugin-System verlangter Provider-Einstiegspunkt. Calendar ist
 * zustandslos und dependency-frei — app(Calendar::class) löst ohne Binding auf,
 * daher kein register()-Body nötig.
 */
class CalendarServiceProvider extends ServiceProvider {}
