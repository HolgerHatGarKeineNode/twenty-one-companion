<?php

// Konvertiert von PHPUnit-nativer Klasse zu funktionaler Pest-Syntax
// (Test-Engineer, P2 „TIA aktivieren“, 2026-08-05) — Grund: Pests TIA-Modus
// verlangt ausschließlich funktionale Pest-Tests, siehe
// EnsureTiaIsRunningPestTestsOnly. `RefreshDatabase` stand auf der alten
// PHPUnit\Framework\TestCase ohnehin unbenutzt (das Trait braucht Laravels
// TestCase, keiner der Testfälle greift auf die Datenbank zu) und wurde beim
// Konvertieren bewusst nicht mitgenommen. Testwert unverändert: true ist true.
it('that true is true', function () {
    expect(true)->toBeTrue();
});
