<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Control for the APK signature guard in `scripts/release.sh --pruefe-signatur`.
 *
 * Every release APK up to v1.12.1 carried the v2 scheme alone — measured with
 * `apksigner verify --verbose` against v1.9.5, v1.10.0, v1.11.0, v1.12.0 and v1.12.1,
 * all four reporting `v1: false / v2: true / v3: false`. NativePHP's signingConfigs
 * block sets no `enableV*Signing` flags, so AGP's defaults decide, and at minSdk 33
 * that is v2 alone. `patch_gradle_signing()` in `scripts/apply-vendor-patches.sh` now
 * sets all three; this guard measures whether they reached the ARTEFACT, which is the
 * only place where a lost patch, a NativePHP rewrite and a wrong keystore all look
 * alike.
 *
 * The certificate digest is measured next to the schemes on purpose: three schemes
 * from the WRONG keystore would be worse than one scheme from the right one. Android
 * binds every existing installation to the certificate, and `44411e20…0b7c` is what
 * the published Nostr events carry as `apk_certificate_hash`.
 *
 * The fixtures are `apksigner verify --verbose --print-certs` text. They are not
 * invented: the passing shape is a verbatim run against an APK that really carries
 * v1+v2+v3 (a copy of the v1.12.1 APK re-signed with a throw-away key on 2026-09-22),
 * with the throw-away digest swapped for the maintainer's. Text instead of an APK for
 * the same two reasons as in `ReleaseManifestGuardTest.php`: a signed APK cannot be
 * produced without a keystore, and `dist/` is gitignored, so a fresh checkout ships no
 * artifact at all.
 */
const SIG_ERWARTET = '44411e20a1b43d0f66cf99e1238a33e7e8fd9248f0d0d258f5e0727cfabf0b7c';

const SIG_APK_V121 = 'dist/v1.12.1/twenty-one-companion-v1.12.1.apk';

beforeEach(function (): void {
    $this->fixtures = sys_get_temp_dir().'/signature-guard-'.uniqid();
    File::ensureDirectoryExists($this->fixtures);
});

afterEach(function (): void {
    File::deleteDirectory($this->fixtures);
});

/**
 * An apksigner report in the real shape. `v3.1`/`v3.2` are always present and always
 * false — they are the reason the guard matches whole lines instead of substrings.
 * The signer prefix varies with the scheme apksigner verified (`V2 Signer:` in the
 * v1.12.1 report, `V3.0 Signer:` with all three schemes, `Signer #1` for plain v1),
 * which is why it is a parameter here and why the guard must not depend on it.
 *
 * @param  list<string>  $schemes  which of v1/v2/v3 report true
 */
function apksignerReport(array $schemes = ['v1', 'v2', 'v3'], string $digest = SIG_ERWARTET, string $prefix = 'V3.0 Signer: '): string
{
    $wert = fn (string $scheme): string => in_array($scheme, $schemes, true) ? 'true' : 'false';

    return implode("\n", [
        'Verifies',
        'Verified using v1 scheme (JAR signing): '.$wert('v1'),
        'Verified using v2 scheme (APK Signature Scheme v2): '.$wert('v2'),
        'Verified using v3 scheme (APK Signature Scheme v3): '.$wert('v3'),
        'Verified using v3.1 scheme (APK Signature Scheme v3.1): '.$wert('v3.1'),
        'Verified using v3.2 scheme (APK Signature Scheme v3.2): '.$wert('v3.2'),
        'Verified using v4 scheme (APK Signature Scheme v4): false',
        'Verified for SourceStamp: false',
        'Number of signers: 1',
        $prefix.'certificate DN: CN=Einundzwanzig, O=Einundzwanzig, C=DE',
        $prefix.'certificate SHA-256 digest: '.$digest,
        $prefix.'certificate SHA-1 digest: 70e93c02d8438a8a1e6bbfac1a476fcb0560e296',
        $prefix.'key algorithm: RSA',
        $prefix.'public key SHA-256 digest: 4c6ff9ebb55a6ae29d6d2de0c12516d7be740d47382be7c1b4f05c2e1912f8bd',
    ])."\n";
}

function runSignatureGuard(string $file, array $env = []): Process
{
    $process = new Process(
        ['bash', 'scripts/release.sh', '--pruefe-signatur', $file],
        base_path(),
        $env,
    );
    $process->run();

    return $process;
}

