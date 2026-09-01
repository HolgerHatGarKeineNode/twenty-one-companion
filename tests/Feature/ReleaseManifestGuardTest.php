<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Control for the App-Link guard in `scripts/release.sh --pruefe-manifest`.
 *
 * The release script verified the bundle but never the manifest, so nothing at all
 * measured what the deeplink patch is supposed to shape: which portal paths the APK
 * claims. In v1.9.4 the patch had been overwritten and the build still ended with
 * exit 0 while the APK claimed the whole host.
 *
 * The known-bad case is the whole-host claim (`pathPrefix="/"`), the known-good case
 * the configured prefix. Two fail-closed cases sit next to them: an unreadable dump
 * and a missing aapt2 must both fail, because a guard that quietly does nothing is
 * the failure mode this replaces.
 *
 * The fixtures are `aapt2 dump xmltree` text, copied in shape from a real run against
 * `dist/v1.9.3/twenty-one-companion-v1.9.3.apk` (attribute names carry the full
 * namespace URI, values appear twice — resolved and raw). Text instead of an APK for
 * two reasons: a binary manifest cannot be written without the Android SDK, and
 * `dist/` is gitignored, so no shipped artifact is available on a fresh checkout.
 */
const GUARD_HOST = 'portal.test';

beforeEach(function (): void {
    $this->fixtures = sys_get_temp_dir().'/manifest-guard-'.uniqid();
    File::ensureDirectoryExists($this->fixtures);
});

afterEach(function (): void {
    File::deleteDirectory($this->fixtures);
});

/**
 * One intent-filter with a `<data>` element per path prefix, plus the custom-scheme
 * filter NativePHP always emits — that one has no host and no prefix and must not
 * confuse the reader that collects them per element.
 *
 * @param  list<string>  $pathPrefixes
 */
function appLinkDump(array $pathPrefixes): string
{
    $ns = 'http://schemas.android.com/apk/res/android';
    $lines = [
        "N: android={$ns}",
        '  E: manifest (line=2)',
        '    E: application (line=44)',
        '      E: activity (line=67)',
        '        E: intent-filter (line=81)',
        "          A: {$ns}:autoVerify(0x010104ee)=true",
    ];

    foreach ($pathPrefixes as $index => $prefix) {
        $host = GUARD_HOST;
        $lines[] = '          E: data (line='.(87 + $index).')';
        $lines[] = "            A: {$ns}:scheme(0x01010027)=\"https\" (Raw: \"https\")";
        $lines[] = "            A: {$ns}:host(0x01010028)=\"{$host}\" (Raw: \"{$host}\")";
        $lines[] = "            A: {$ns}:pathPrefix(0x0101002b)=\"{$prefix}\" (Raw: \"{$prefix}\")";
    }

    $lines[] = '        E: intent-filter (line=93)';
    $lines[] = '          E: data (line=99)';
    $lines[] = "            A: {$ns}:scheme(0x01010027)=\"einundzwanzig\" (Raw: \"einundzwanzig\")";

    return implode("\n", $lines)."\n";
}

/**
 * Runs the guard alone, with the deeplink host pinned: the value otherwise comes from
 * the developer's `.env`, and `.env.example` ships without it. The prefixes are NOT
 * pinned — they are the subject of the measurement and come from
 * `config/nativephp.php` on both sides.
 */
function runManifestGuard(string $file, array $env = []): Process
{
    $process = new Process(
        ['bash', 'scripts/release.sh', '--pruefe-manifest', $file],
        base_path(),
        array_merge(['NATIVEPHP_DEEPLINK_HOST' => GUARD_HOST], $env),
    );
    $process->run();

    return $process;
}

it('rejects a manifest that claims the whole portal host', function (): void {
    $file = $this->fixtures.'/ganzer-host.txt';
    File::put($file, appLinkDump(['/']));

    $process = runManifestGuard($file);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('beansprucht den GANZEN Portal-Host')
        ->and($process->getErrorOutput())->toContain('erwartet:  /app/');
});

// '/app/' is the value in config/nativephp.php. It is written out here rather than
// read from config, so that a change to the claimed paths turns this red and gets
// looked at instead of being followed silently.
it('accepts a manifest that claims only the configured path prefix', function (): void {
    $file = $this->fixtures.'/korrekt.txt';
    File::put($file, appLinkDump(['/app/']));

    $process = runManifestGuard($file);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('✓ App-Link-Pfade: /app/');
});

it('rejects a manifest source it cannot read', function (string $name, string $content): void {
    $file = $this->fixtures.'/'.$name;
    File::put($file, $content);

    $process = runManifestGuard($file);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('nicht verifizierbar');
})->with([
    'empty dump' => ['leer.txt', ''],
    'not a manifest dump at all' => ['muell.txt', "irgendein Text\n"],
]);

it('fails instead of skipping when aapt2 cannot be located', function (): void {
    $file = $this->fixtures.'/app-release.apk';
    File::put($file, 'kein echtes APK');

    $process = runManifestGuard($file, ['AAPT2' => '/nonexistent/aapt2']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('aapt2 nicht gefunden');
});
