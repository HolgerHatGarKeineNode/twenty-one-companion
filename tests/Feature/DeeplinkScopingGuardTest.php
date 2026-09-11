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
 * hand-applied patch and nothing said a word.
 *
 * On 2026-09-11 the patch itself went away: nativephp/mobile 4.4.0 reads
 * `config('nativephp.deeplink_paths')` and builds the `<data>` elements in
 * `deepLinkPathData()` (PR #403), which is the same scoping the local patch used to
 * force. What stays is the guard — now a VERIFY step, because a downgrade below
 * 4.4.0 or an upstream rewrite of that method would take the scoping back silently,
 * and silently taking it back is exactly what happened in v1.9.4.
 *
 * The verify step itself changed the same day from a textual check (do the strings
 * `config('nativephp.deeplink_paths'` and `deepLinkPathData` appear anywhere in the
 * file?) to a behavioral one: `scripts/deeplink-scoping-probe.php` requires the target
 * file and calls its `generateDeepLinkFilters()` via reflection with a known probe
 * host/path, then reads the `<data>` element back. The textual version is falsified by
 * `runsAndroidToterCode()` below — both markers sit in a comment while
 * `generateDeepLinkFilters()` unconditionally claims the whole host, which a `grep -q`
 * check cannot tell apart from a marker that is actually load-bearing.
 *
 * Known-bad and known-good in one file, the same shape the accessibility harness
 * uses (`tests/Browser/Accessibility/*`): a guard that has only ever been seen
 * green is indistinguishable from a guard that cannot fire at all. The known-bad
 * side therefore carries every failure direction — the file is gone, the file is
 * there but no longer scopes anything, and the file merely LOOKS like it scopes.
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

const SCOPING_TARGET = 'vendor/nativephp/mobile/src/Concerns/RunsAndroid.php';

/**
 * `RunsAndroid.php` in the 4.4.0 shape: reads the configured paths and turns them
 * into `<data>` elements. Reduced to what `deeplink-scoping-probe.php` actually
 * exercises, but functionally faithful to nativephp/mobile 4.4.0 — the probe calls
 * `generateDeepLinkFilters()` for real (via reflection), so a fixture carrying only
 * dead markers does not fool it the way it fooled the old `grep -q` check.
 */
function runsAndroidVierVier(): string
{
    return <<<'PHP'
        <?php

        namespace Native\Mobile\Concerns;

        trait RunsAndroid
        {
            private function updateDeepLinkConfiguration(): void
            {
                $paths = config('nativephp.deeplink_paths', []);
                $this->generateDeepLinkFilters(null, config('nativephp.deeplink_host'), $paths);
            }

            private function generateDeepLinkFilters(?string $scheme, ?string $host, array $paths = []): string
            {
                if (! $host) {
                    return '';
                }

                $data = $this->deepLinkPathData($host, $paths);

                return $data === null ? '' : "<intent-filter android:autoVerify=\"true\">\n{$data}\n</intent-filter>";
            }

            private function deepLinkPathData(string $host, array $paths): ?string
            {
                $prefixes = [];

                foreach ($paths as $path) {
                    $path = trim((string) $path);

                    if ($path === '') {
                        continue;
                    }

                    $path = '/'.ltrim($path, '/');
                    $prefixes[$path] = true;
                }

                $prefix = '                <data android:scheme="https" android:host="'.$host.'" ';

                if ($prefixes === []) {
                    return $paths === [] ? $prefix.'android:pathPrefix="/" />' : null;
                }

                $lines = [];

                foreach (array_keys($prefixes) as $path) {
                    $attribute = str_ends_with($path, '/') ? 'pathPrefix' : 'path';
                    $lines[] = $prefix.'android:'.$attribute.'="'.$path.'" />';
                }

                return implode("\n", $lines);
            }
        }
        PHP;
}

/**
 * The 4.3.2 shape, i.e. what a downgrade puts back: the host is claimed whole and
 * nothing reads a path configuration. This is the file the local patch used to
 * rewrite — unpatched it is the v1.9.4 defect. `generateDeepLinkFilters()` takes only
 * `$scheme`/`$host` here, matching the real pre-4.4.0 signature; the probe always
 * calls it with a third argument regardless, which PHP simply ignores.
 */
function runsAndroidVierDrei(): string
{
    return <<<'PHP'
        <?php

        namespace Native\Mobile\Concerns;

        trait RunsAndroid
        {
            private function generateDeepLinkFilters(?string $scheme, ?string $host): string
            {
                if (! $host) {
                    return '';
                }

                return '<intent-filter android:autoVerify="true"><data android:scheme="https" android:host="'.$host.'" android:pathPrefix="/" /></intent-filter>';
            }
        }
        PHP;
}

/**
 * What falsified the OLD textual check (found in review, 2026-09-11): both markers
 * `grep -q` looked for — `config('nativephp.deeplink_paths'` and `deepLinkPathData` —
 * sit in a plain comment, while `generateDeepLinkFilters()` claims the whole host
 * unconditionally, exactly as `runsAndroidVierDrei()` does. The old check read the
 * comment as proof of scoping; the probe calls the method and reads what it actually
 * returns.
 */
function runsAndroidToterCode(): string
{
    return <<<'PHP'
        <?php

        namespace Native\Mobile\Concerns;

        trait RunsAndroid
        {
            private function generateDeepLinkFilters(?string $scheme, ?string $host, array $paths = []): string
            {
                // config('nativephp.deeplink_paths', []) is read by the caller upstream;
                // deepLinkPathData() used to scope this per path, now inlined below.
                if (! $host) {
                    return '';
                }

                return '<intent-filter android:autoVerify="true"><data android:scheme="https" android:host="'.$host.'" android:pathPrefix="/" /></intent-filter>';
            }
        }
        PHP;
}

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
 * @param  string|null  $inhalt  content of that file, defaults to the 4.4.0 shape
 */
function deeplinkSandbox(string $sandbox, ?string $vendorTarget = null, ?string $inhalt = null): void
{
    $kotlin = $sandbox.'/nativephp/android/app/src/main/java/com/nativephp/mobile';

    File::ensureDirectoryExists($sandbox.'/scripts');
    File::ensureDirectoryExists($kotlin.'/bridge');
    File::ensureDirectoryExists($kotlin.'/ui');

    File::copy(base_path('scripts/apply-vendor-patches.sh'), $sandbox.'/scripts/apply-vendor-patches.sh');
    // The behavioral probe pruefe_deeplink_scoping() shells out to, relative to the
    // script's own directory — has to sit next to it in every sandbox as well.
    File::copy(base_path('scripts/deeplink-scoping-probe.php'), $sandbox.'/scripts/deeplink-scoping-probe.php');
    // Phase 3 is satisfied by its marker alone. Phase 3b is not: it measures WHERE the
    // wipe sits — inside `fun initialize()`, the cold boot path — so this fixture has
    // to carry the 4.3.1 boot shape even though the deeplink branch is what is
    // measured here. Its own controls live in VendorPatchHalfStateTest.
    File::put($kotlin.'/bridge/LaravelEnvironment.kt', <<<'KT'
        // opcache.file_cache
            fun initialize() {
                extractionLock.withLock {
                    val didExtract = extractLaravelBundleUnlocked()
                    if (didExtract) runCatching { } // OPTIMIZE-opcache-wipe
                    setupEnvironment(didExtract)
                }
            }

            fun initializeForBackground() {
                val didExtract = extractLaravelBundle()
                if (didExtract) runCatching { } // OPTIMIZE-opcache-wipe
            }
        KT);
    File::put($kotlin.'/ui/MainActivity.kt', <<<'KT'
        // FILE_CHOOSER_REQUEST_CODE
        KT);

    if ($vendorTarget !== null) {
        File::ensureDirectoryExists(dirname($sandbox.'/'.$vendorTarget));
        File::put($sandbox.'/'.$vendorTarget, $inhalt ?? runsAndroidVierVier());
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
        ->and($process->getErrorOutput())->toContain('Deeplink-Scoping nicht nachweisbar')
        ->and($process->getErrorOutput())->toContain('Datei nicht vorhanden')
        ->and($process->getOutput())->not->toContain('Fertig.');
})->with([
    'vendor tree missing entirely' => [null],
    'still at the pre-4.x src/Traits location' => ['vendor/nativephp/mobile/src/Traits/RunsAndroid.php'],
]);

it('exits non-zero when the vendor file no longer scopes the claimed paths', function (string $inhalt, string $erwarteterFehler): void {
    deeplinkSandbox($this->sandbox, SCOPING_TARGET, $inhalt);

    $process = runPatchScript($this->sandbox);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Deeplink-Scoping nicht nachweisbar')
        ->and($process->getErrorOutput())->toContain($erwarteterFehler)
        // The whole-host claim is the damage this guard stands in front of, so the
        // message has to name it rather than merely report a missing string.
        ->and($process->getErrorOutput())->toContain('GANZEN Portal-Host')
        ->and($process->getOutput())->not->toContain('Fertig.');
})->with([
    // A downgrade below 4.4.0 — the file is there, reads no paths and claims the
    // whole host, which is byte for byte the state that shipped as v1.9.4. The probe
    // never sees its configured path in the output at all, since this shape ignores
    // $paths entirely.
    'downgraded to the 4.3.2 shape' => [
        runsAndroidVierDrei(),
        'liefert den konfigurierten Pfad',
    ],
    // Upstream keeps the config key but rebuilds the method the scoping lives in.
    // Reading the key proves nothing on its own: the value has to reach a <data>
    // element, and deepLinkPathData() is where that happens — renaming it makes
    // generateDeepLinkFilters() throw when it tries to call it.
    'config key read, path builder gone' => [
        str_replace('private function deepLinkPathData', 'private function irgendwasAnderes', runsAndroidVierVier()),
        'Call to undefined method',
    ],
    // What actually fooled the OLD grep-based check (found in review, 2026-09-11):
    // both markers sit in a comment, and generateDeepLinkFilters() claims the whole
    // host regardless of what nativephp.deeplink_paths says. `grep -q` read the
    // comment as proof; the probe calls the method and reads what comes back.
    'dead code: markers in a comment, whole host claimed for real' => [
        runsAndroidToterCode(),
        'liefert den konfigurierten Pfad',
    ],
    // The probe REQUIRES the target, so the target gets to decide when the process
    // ends. `exit(0)` before the assertions is not catchable, prints nothing and
    // leaves status 0 behind — for one day (2026-09-11) the guard read only that
    // status and answered "Verhaltensprobe bestanden" on a one-line file. The probe
    // now signs its verdict with a nonce the caller generates, so a target that
    // never reaches the assertions cannot produce the receipt.
    'target exits before the probe can assert anything' => [
        "<?php exit(0);\n",
        'ohne Quittung',
    ],
    // And the receipt has to be unforgeable, not merely present: a target printing
    // its own "ok" line would satisfy any fixed success token. The nonce is drawn
    // per run and cannot be inside a file that was written earlier.
    'target forges a success line with a foreign token' => [
        "<?php echo \"ok 00112233445566778899aabbccddeeff\\n\"; exit(0);\n",
        'ohne Quittung',
    ],
    // Guessing was never the threat — READING was. The first nonce version handed the
    // value to the target in $argv[2], where every required file can read it, and
    // three lines produced a valid receipt without the method ever running (review
    // finding, 2026-09-11). The probe now loads the target from inside a function
    // body with argv emptied, so neither the superglobals nor a leaked local reach it.
    'target reads the nonce back out of argv' => [
        "<?php echo 'ok '.(\$argv[2] ?? 'NO-NONCE').\"\\n\"; exit(0);\n",
        'ohne Quittung',
    ],
    // The same read through every channel the value could still have survived in.
    'target hunts the nonce in GLOBALS, SERVER and the enclosing scope' => [
        "<?php echo 'ok '.(\$GLOBALS['argv'][2] ?? \$_SERVER['argv'][2] ?? \$nonce ?? 'NO-NONCE').\"\\n\"; exit(0);\n",
        'ohne Quittung',
    ],
    // And the channel that no amount of PHP-side scrubbing closes: the kernel hands
    // the command line back through /proc/self/cmdline, superglobals or not. That is
    // why the nonce travels on stdin and the descriptor is closed before the target
    // loads — there is no command line to read it from.
    'target reads the command line straight from /proc' => [
        "<?php \$c = @file_get_contents('/proc/self/cmdline'); \$p = \$c ? explode(\"\\0\", \$c) : [];"
            ." echo 'ok '.(\$p[3] ?? 'NO-NONCE').\"\\n\"; exit(0);\n",
        'ohne Quittung',
    ],
]);

it('completes when the vendor file carries the 4.4.0 scoping', function (): void {
    deeplinkSandbox($this->sandbox, SCOPING_TARGET);

    $process = runPatchScript($this->sandbox);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('Deeplink-Scoping liegt bei NativePHP selbst')
        ->and($process->getOutput())->toContain('Fertig.');
});

// The guard reads the vendor source, so it can only ever say "the package COULD
// scope". What it is scoped TO lives in config/nativephp.php, and an empty list
// sends NativePHP back to pathPrefix="/" all by itself (documented in
// deepLinkPathData()). That value is therefore pinned here as well — the release
// guard measures it against the APK, this one against the source of truth.
it('keeps the claimed paths non-empty and narrower than the whole host', function (): void {
    $paths = config('nativephp.deeplink_paths');

    expect($paths)->toBe(['/app/'])
        ->and($paths)->not->toContain('/');
});
