<?php

namespace App\Providers;

use App\Services\AndroidManifestPatcher;
use App\Services\AppPreferences;
use App\Services\BrandResolver;
use App\Services\CountryOptions;
use App\Services\PortalApi;
use App\Services\PortalAuth;
use App\Services\PortalWriter;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Env;
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
        $this->repairNativeRuntimeFlag();

        // Eine Instanz pro Request, damit die Token-Memoisierung (Keystore-
        // Bridge-Call) über PortalConnector und PortalApi hinweg greift.
        $this->app->scoped(PortalAuth::class);

        // Eine Instanz pro Request, damit Middleware und Seiten die
        // Preferences-Tabelle nur einmal lesen.
        $this->app->scoped(AppPreferences::class);

        // Eine Instanz pro Request, damit Render und Validierung die
        // memoisierte Länderliste teilen (Cache-Read + DTO-Mapping).
        $this->app->scoped(CountryOptions::class);

        // Eine Instanz pro Request, damit Layout, Brand-Komponenten und die
        // Regionswechsel-Animation dieselbe aufgelöste Marke teilen.
        $this->app->scoped(BrandResolver::class);

        // Eine Instanz pro Request, damit Offline-/Stale-/Fehler-Status der
        // API-Aufrufe (Banner + Fehler-States) über den Render hinweg
        // aufläuft und der Network-Bridge-Call memoisiert bleibt.
        $this->app->scoped(PortalApi::class);

        // Schreib-Fassade als Gegenstück zur lesenden PortalApi; scoped,
        // damit sie dieselbe memoisierte PortalAuth/PortalApi teilt und der
        // Connector pro Request einmal auf tries = 1 gesetzt wird.
        $this->app->scoped(PortalWriter::class);
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
     * Restore `nativephp-internal.running` when the device froze it into a cached config.
     *
     * NativePHP Mobile 4.x runs `config:cache` once per app update, inside the
     * artisan embed (`LaravelEnvironment.kt`, "the biggest Laravel-side cold-start
     * lever"). That embed — `native_run_artisan_command()` in `php_bridge.c` — is
     * the ONE entry point that does not set `NATIVEPHP_RUNNING`; the request,
     * worker, ephemeral and persistent-boot entry points all do. So the value
     * baked into `bootstrap/cache/config.php` is `false`, and because a cached
     * config also skips `mergeConfigFrom`, every later request on the device reads
     * that frozen `false` — measured on the emulator (v1.10.0/126): the app booted
     * to the logged-out shell, `window.__nostrMobile` was `false` and the chat
     * island reported "Nip07 is not enabled". 3.3.7 had no `config:cache` in its
     * boot sequence, which is why this only appears now.
     *
     * The environment variable itself is intact per request: the persistent
     * dispatch prologue exports it and writes `$_SERVER['NATIVEPHP_RUNNING']`
     * before Laravel boots, which is exactly why NativePHP's own `Route::native`
     * fallback asks `env()` AND `config()` while the rest of the package only asks
     * `config()`. Repairing the config value instead of teaching each reader that
     * trick means NativePHP's own gates (temp filesystem, package migrations, the
     * Vite hot file) see the same truth as our own `Chassis::istApp()`.
     *
     * Direction on doubt: no variable, no change — a web request must never be
     * told it is running inside the app shell. `register()` is early enough
     * because every provider registers before any provider boots, and the package
     * reads the flag in `packageBooted()`.
     *
     * `Env::get()` and not the `env()` helper on purpose. Larastan forbids `env()`
     * outside `config/` because it returns null once the config is cached — true
     * for anything that came from `.env`, and exactly inverted here: this variable
     * never lived in `.env`, the native runtime exports it per request, so a cached
     * config is the only situation in which it has to be read. `Env::get()` reads
     * `$_SERVER`, `$_ENV` and `getenv()` alike, which matters because the runtime
     * writes it through more than one of them.
     */
    protected function repairNativeRuntimeFlag(): void
    {
        if (config('nativephp-internal.running')) {
            return;
        }

        if (! Env::get('NATIVEPHP_RUNNING')) {
            return;
        }

        config(['nativephp-internal.running' => true]);
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
            if (in_array($event->command, self::NATIVE_BUILD_COMMANDS, true) && $this->app->make(AndroidManifestPatcher::class)->ensureAll()) {
                $event->output->writeln('<info>AndroidManifest.xml gepatcht: singleTask + Amber-<queries> (NIP-55-ContentResolver).</info>');
            }
        });

        Event::listen(CommandFinished::class, function (CommandFinished $event): void {
            if ($event->command === 'native:install' && $this->app->make(AndroidManifestPatcher::class)->ensureAll()) {
                $event->output->writeln('<info>AndroidManifest.xml gepatcht: singleTask + Amber-<queries> (NIP-55-ContentResolver).</info>');
            }
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        // UTC-Zeitpunkt → Anzeige-Zeitzone des Nutzers (Profil-Einstellung,
        // Default Europe/Berlin). Liest sich in Blades als
        // `$date->forDisplay()->translatedFormat(…)`.
        CarbonImmutable::macro('forDisplay', function (): CarbonImmutable {
            /** @var CarbonImmutable $this */
            return Clock::toDisplay($this);
        });

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