/** @return string absolute path of a fixture file holding $content */
function signatureFixture(string $dir, string $name, string $content): string
{
    File::put($file = $dir.'/'.$name, $content);

    return $file;
}

it('accepts an APK that carries v1, v2 and v3 from the expected certificate', function (): void {
    $file = signatureFixture($this->fixtures, 'gut.txt', apksignerReport());

    $process = runSignatureGuard($file);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('✓ Signaturschemata: v1, v2, v3');
});

it('rejects an APK that is missing a signature scheme', function (array $schemes, string $benannt): void {
    $file = signatureFixture($this->fixtures, 'fehlend.txt', apksignerReport($schemes));

    $process = runSignatureGuard($file);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('fehlen Signaturschemata:'.$benannt);
})->with([
    // The v2-only shape every release up to v1.12.1 had.
    'v2 only' => [['v2'], ' v1 v3'],
    'no v1' => [['v2', 'v3'], ' v1'],
    'no v3' => [['v1', 'v2'], ' v3'],
    'no v2' => [['v1', 'v3'], ' v2'],
]);

// v3.1 and v3.2 are rotation variants and appear in every report. A substring match on
// "v3" would read them as the v3 scheme and wave through an APK that has none.
it('does not mistake v3.1 or v3.2 for the v3 scheme', function (): void {
    $file = signatureFixture($this->fixtures, 'v31.txt', apksignerReport(['v1', 'v2', 'v3.1', 'v3.2']));

    $process = runSignatureGuard($file);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('fehlen Signaturschemata: v3');
});

// Three schemes from the wrong keystore are worse than one from the right one: every
// existing installation stops updating and the published apk_certificate_hash is void.
it('rejects an APK signed with a different certificate', function (): void {
    $fremd = str_repeat('ab', 32);
    $file = signatureFixture($this->fixtures, 'fremd.txt', apksignerReport(digest: $fremd));

    $process = runSignatureGuard($file);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('ANDERES Signatur-Zertifikat')
        ->and($process->getErrorOutput())->toContain($fremd);
});

it('rejects a report whose signers disagree about the certificate', function (): void {
    // Two signer blocks, one of them foreign — the guard collects every digest in the
    // report, not just the first, so a mixed-signer APK cannot slip through.
    $file = signatureFixture(
        $this->fixtures,
        'gemischt.txt',
        apksignerReport().'Signer #2 certificate SHA-256 digest: '.str_repeat('cd', 32)."\n",
    );

    $process = runSignatureGuard($file);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('ANDERES Signatur-Zertifikat');
});

it('reads the certificate regardless of the signer prefix apksigner chose', function (): void {
    // Plain v1 verification labels the signer `Signer #1`, v2 `V2 Signer:`, all three
    // `V3.0 Signer:` — all three were seen in real runs on 2026-09-22.
    $file = signatureFixture($this->fixtures, 'praefix.txt', apksignerReport(prefix: 'Signer #1 '));

    $process = runSignatureGuard($file);

    expect($process->getExitCode())->toBe(0);
});

it('rejects a report it cannot read instead of calling every scheme missing', function (string $name, string $content): void {
    $file = signatureFixture($this->fixtures, $name, $content);

    $process = runSignatureGuard($file);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('nicht verifizierbar');
})->with([
    'empty report' => ['leer.txt', ''],
    'not an apksigner report at all' => ['muell.txt', "irgendein Text\n"],
    // `--print-certs` forgotten: schemes are there, the digest is not. Nothing may be
    // inferred from the absence — a guard that skips its own second half is no guard.
    'schemes without certificates' => ['ohne-certs.txt', implode("\n", [
        'Verifies',
        'Verified using v1 scheme (JAR signing): true',
        'Verified using v2 scheme (APK Signature Scheme v2): true',
        'Verified using v3 scheme (APK Signature Scheme v3): true',
    ])."\n"],
]);

it('rejects a certificate line that is not a full SHA-256', function (): void {
    $file = signatureFixture($this->fixtures, 'kurz.txt', apksignerReport(digest: substr(SIG_ERWARTET, 0, 40)));

    $process = runSignatureGuard($file);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('nicht verifizierbar');
});

