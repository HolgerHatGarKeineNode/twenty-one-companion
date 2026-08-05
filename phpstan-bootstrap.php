<?php

declare(strict_types=1);

// PHPStan hat keinen `parameters: memoryLimit:`-Konfigurationsschlüssel - das
// wurde vor Einsatz dieser Datei empirisch verifiziert (Fehler "Unexpected
// item 'parameters › memoryLimit'." bei einem entsprechenden Versuch).
// `memoryLimit` existiert ausschließlich als CLI-Option (`--memory-limit`).
// Dieser Bootstrap-Weg ist der von PHPStan dafür vorgesehene Mechanismus
// (`parameters: bootstrapFiles:`), er läuft vor der Container-Kompilierung
// und damit vor der eigentlichen Analyse.
ini_set('memory_limit', '1G');
