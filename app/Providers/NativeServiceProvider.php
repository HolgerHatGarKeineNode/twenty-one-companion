<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use Native\Mobile\Providers\BrowserServiceProvider;
use Native\Mobile\Providers\DialogServiceProvider;
use Native\Mobile\Providers\NetworkServiceProvider;
use Native\Mobile\Providers\SecureStorageServiceProvider;
use Native\Mobile\Providers\ShareServiceProvider;

class NativeServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }

    /**
     * The NativePHP plugins to enable.
     *
     * Only plugins listed here will be compiled into your native builds.
     * This is a security measure to prevent transitive dependencies from
     * automatically registering plugins without your explicit consent.
     *
     * @return array<int, class-string<ServiceProvider>>
     */
    public function plugins(): array
    {
        return [
            BrowserServiceProvider::class,
            DialogServiceProvider::class,
            NetworkServiceProvider::class,
            ShareServiceProvider::class,
            SecureStorageServiceProvider::class,
            \Developernauts\NativephpMobileLocales\NativephpMobileLocalesServiceProvider::class,
            \Native\Mobile\Providers\FileServiceProvider::class,
            \Native\Mobile\Providers\CameraServiceProvider::class,
        
        
        

        ];
    }
}