it('fails instead of skipping when apksigner cannot be located', function (): void {
    $file = signatureFixture($this->fixtures, 'app-release.apk', 'kein echtes APK');

    $process = runSignatureGuard($file, ['APKSIGNER' => '/nonexistent/apksigner']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('apksigner nicht gefunden');
});

it('rejects a missing file', function (): void {
    $process = runSignatureGuard($this->fixtures.'/gibtsnicht.txt');

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Signatur-Quelle nicht gefunden');
});

/*
|--------------------------------------------------------------------------
| The real artifact
|--------------------------------------------------------------------------
|
| Everything above runs against text. This one runs the whole chain — locating
| apksigner, invoking it, reading its output — against the last shipped APK, which is
| v2-only and must therefore be refused. Skipped rather than failed when `dist/` is
| empty: it is gitignored and absent on a fresh checkout.
*/

it('refuses the last v2-only release APK', function (): void {
    $process = runSignatureGuard(base_path(SIG_APK_V121));

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('fehlen Signaturschemata: v1 v3')
        ->and($process->getErrorOutput())->toContain('Verified using v2 scheme (APK Signature Scheme v2): true');
})->skip(
    fn (): bool => ! file_exists(base_path(SIG_APK_V121)),
    SIG_APK_V121.' is gitignored and not present in this checkout',
);

/**
 * The one control that covers the `--min-sdk-version 21` decision in the guard, and it
 * needs a real three-scheme APK because nothing else can show the trap: apksigner only
 * VERIFIES the schemes the given SDK range needs and reports every other one as
 * `false`, even when it is in the file. Measured on 2026-09-22 against an APK that
 * demonstrably carries v1+v2+v3 (META-INF/*.SF, *.RSA, MANIFEST.MF in the zip):
 *
 *   --min-sdk-version 21 → v1 true  · v2 true  · v3 true
 *   --min-sdk-version 24 → v1 FALSE · v2 true  · v3 true
 *   no --min-sdk-version (33 from the manifest) → v1 FALSE · v2 FALSE · v3 true
 *
 * Without the switch the guard would reject every future release although all three
 * schemes are there. The APK is built here rather than shipped: re-signing a copy of
 * the last release with a throw-away key in a temp directory. The certificate is
 * therefore the wrong one on purpose — the assertion is that the run gets PAST the
 * scheme check and fails on the certificate alone.
 */
it('sees all three schemes in an APK that really carries them', function (): void {
    $keystore = $this->fixtures.'/wegwerf.jks';
    $apk = $this->fixtures.'/dreifach.apk';
    File::copy(base_path(SIG_APK_V121), $apk);

    $keytool = (new Process(['keytool', '-genkeypair', '-keystore', $keystore,
        '-storepass', 'wegwerf', '-keypass', 'wegwerf', '-alias', 't',
        '-keyalg', 'RSA', '-keysize', '2048', '-validity', '1',
        '-dname', 'CN=Throwaway, O=Test, C=DE']))->mustRun();

    $sign = new Process([signatureApksigner(), 'sign',
        '--ks', $keystore, '--ks-pass', 'pass:wegwerf', '--key-pass', 'pass:wegwerf',
        '--v1-signing-enabled', 'true', '--v2-signing-enabled', 'true',
        '--v3-signing-enabled', 'true', '--v4-signing-enabled', 'false', $apk]);
    $sign->mustRun();

    $process = runSignatureGuard($apk);

    expect($keytool->isSuccessful())->toBeTrue()
        // Past the scheme check: the only complaint left is the throw-away certificate.
        ->and($process->getErrorOutput())->not->toContain('fehlen Signaturschemata')
        ->and($process->getErrorOutput())->toContain('ANDERES Signatur-Zertifikat')
        ->and($process->getExitCode())->toBe(1);
})->skip(
    fn (): bool => ! file_exists(base_path(SIG_APK_V121))
        || signatureApksigner() === null
        || (new ExecutableFinder)->find('keytool') === null,
    'needs '.SIG_APK_V121.', apksigner and keytool — none of them ship with the checkout',
);

/** Same search order as `finde_apksigner()` in the script, null when unavailable. */
function signatureApksigner(): ?string
{
    if ($pfad = (new ExecutableFinder)->find('apksigner')) {
        return $pfad;
    }

    foreach ([getenv('ANDROID_HOME'), getenv('ANDROID_SDK_ROOT'), getenv('HOME').'/Android/Sdk'] as $sdk) {
        if (! $sdk || ! is_dir($sdk.'/build-tools')) {
            continue;
        }

        $kandidaten = glob($sdk.'/build-tools/*/apksigner') ?: [];
        usort($kandidaten, 'version_compare');

        if ($kandidaten !== []) {
            return end($kandidaten);
        }
    }

    return null;
}
