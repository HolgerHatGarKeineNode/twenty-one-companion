<?php

use App\Services\AndroidManifestPatcher;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

beforeEach(function (): void {
    $this->manifestPath = sys_get_temp_dir().'/nativephp-manifest-test-'.uniqid().'.xml';
});

afterEach(function (): void {
    @unlink($this->manifestPath);
});

function fakeManifest(string $launchMode, bool $withAmberQueries = false): string
{
    $queries = $withAmberQueries
        ? <<<'XML'
            <queries>
                <package android:name="com.greenart7c3.nostrsigner" />
            </queries>

            XML
        : '';

    return <<<XML
        <manifest xmlns:android="http://schemas.android.com/apk/res/android">
            {$queries}<application android:label="Einundzwanzig">
                <activity
                    android:name=".ui.MainActivity"
                    android:exported="true"
                    android:launchMode="{$launchMode}">
                </activity>
            </application>
        </manifest>
        XML;
}

it('ersetzt singleTop durch singleTask im Manifest', function (): void {
    file_put_contents($this->manifestPath, fakeManifest('singleTop'));

    $patcher = new AndroidManifestPatcher($this->manifestPath);

    expect($patcher->ensureSingleTask())->toBeTrue()
        ->and(file_get_contents($this->manifestPath))->toContain('android:launchMode="singleTask"')
        ->and(file_get_contents($this->manifestPath))->not->toContain('singleTop');
});

it('ist idempotent bei bereits gepatchtem Manifest', function (): void {
    // isPatched() prüft seit be63d30 (Amber-Signer) beide Patches (singleTask
    // + Amber-<queries>) — die Fixture muss also ein vollständig gepatchtes
    // Manifest simulieren, nicht nur den launchMode-Fix.
    file_put_contents($this->manifestPath, fakeManifest('singleTask', withAmberQueries: true));

    $patcher = new AndroidManifestPatcher($this->manifestPath);

    expect($patcher->ensureSingleTask())->toBeFalse()
        ->and($patcher->isPatched())->toBeTrue();
});

it('ignoriert ein fehlendes Manifest', function (): void {
    $patcher = new AndroidManifestPatcher($this->manifestPath.'-missing');

    expect($patcher->ensureSingleTask())->toBeFalse()
        ->and($patcher->isPatched())->toBeFalse();
});

it('patcht über den Artisan-Befehl', function (): void {
    file_put_contents($this->manifestPath, fakeManifest('singleTop'));

    $this->app->bind(AndroidManifestPatcher::class, fn (): AndroidManifestPatcher => new AndroidManifestPatcher($this->manifestPath));

    $this->artisan('app:patch-android-manifest')
        ->expectsOutputToContain('gepatcht')
        ->assertSuccessful();

    expect(file_get_contents($this->manifestPath))->toContain('android:launchMode="singleTask"');
});

it('patcht automatisch nach native:install und vor native:run', function (string $eventClass, string $command): void {
    file_put_contents($this->manifestPath, fakeManifest('singleTop'));

    $this->app->bind(AndroidManifestPatcher::class, fn (): AndroidManifestPatcher => new AndroidManifestPatcher($this->manifestPath));

    $event = $eventClass === CommandFinished::class
        ? new CommandFinished($command, new ArrayInput([]), new BufferedOutput, 0)
        : new CommandStarting($command, new ArrayInput([]), new BufferedOutput);

    event($event);

    expect(file_get_contents($this->manifestPath))->toContain('android:launchMode="singleTask"');
})->with([
    'native:install (finished)' => [CommandFinished::class, 'native:install'],
    'native:run (starting)' => [CommandStarting::class, 'native:run'],
    'native:package (starting)' => [CommandStarting::class, 'native:package'],
    'native:watch (starting)' => [CommandStarting::class, 'native:watch'],
]);

it('lässt fremde Befehle unangetastet', function (): void {
    file_put_contents($this->manifestPath, fakeManifest('singleTop'));

    $this->app->bind(AndroidManifestPatcher::class, fn (): AndroidManifestPatcher => new AndroidManifestPatcher($this->manifestPath));

    event(new CommandStarting('migrate', new ArrayInput([]), new BufferedOutput));

    expect(file_get_contents($this->manifestPath))->toContain('android:launchMode="singleTop"');
});
