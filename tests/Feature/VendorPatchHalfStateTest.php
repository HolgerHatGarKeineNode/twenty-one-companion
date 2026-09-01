<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Controls for the half-patch protection in `scripts/apply-vendor-patches.sh`.
 *
 * A patch function that runs SEVERAL substitutions and verifies only some of them can
 * leave a vendor file half patched and still look fine. Three instances surfaced on
 * 2026-09-01 alone: the extract gate (first perl substitution landed, second missed,
 * file left half patched), `patch_deeplinks` (two substitutions, one verified — the
 * script printed "eingeschraenkt" and exited 0 while `pathPrefix="/"` was still in the
 * file, which is the v1.9.4 defect wearing a green light), and `patch_env` phase 3 plus
 * `patch_filechooser_webview`, which are the subject here.
 *
 * What makes it expensive is the SECOND run: the idempotency guard (`grep -q
 * 'opcache.file_cache'`, `grep -q 'FILE_CHOOSER_REQUEST_CODE'`) is satisfied by
 * whichever half landed, so the next run reports `[=]` and the missing half never
 * comes back. Every half-state case below therefore runs the script TWICE and asserts
 * the vendor file is byte-identical to the fixture afterwards — that is the assertion
 * a missing rollback cannot satisfy.
 *
 * Known-bad and known-good in one file, the shape this repo already uses for guards
 * (`tests/Browser/Accessibility/*`, `tests/Feature/DeeplinkPatchGuardTest.php`): a
 * guard that has only ever been seen green is indistinguishable from one that cannot
 * fire at all.
 *
 * The script always runs in a throw-away tree, never against this repo — it edits
 * vendor files in place.
 */
beforeEach(function (): void {
    $this->tree = sys_get_temp_dir().'/halfpatch-'.uniqid();
});

afterEach(function (): void {
    File::deleteDirectory($this->tree);
});

/** Path of every file the script may touch, relative to the sandbox root. */
const HP_ENV = 'nativephp/android/app/src/main/java/com/nativephp/mobile/bridge/LaravelEnvironment.kt';
const HP_MAIN = 'nativephp/android/app/src/main/java/com/nativephp/mobile/ui/MainActivity.kt';
const HP_WEBVIEW = 'nativephp/android/app/src/main/java/com/nativephp/mobile/network/WebViewManager.kt';
const HP_ICON = 'nativephp/android/app/src/main/res/drawable/ic_launcher_background.xml';
const HP_GRADLE = 'nativephp/android/app/build.gradle.kts';
const HP_DEEPLINK = 'vendor/nativephp/mobile/src/Concerns/RunsAndroid.php';

/**
 * Every target already carries the marker of its patch, so the whole run reports `[=]`
 * and changes nothing. A test overrides exactly ONE file with a fixture that is still
 * to be patched — that isolates a single patch function inside a script that otherwise
 * exits on the first failure.
 *
 * The template target (`vendor/nativephp/mobile/resources/androidstudio`) is left out
 * on purpose: the script skips a target whose files do not exist, so the build target
 * alone carries the measurement.
 *
 * @return array<string, string> relative path => content
 */
function hpDefaults(): array
{
    return [
        HP_ENV => "// opcache.file_cache\n// OPTIMIZE-opcache-wipe\n",
        HP_MAIN => "// FILE_CHOOSER_REQUEST_CODE\n",
        HP_WEBVIEW => "// FILE_CHOOSER_REQUEST_CODE\n// onShowFileChooser\n",
        HP_ICON => '<solid android:color="#000000"/>'."\n",
        HP_GRADLE => "// OPTIMIZE-STRIP\n",
        HP_DEEPLINK => "<?php\n\$prefixes = config('nativephp.deeplink_path_prefixes') ?: ['/'];\n",
    ];
}

/**
 * @param  array<string, string>  $overrides  relative path => content
 */
function hpSandbox(string $tree, array $overrides = []): void
{
    File::ensureDirectoryExists($tree.'/scripts');
    File::copy(base_path('scripts/apply-vendor-patches.sh'), $tree.'/scripts/apply-vendor-patches.sh');

    foreach (array_merge(hpDefaults(), $overrides) as $relative => $content) {
        File::ensureDirectoryExists(dirname($tree.'/'.$relative));
        File::put($tree.'/'.$relative, $content);
    }
}

