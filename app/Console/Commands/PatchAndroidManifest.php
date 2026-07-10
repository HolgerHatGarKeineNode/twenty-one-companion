<?php

namespace App\Console\Commands;

use App\Services\AndroidManifestPatcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('app:patch-android-manifest')]
#[Description('Setzt launchMode="singleTask" im generierten Android-Manifest (Amber-Deep-Link-Fix, PLAN 1.21)')]
class PatchAndroidManifest extends Command
{
    public function handle(AndroidManifestPatcher $patcher): int
    {
        if ($patcher->ensureAll()) {
            $this->info('AndroidManifest.xml gepatcht: singleTask + Amber-<queries>.');

            return self::SUCCESS;
        }

        if ($patcher->isPatched()) {
            $this->info('AndroidManifest.xml ist bereits gepatcht (singleTask + Amber-<queries>).');

            return self::SUCCESS;
        }

        $this->warn('Kein Manifest gefunden ('.$patcher->manifestPath().') — zuerst `php artisan native:install android` ausführen.');

        return self::FAILURE;
    }
}
