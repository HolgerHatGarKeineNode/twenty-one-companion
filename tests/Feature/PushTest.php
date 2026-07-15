<?php

use App\Services\AppPreferences;
use Einundzwanzig\Push\Push;

/**
 * Die eigentliche Logik ist Kotlin (RelayPollWorker) und nur auf dem Gerät
 * prüfbar — hier wird nur abgesichert, dass der PHP-Aufruf ohne Gerät sauber
 * false liefert statt zu werfen.
 *
 * Achtung: `nativephp_call` EXISTIERT auch im Test (nativephp/mobile lädt
 * jump_bridge_functions.php für den Jump-Modus). Der function_exists-Guard
 * greift hier also nicht — was trägt, ist das catch(Throwable) um den
 * JumpBridge-Aufruf, der ohne verbundenes Gerät scheitert.
 */
it('gibt ohne verbundenes Gerät false zurück statt zu werfen', function () {
    expect((new Push)->pollNow())->toBeFalse();
});

/**
 * secp256k1-kmp (NIP-46-Signatur im Worker) lädt seine JNI-Klasse per
 * Class.forName — ohne Keep-Regel wirft R8 sie aus dem Release-Build und der
 * Worker stirbt an „Could not load native Secp256k1 JNI library". Am Gerät
 * passiert und verifiziert (plans/PUSH-NOTIFICATIONS.md §5).
 *
 * Der Test hängt an der Config, weil die Build-Ausgabe (proguard-rules.pro)
 * nicht im Repo liegt. Debug-Builds nutzen kein R8, die Suite baut kein APK —
 * ohne diesen Test fällt ein Verlust der Regel erst im Release auf.
 */
it('behält die Keep-Regel für secp256k1, sonst crasht der NIP-46-Worker im Release', function () {
    expect(config('nativephp.android.build.custom_proguard_rules'))
        ->toContain('-keep class fr.acinq.secp256k1.** { *; }');
});

describe('push/sync', function () {
    $state = [
        'pubkey' => str_repeat('a1', 32),
        'relay' => 'wss://group.einundzwanzig.space/',
        'rooms' => ['general'],
    ];

    it('plant nichts, solange der Schalter aus ist', function () use ($state) {
        app(AppPreferences::class)->setPushEnabled(false);

        $this->postJson(route('push.sync'), $state)
            ->assertOk()
            ->assertJson(['scheduled' => false]);
    });

    it('weist unbrauchbaren Zustand zurück, statt 422 zu werfen', function () use ($state) {
        // Der Zustand kommt aus dem Client und wandert in einen Hintergrund-Job,
        // der damit ein Auth-Event signieren lässt und einen Socket öffnet —
        // hier ist die Trust-Grenze. Antwort bleibt 200: „nichts zu tun" ist
        // der Normalfall (ausgeloggt), kein Fehler.
        app(AppPreferences::class)->setPushEnabled(true);

        $bad = [
            ['pubkey' => ''],
            ['pubkey' => 'npub1abc'],
            ['pubkey' => str_repeat('z', 64)],
            ['pubkey' => str_repeat('a', 63)],
            ['relay' => 'http://evil.example'],
            ['relay' => ''],
            ['rooms' => []],
            ['rooms' => 'general'],
        ];

        foreach ($bad as $override) {
            $this->postJson(route('push.sync'), [...$state, ...$override])
                ->assertOk()
                ->assertJson(['scheduled' => false]);
        }
    });

    it('nimmt POST ohne CSRF-Token an', function () use ($state) {
        // Der Partial läuft als nacktes Script in beiden Layouts und hat kein
        // Token — genau daran ist die Livewire-Variante mit 419 gescheitert.
        $this->post(route('push.sync'), $state)->assertOk();
    });

    it('liegt ausserhalb des Onboarding-Gates', function () use ($state) {
        // Sonst würde EnsureOnboarded den Sync umleiten, statt ihn auszuführen.
        resetOnboarding();

        $this->postJson(route('push.sync'), $state)->assertOk();
    });
});
