<?php

namespace Goldnead\StatamicBooking;

use Goldnead\StatamicBooking\Support\BookingRecorder;
use Goldnead\StatamicBooking\Support\SignatureVerifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $viewNamespace = 'statamic-booking';

    protected $routes = [
        'web' => __DIR__.'/../routes/web.php',
    ];

    /**
     * The parent boots config off the addon directory, which is resolved
     * through the manifest and comes up empty in package test suites. Config is
     * merged explicitly in register() with an absolute path instead.
     */
    protected $config = false;

    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/statamic-booking.php', 'statamic-booking');

        $this->app->scoped(SignatureVerifier::class);
        $this->app->scoped(BookingRecorder::class);
    }

    public function bootAddon()
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'statamic-booking');

        // Resolved per request rather than baked into a cached route file.
        RateLimiter::for('statamic-booking', fn ($request) => Limit::perMinute(
            (int) config('statamic-booking.rate_limit', 60)
        )->by($request->ip()));

        // Unlike an optional feature, the table is the addon. There is nothing
        // this package does without it, so the migrations load unconditionally
        // and `php artisan migrate` is part of installing it.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->publishes([
            __DIR__.'/../config/statamic-booking.php' => config_path('statamic-booking.php'),
        ], 'statamic-booking-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'statamic-booking-migrations');
    }
}