function hpRun(string $tree): Process
{
    $process = new Process(['bash', 'scripts/apply-vendor-patches.sh'], $tree);
    $process->run();

    return $process;
}

/*
|--------------------------------------------------------------------------
| Fixtures
|--------------------------------------------------------------------------
|
| Reduced to the anchors the patch functions look for, but literal in their shape —
| copied from nativephp/mobile 4.3.1. A broken variant always models a plausible
| upstream drift, not a random mutilation.
*/

/**
 * `patch_env` phase 3 inserts at TWO independent anchors: the `mkdirs()` call before
 * `val phpIni = """`, and the opcache directives after the `openssl.cafile=` line.
 */
function hpEnvKotlin(bool $phpIniAnchor = true, bool $cafileAnchor = true): string
{
    $phpIni = $phpIniAnchor
        ? '                val phpIni = """'
        : '                val phpIni = buildString {';
    $cafile = $cafileAnchor
        ? 'openssl.cafile="${context.filesDir.absolutePath}/$CACERT_FILE"'
        : 'curl.capath="${context.filesDir.absolutePath}"';

    return <<<KT
        package com.nativephp.mobile.bridge

        class LaravelEnvironment {
            // OPTIMIZE-opcache-wipe: phase 3b is already applied in this fixture, so the
            // measurement is about phase 3 alone.
            private fun writePhpIni() {
        {$phpIni}
        curl.cainfo="\${context.filesDir.absolutePath}/\$CACERT_FILE"
        {$cafile}
        """
                File(context.filesDir, PHP_INI_FILE).writeText(phpIni)
            }
        }

        KT;
}

/**
 * `patch_filechooser_webview` inserts TWO blocks that belong to ONE feature: the
 * companion fields, and the override that reads them.
 */
function hpWebViewKotlin(bool $chromeClientAnchor = true): string
{
    $chrome = $chromeClientAnchor
        ? '        return object : WebChromeClient() {'
        : '        val chromeClient = object : WebChromeClient() {';

    return <<<KT
        package com.nativephp.mobile.network

        class WebViewManager {
            companion object {
                var shared: WebViewManager? = null
            }

            private fun chromeClient(): WebChromeClient {
        {$chrome}
                    override fun onProgressChanged(view: WebView?, newProgress: Int) {}
                }
            }
        }

        KT;
}

/**
 * `patch_deeplinks` runs TWO substitutions: the `<data>` line becomes `{$dataTags}`,
 * and the computation of `$dataTags` goes in front of the heredoc. With the attribute
 * order reversed, substitution 1 misses and substitution 2 lands — the asymmetric half
 * state that leaves `pathPrefix="/"` in place while `grep -q deeplink_path_prefixes`
 * reports success.
 */
function hpRunsAndroidPhp(bool $dataAnchor = true): string
{
    $data = $dataAnchor
        ? '                <data android:scheme="https" android:host="{$host}" android:pathPrefix="/" />'
        : '                <data android:host="{$host}" android:scheme="https" android:pathPrefix="/" />';

    return <<<PHP
        <?php

        trait RunsAndroid
        {
            protected function generateDeepLinkFilters(\$host, \$scheme): string
            {
                \$filters = [];

                if (\$host) {
                    \$filters[] = <<<XML
                    <intent-filter android:autoVerify="true">
                        <action android:name="android.intent.action.VIEW" />
        {$data}
                    </intent-filter>
        XML;
                }

                return implode("\\n", \$filters);
            }
        }

        PHP;
}

/** `patch_gradle_strip` replaces the anchor line — `s///` without /g hits only the first. */
function hpGradle(int $anchors = 1): string
{
    $second = $anchors > 1
        ? <<<'KTS'

                buildTypes {
                    release {
                        packaging {
                            jniLibs {
                                keepDebugSymbols.add("**/*.so")
                            }
                        }
                    }
                }
            KTS
        : '';

    return <<<KTS
        android {
            packaging {
                jniLibs {
                    useLegacyPackaging = true
                    keepDebugSymbols.add("**/*.so")
                }
            }
        {$second}
        }

        KTS;
}

/** `patch_iconbg` runs `sed` without /g — a second white value would survive it. */
function hpIcon(int $whites = 1): string
{
    $body = $whites > 1
        ? '    <gradient android:startColor="#ffffff" android:endColor="#ffffff"/>'
        : '    <solid android:color="#ffffff"/>';

    return <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <shape xmlns:android="http://schemas.android.com/apk/res/android">
        {$body}
        </shape>

        XML;
}

/*
|--------------------------------------------------------------------------
| patch_env — phase 3, one awk pass, two independent anchors
|--------------------------------------------------------------------------
*/

it('rolls the vendor file back when only one half of the opcache patch lands', function (string $file, string $expectedError): void {
    hpSandbox($this->tree, [HP_ENV => $file]);

    $first = hpRun($this->tree);

    expect($first->getExitCode())->toBe(1)
        ->and($first->getErrorOutput())->toContain($expectedError)
        ->and($first->getErrorOutput())->toContain('kein Halbstand')
        ->and(File::get($this->tree.'/'.HP_ENV))->toBe($file);

    // The expensive part is the re-run: a residue of the first attempt satisfies
    // `grep -q 'opcache.file_cache'`, the run reports `[=]` and the missing half never
    // returns. In the other direction the residue is a second `mkdirs()` line.
    $second = hpRun($this->tree);

    expect($second->getExitCode())->toBe(1)
        ->and($second->getOutput())->not->toContain('[=] Phase 3 opcache.file_cache bereits gesetzt')
        ->and(File::get($this->tree.'/'.HP_ENV))->toBe($file);
})->with([
    'mkdirs anchor gone, ini directives land' => [
        hpEnvKotlin(phpIniAnchor: false),
        'Anker 1 (val phpIni) nicht getroffen',
    ],
    'ini anchor gone, mkdirs lands' => [
        hpEnvKotlin(cafileAnchor: false),
        'Anker 2 (openssl.cafile) nicht getroffen',
    ],
]);

it('applies both halves of the opcache patch when both anchors are there', function (): void {
    hpSandbox($this->tree, [HP_ENV => hpEnvKotlin()]);

    $process = hpRun($this->tree);
    $patched = File::get($this->tree.'/'.HP_ENV);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('[+] Phase 3 opcache.file_cache')
        ->and($patched)->toContain('mkdirs() // OPTIMIZE')
        ->and($patched)->toContain('opcache.file_cache=');
});

/*
|--------------------------------------------------------------------------
| patch_filechooser_webview — two guarded blocks, one feature
|--------------------------------------------------------------------------
*/

it('rolls the companion holder back when the onShowFileChooser block cannot land', function (): void {
    $file = hpWebViewKotlin(chromeClientAnchor: false);
    hpSandbox($this->tree, [HP_WEBVIEW => $file]);

    $first = hpRun($this->tree);

    expect($first->getExitCode())->toBe(1)
        ->and($first->getErrorOutput())->toContain('onShowFileChooser-Patch griff nicht')
        // Block 1 landed and is rolled back with it: without the override those two
        // fields are dead code, and `grep -q FILE_CHOOSER_REQUEST_CODE` would read them
        // as "already patched" forever after.
        ->and(File::get($this->tree.'/'.HP_WEBVIEW))->toBe($file);

    $second = hpRun($this->tree);

    expect($second->getExitCode())->toBe(1)
        ->and($second->getOutput())->not->toContain('[=] FileChooser Companion-Halter bereits vorhanden')
        ->and(File::get($this->tree.'/'.HP_WEBVIEW))->toBe($file);
});

it('applies both file chooser blocks when both anchors are there', function (): void {
    hpSandbox($this->tree, [HP_WEBVIEW => hpWebViewKotlin()]);

    $process = hpRun($this->tree);
    $patched = File::get($this->tree.'/'.HP_WEBVIEW);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('[+] FileChooser Companion-Halter')
        ->and($process->getOutput())->toContain('[+] onShowFileChooser-Override')
        ->and($patched)->toContain('const val FILE_CHOOSER_REQUEST_CODE')
        ->and($patched)->toContain('override fun onShowFileChooser(');
});

/*
|--------------------------------------------------------------------------
| patch_deeplinks — fixed on 2026-09-01, proven by sandbox probe only until now
|--------------------------------------------------------------------------
*/

it('rolls RunsAndroid.php back when the data line is not replaced', function (): void {
    $file = hpRunsAndroidPhp(dataAnchor: false);
    hpSandbox($this->tree, [HP_DEEPLINK => $file]);

    $first = hpRun($this->tree);

    expect($first->getExitCode())->toBe(1)
        ->and($first->getErrorOutput())->toContain('Ersetzung 1 griff nicht')
        ->and($first->getOutput())->not->toContain('Fertig.')
        ->and(File::get($this->tree.'/'.HP_DEEPLINK))->toBe($file);

    // Without the rollback this is where v1.9.4 repeats itself: `$prefixes`/`$dataTags`
    // from substitution 2 satisfy `grep -q deeplink_path_prefixes`, the second run
    // reports `[=] Deeplink-Pfade bereits eingeschraenkt` and exits 0 — while the
    // manifest still claims the whole host through the untouched `pathPrefix="/"`.
    $second = hpRun($this->tree);

    expect($second->getExitCode())->toBe(1)
        ->and($second->getOutput())->not->toContain('[=] Deeplink-Pfade bereits eingeschraenkt')
        ->and(File::get($this->tree.'/'.HP_DEEPLINK))->toBe($file);
});

it('restricts the deeplink paths when the data line matches', function (): void {
    hpSandbox($this->tree, [HP_DEEPLINK => hpRunsAndroidPhp()]);

    $process = hpRun($this->tree);
    $patched = File::get($this->tree.'/'.HP_DEEPLINK);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('[+] Deeplink-Pfade')
        ->and($patched)->toContain('{$dataTags}')
        ->and($patched)->toContain("config('nativephp.deeplink_path_prefixes')")
        ->and($patched)->not->toContain('android:pathPrefix="/" />');
});

/*
|--------------------------------------------------------------------------
| patch_gradle_strip and patch_iconbg — single substitution, but without /g
|--------------------------------------------------------------------------
*/

it('refuses a gradle file where a second keepDebugSymbols line survives', function (): void {
    $file = hpGradle(anchors: 2);
    hpSandbox($this->tree, [HP_GRADLE => $file]);

    $first = hpRun($this->tree);

    expect($first->getExitCode())->toBe(1)
        ->and($first->getErrorOutput())->toContain('keepDebugSymbols steht')
        ->and(File::get($this->tree.'/'.HP_GRADLE))->toBe($file);

    // `s///` replaces the first occurrence only. The marker would be set — so `[=]`
    // from here on — while the surviving line keeps making stripReleaseDebugSymbols
    // useless, which is the entire point of the patch.
    $second = hpRun($this->tree);

    expect($second->getExitCode())->toBe(1)
        ->and($second->getOutput())->not->toContain('[=] keepDebugSymbols bereits eingeschraenkt')
        ->and(File::get($this->tree.'/'.HP_GRADLE))->toBe($file);
});

it('removes the keepDebugSymbols line when it appears once', function (): void {
    hpSandbox($this->tree, [HP_GRADLE => hpGradle()]);

    $process = hpRun($this->tree);
    $patched = File::get($this->tree.'/'.HP_GRADLE);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('[+] keepDebugSymbols entfernt')
        ->and($patched)->toContain('OPTIMIZE-STRIP')
        ->and($patched)->not->toContain('keepDebugSymbols.add');
});

it('refuses an icon background where a second white value survives', function (): void {
    $file = hpIcon(whites: 2);
    hpSandbox($this->tree, [HP_ICON => $file]);

    $first = hpRun($this->tree);

    expect($first->getExitCode())->toBe(1)
        ->and($first->getErrorOutput())->toContain('Icon-BG-Patch griff nicht')
        ->and(File::get($this->tree.'/'.HP_ICON))->toBe($file);

    $second = hpRun($this->tree);

    expect($second->getExitCode())->toBe(1)
        ->and(File::get($this->tree.'/'.HP_ICON))->toBe($file);
});

it('turns the icon background black when there is one white value', function (): void {
    hpSandbox($this->tree, [HP_ICON => hpIcon()]);

    $process = hpRun($this->tree);
    $patched = File::get($this->tree.'/'.HP_ICON);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('[+] Icon-Hintergrund schwarz')
        ->and($patched)->toContain('#000000')
        ->and($patched)->not->toContain('#ffffff');
});

/*
|--------------------------------------------------------------------------
| Control for the fixtures themselves
|--------------------------------------------------------------------------
*/

it('reports every intervention as already applied when no fixture is overridden', function (): void {
    hpSandbox($this->tree);

    $process = hpRun($this->tree);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('Fertig.')
        // Seven interventions per target, and only the build target exists here.
        ->and(substr_count($process->getOutput(), '    [='))->toBe(8)
        ->and($process->getOutput())->not->toContain('[+]');
});
