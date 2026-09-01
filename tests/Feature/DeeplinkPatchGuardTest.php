<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Control for the deeplink guard in `scripts/apply-vendor-patches.sh`.
 *
 * Until 2026-09-01 a missing `RunsAndroid.php` printed "übersprungen (nicht
 * vorhanden)" and the script still exited 0. That is how v1.9.4 got built with an
 * APK claiming the WHOLE portal host: `composer update` had overwritten the
 * hand-applied patch and nothing said a word. NativePHP 4.x moves that very file
 * from `src/Traits/` to `src/Concerns/`, so the silent skip was one upgrade away
 * from repeating it.
 *
 * Known-bad and known-good in one file, the same shape the accessibility harness
 * uses (`tests/Browser/Accessibility/*`): a guard that has only ever been seen
 * green is indistinguishable from a guard that cannot fire at all.
 *
 * The script always runs in a throw-away tree, never against this repo — it edits
 * vendor files in place.
 */
beforeEach(function (): void {
    $this->sandbox = sys_get_temp_dir().'/deeplink-guard-'.uniqid();
});

afterEach(function (): void {
    File::deleteDirectory($this->sandbox);
});

/**
 * Builds a tree that `apply-vendor-patches.sh` accepts as a work target.
 *
 * The two Kotlin fixtures carry the marker string of every boot-time patch, so each
 * patch function bails out idempotently and the run measures the deeplink branch
 * alone. The remaining targets (WebViewManager, icon, gradle) are absent on purpose:
 * the script only patches them when the file exists.
 *
 * @param  string|null  $vendorTarget  path of the vendor PHP file to create, relative
 *                                     to the sandbox root — null leaves it missing
 */
function deeplinkSandbox(string $sandbox, ?string $vendorTarget = null): void
{
    $kotlin = $sandbox.'/nativephp/android/app/src/main/java/com/nativephp/mobile';

    File::ensureDirectoryExists($sandbox.'/scripts');
    File::ensureDirectoryExists($kotlin.'/bridge');
    File::ensureDirectoryExists($kotlin.'/ui');

    File::copy(base_path('scripts/apply-vendor-patches.sh'), $sandbox.'/scripts/apply-vendor-patches.sh');
    File::put($kotlin.'/bridge/LaravelEnvironment.kt', <<<'KT'
        // opcache.file_cache
        // OPTIMIZE-opcache-wipe
        // EXTRACT-GATE-FIX
        KT);
    File::put($kotlin.'/ui/MainActivity.kt', <<<'KT'
        // postDelayed({ queueWorker
        // FILE_CHOOSER_REQUEST_CODE
        KT);

    if ($vendorTarget !== null) {
        File::ensureDirectoryExists(dirname($sandbox.'/'.$vendorTarget));
        // Already carries the patch marker, so patch_deeplinks returns early instead
        // of rewriting a fixture that is not the real vendor source.
        File::put($sandbox.'/'.$vendorTarget, <<<'PHP'
            <?php
            $prefixes = config('nativephp.deeplink_path_prefixes') ?: ['/'];
            PHP);
    }
}

function runPatchScript(string $sandbox): Process
{
    $process = new Process(['bash', 'scripts/apply-vendor-patches.sh'], $sandbox);
    $process->run();

    return $process;
}

it('exits non-zero when the deeplink target is not where the script expects it', function (?string $vendorTarget): void {
    deeplinkSandbox($this->sandbox, $vendorTarget);

    $process = runPatchScript($this->sandbox);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Deeplink-Ziel nicht vorhanden')
        ->and($process->getOutput())->not->toContain('Fertig.');
})->with([
    'vendor tree missing entirely' => [null],
    'moved to src/Concerns as NativePHP 4.x does' => ['vendor/nativephp/mobile/src/Concerns/RunsAndroid.php'],
]);

it('completes when the deeplink target is in place', function (): void {
    deeplinkSandbox($this->sandbox, 'vendor/nativephp/mobile/src/Traits/RunsAndroid.php');

    $process = runPatchScript($this->sandbox);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('Deeplink-Pfade bereits eingeschraenkt')
        ->and($process->getOutput())->toContain('Fertig.');
});
