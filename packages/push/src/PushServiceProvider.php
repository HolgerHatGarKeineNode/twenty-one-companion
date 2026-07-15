<?php

namespace Einundzwanzig\Push;

use Illuminate\Support\ServiceProvider;

/**
 * Vom NativePHP-Plugin-System verlangter Provider-Einstiegspunkt. Push ist
 * zustandslos und dependency-frei — app(Push::class) löst ohne Binding auf,
 * daher kein register()-Body nötig.
 */
class PushServiceProvider extends ServiceProvider {}
