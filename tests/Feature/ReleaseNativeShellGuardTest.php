<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * Control for `scripts/release.sh --pruefe-unterbau`.
 *
 * `composer update` replaces the NativePHP package, but nothing refreshes the generated
 * Android project. Measured 2026-11-15: the project had been generated from 4.4.0 on
 * 2026-09-11 and was still being built under 4.7.0 — 45 Kotlin files differed from the
 * template, 14 were missing. Four releases shipped that shell with a green build.
 *
 * The known-bad cases are the two ways a stale shell shows: a template file the
 * generated project does not have, and one whose content differs. The known-good cases
 * are an identical tree and a tree that differs only in MainActivity.kt, which the build
 * rewrites on every run and which must therefore never trip the guard.
 */
beforeEach(function (): void {
    $this->root = sys_get_temp_dir().'/shell-guard-'.uniqid();
    $this->template = $this->root.'/template';
    $this->generated = $this->root.'/generated';

    foreach ([$this->template, $this->generated] as $tree) {
        File::ensureDirectoryExists($tree.'/ui');
        File::ensureDirectoryExists($tree.'/network');
        File::put($tree.'/ui/MainActivity.kt', "class MainActivity\n");
        File::put($tree.'/network/WebViewManager.kt', "class WebViewManager\n");
    }
});

afterEach(function (): void {
    File::deleteDirectory($this->root);
});

function nativeShellGuard(string $template, string $generated): Process
{
    $process = new Process(
        ['bash', 'scripts/release.sh', '--pruefe-unterbau', $template, $generated],
        base_path(),
    );
    $process->run();

    return $process;
}

it('accepts a generated project that matches the template', function (): void {
    $process = nativeShellGuard($this->template, $this->generated);

    expect($process->getExitCode())->toBe(0)
        ->and($process->getOutput())->toContain('✓');
});

it('accepts a project that differs only in the file the build rewrites', function (): void {
    File::put($this->generated.'/ui/MainActivity.kt', "class MainActivity // deep links\n");

    expect(nativeShellGuard($this->template, $this->generated)->getExitCode())->toBe(0);
});

it('rejects a project that lacks a file the template ships', function (): void {
    File::put($this->template.'/ui/AppLifecycleMonitor.kt', "class AppLifecycleMonitor\n");

    $process = nativeShellGuard($this->template, $this->generated);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('1 Kotlin-Datei(en)');
});

it('rejects a project whose file differs from the template', function (): void {
    File::put($this->generated.'/network/WebViewManager.kt', "class WebViewManager // 4.4.0\n");

    $process = nativeShellGuard($this->template, $this->generated);

    expect($process->getExitCode())->toBe(1)
        ->and($process->getErrorOutput())->toContain('1 Kotlin-Datei(en)');
});

it('fails closed when one of the two trees is missing', function (string $missing): void {
    File::deleteDirectory($this->{$missing});

    expect(nativeShellGuard($this->template, $this->generated)->getExitCode())->toBe(1);
})->with(['template', 'generated']);
