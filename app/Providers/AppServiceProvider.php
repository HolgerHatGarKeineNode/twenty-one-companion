<?php

namespace App\Providers;

use App\Services\AndroidManifestPatcher;
use App\Services\AppPreferences;
use App\Services\CountryOptions;
use App\Services\PortalApi;
use App\Services\PortalAuth;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * NativePHP-Befehle, vor deren Build das Android-Manifest gepatcht sein muss.
     *
     * @var list<string>
     */
    private const NATIVE_BUILD_COMMANDS = ['native:run', 'native:package', 'native:watch'];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Eine Instanz pro Request, damit die Token-Memoisierung (Keystore-
        // Bridge-Call) über PortalConnector und PortalApi hinweg greift.
        $this->app->scoped(PortalAuth::class);

        // Eine Instanz pro Request, damit Middleware und Seiten die
        // Preferences-Tabelle nur einmal lesen.
        $this->app->scoped(AppPreferences::class);

        // Eine Instanz pro Request, damit Render und Validierung die
        // memoisierte Länderliste teilen (Cache-Read + DTO-Mapping).
        $this->app->scoped(CountryOptions::class);

        // Eine Instanz pro Request, damit Offline-/Stale-/Fehler-Status der
        // API-Aufrufe (Banner + Fehler-States) über den Render hinweg
        // aufläuft und der Network-Bridge-Call memoisiert bleibt.
        $this->app->scoped(PortalApi::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->keepNativeManifestPatched();
    }

    /**
     * Wendet den launchMode-Fix (PLAN 1.21) automatisch an: nach `native:install`
     * (re-scaffoldet das Manifest aus dem Vendor-Template) und vor jedem Build.
     */
    protected function keepNativeManifestPatched(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        Event::listen(CommandStarting::class, function (CommandStarting $event): void {
            if (in_array($event->command, self::NATIVE_BUILD_COMMANDS, true) && $this->app->make(AndroidManifestPatcher::class)->ensureSingleTask()) {
                $event->output->writeln('<info>AndroidManifest.xml gepatcht: launchMode singleTop → singleTask (Amber-Deep-Link-Fix).</info>');
            }
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            if ($event->command === 'native:install' && $this->app->make(AndroidManifestPatcher::class)->ensureSingleTask()) {
                $event->output->writeln('<info>AndroidManifest.xml gepatcht: launchMode singleTop → singleTask (Amber-Deep-Link-Fix).</info>');
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
