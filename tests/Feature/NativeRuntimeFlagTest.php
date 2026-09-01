<?php

use App\Providers\AppServiceProvider;

// NativePHP Mobile 4.x caches the config on the device (`config:cache` in the
// boot sequence) from an artisan embed that never sets NATIVEPHP_RUNNING, so
// `nativephp-internal.running` freezes to false while the request environment
// still says true. AppServiceProvider::register() repairs that; these cases pin
// both directions of the repair, because the device is the only other place it
// can be observed.

afterEach(function (): void {
    unset($_SERVER['NATIVEPHP_RUNNING']);
});

it('lifts the runtime flag when only the environment still carries it', function () {
    config(['nativephp-internal.running' => false]);
    $_SERVER['NATIVEPHP_RUNNING'] = 'true';

    (new AppServiceProvider($this->app))->register();

    expect(config('nativephp-internal.running'))->toBeTrue();
});

it('leaves the runtime flag false without the environment variable', function () {
    config(['nativephp-internal.running' => false]);

    (new AppServiceProvider($this->app))->register();

    // Fail-closed: a web request must never be told it runs inside the app shell.
    expect(config('nativephp-internal.running'))->toBeFalse();
});

it('keeps an already true runtime flag true', function () {
    config(['nativephp-internal.running' => true]);

    (new AppServiceProvider($this->app))->register();

    expect(config('nativephp-internal.running'))->toBeTrue();
});
