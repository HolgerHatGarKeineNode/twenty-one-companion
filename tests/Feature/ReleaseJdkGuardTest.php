<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Control for the JDK choice in `scripts/release.sh --pruefe-jdk`.
 *
 * Until 2026-09-01 the script exported the JetBrains runtime that ships with Android
 * Studio, unconditionally and unverified. Measured that day, that runtime is 25.0.2 and
 * cannot build this project at all: `./gradlew help` dies after 0.6 s with
 * `java.lang.IllegalArgumentException: 25.0.2` in
 * `org.jetbrains.kotlin.com.intellij.util.lang.JavaVersion.parse` — reproduced on the
 * 4.3.1 project (Gradle 8.14.5) and on a pristine 3.3.7 one (Gradle 8.13). Builds kept
 * working only because Gradle pulls its own toolchain JDK for the compile tasks, which
 * does not help the configuration phase.
 *
 * The known-bad cases are therefore "only a JDK that is too new is available" and "no
 * JDK at all": both must fail loudly. A silent fallback to an unusable runtime is the
 * failure mode being replaced — it costs a build that dies twenty minutes in.
 *
 * `JDK_SUCHPFADE` replaces the search locations so the negative case is measurable
 * without touching the JDKs of this machine. The positive case runs against whatever
 * this machine really has, because a guard that only ever sees fixtures proves nothing
 * about the host it will run on.
 */
beforeEach(function (): void {
    $this->fixtures = sys_get_temp_dir().'/jdk-guard-'.uniqid();
    File::ensureDirectoryExists($this->fixtures);
});

afterEach(function (): void {
    File::deleteDirectory($this->fixtures);
});

/**
 * A directory that looks like a JDK to the guard: `bin/javac` plus the `release`
 * file every JDK ships, which is where the major version is read from.
 */
function fakeJdk(string $root, string $version): string
{
    File::ensureDirectoryExists($root.'/bin');
    File::put($root.'/bin/javac', "#!/bin/sh\n");
    chmod($root.'/bin/javac', 0o755);
    File::put($root.'/release', "JAVA_VERSION=\"{$version}\"\nIMPLEMENTOR=\"Test\"\n");

    return $root;
}

/**
 * @param  list<string>  $suchpfade
 */
function jdkGuard(array $suchpfade): Process
{
    $process = new Process(
        ['bash', 'scripts/release.sh', '--pruefe-jdk'],
        base_path(),
        // JAVA_HOME out of the way: it is the first candidate in the real search
        // order and would otherwise decide the fixture cases.
        ['JDK_SUCHPFADE' => implode(':', $suchpfade), 'JAVA_HOME' => ''],
    );
    $process->run();

    return $process;
}

it('picks a JDK this machine can actually build with', function (): void {
    $process = new Process(['bash', 'scripts/release.sh', '--pruefe-jdk'], base_path());
    $process->run();

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toMatch('/^→ JDK (1[7-9]|2[0-4]): \S/m');
});

it('refuses a runtime that is too new instead of exporting it', function (): void {
    // Exactly the JetBrains runtime case, and the one that used to be hardcoded.
    $process = jdkGuard([fakeJdk($this->fixtures.'/jbr', '25.0.2')]);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Kein JDK zwischen 17 und 24 gefunden')
        ->and($process->getOutput())->not->toContain('→ JDK');
});

it('refuses when no JDK is found at all', function (): void {
    $process = jdkGuard([$this->fixtures.'/gibt-es-nicht']);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('Kein JDK zwischen 17 und 24 gefunden')
        ->and($process->getErrorOutput())->toContain('JavaVersion.parse');
});

it('skips a JRE and takes the JDK behind it', function (): void {
    // No javac, so Gradle would fail in the configuration phase — order matters here:
    // the JRE comes first and must not win.
    $jre = $this->fixtures.'/jre';
    File::ensureDirectoryExists($jre.'/bin');
    File::put($jre.'/release', "JAVA_VERSION=\"21.0.7\"\n");

    $process = jdkGuard([$jre, fakeJdk($this->fixtures.'/jdk21', '21.0.7')]);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('→ JDK 21: '.$this->fixtures.'/jdk21');
});
