<?php

namespace App\Services;

use Illuminate\Support\Facades\File;

/**
 * Hält den launchMode-Fix im generierten Android-Manifest am Leben.
 *
 * NativePHP scaffoldet `nativephp/android` bei `native:install` neu aus dem
 * Vendor-Template, das die MainActivity mit `launchMode="singleTop"` anlegt.
 * singleTop lässt externe Deep-Link-Intents (z. B. den Amber-Signer-Callback
 * `einundzwanzig://signed/...`) eine Wegwerf-Activity im Task des Aufrufers
 * erzeugen, statt die laufende App-Instanz per onNewIntent zu erreichen.
 * `singleTask` routet den Intent an die bestehende Instanz und räumt darüber
 * liegende Activities (Custom Tabs) automatisch ab. Siehe PLAN.md 1.20/1.21.
 */
class AndroidManifestPatcher
{
    protected const SEARCH = 'android:launchMode="singleTop"';

    protected const REPLACE = 'android:launchMode="singleTask"';

    /** Amber-Package — Marker + Ziel des <queries>-Blocks (Android-11+-Sichtbarkeit). */
    protected const AMBER_PACKAGE = 'com.greenart7c3.nostrsigner';

    protected const QUERIES_BLOCK = <<<'XML'

    <!-- Amber (NIP-55 Signer): Package-Visibility für ContentResolver-Signieren (Android 11+). -->
    <queries>
        <package android:name="com.greenart7c3.nostrsigner" />
        <intent>
            <action android:name="android.intent.action.VIEW" />
            <data android:scheme="nostrsigner" />
        </intent>
    </queries>

XML;

    public function __construct(protected ?string $manifestPath = null) {}

    public function manifestPath(): string
    {
        return $this->manifestPath ?? base_path('nativephp/android/app/src/main/AndroidManifest.xml');
    }

    /**
     * Ersetzt singleTop durch singleTask. Idempotent; liefert true nur,
     * wenn die Datei tatsächlich geändert wurde.
     */
    public function ensureSingleTask(): bool
    {
        $path = $this->manifestPath();

        if (! File::exists($path)) {
            return false;
        }

        $contents = File::get($path);

        if (! str_contains($contents, self::SEARCH)) {
            return false;
        }

        File::put($path, str_replace(self::SEARCH, self::REPLACE, $contents));

        return true;
    }

    /**
     * Fügt den Amber-<queries>-Block ein (vor <application>), damit die App auf
     * Android 11+ Ambers ContentProvider für das lokale NIP-55-Signieren sehen darf.
     * Idempotent; true nur bei tatsächlicher Änderung.
     */
    public function ensureAmberQueries(): bool
    {
        $path = $this->manifestPath();

        if (! File::exists($path)) {
            return false;
        }

        $contents = File::get($path);

        if (str_contains($contents, self::AMBER_PACKAGE)) {
            return false;
        }

        $patched = preg_replace('/(\R\s*<application\b)/', self::QUERIES_BLOCK.'$1', $contents, 1);

        if ($patched === null || $patched === $contents) {
            return false;
        }

        File::put($path, $patched);

        return true;
    }

    /** Beide Manifest-Patches anwenden (singleTask + Amber-queries). */
    public function ensureAll(): bool
    {
        $singleTask = $this->ensureSingleTask();
        $queries = $this->ensureAmberQueries();

        return $singleTask || $queries;
    }

    public function isPatched(): bool
    {
        $path = $this->manifestPath();

        if (! File::exists($path)) {
            return false;
        }

        $contents = File::get($path);

        return str_contains($contents, self::REPLACE)
            && str_contains($contents, self::AMBER_PACKAGE);
    }
}
