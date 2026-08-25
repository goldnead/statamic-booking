<?php

namespace Goldnead\StatamicBooking;

use Goldnead\StatamicBooking\Http\Controllers\Cp\BookingsController;
use Goldnead\StatamicBooking\Support\BookingRecorder;
use Goldnead\StatamicBooking\Support\SignatureVerifier;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\RateLimiter;
use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $viewNamespace = 'statamic-booking';

    protected $routes = [
        'web' => __DIR__.'/../routes/web.php',
    ];

    /**
     * The Control Panel bundle. All three values must byte-match `laravel()` in
     * vite.config.js, or the CP loads a manifest that does not describe what is
     * on disk.
     *
     * @var array<string, mixed>
     */
    protected $vite = [
        'hotFile' => __DIR__.'/../dist/hot',
        'publicDirectory' => 'dist',
        'input' => ['resources/js/cp.js', 'resources/css/cp.css'],
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

        $this->bootUtility();

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

    /**
     * One screen, registered as a utility.
     *
     * A utility rather than a nav section of its own: registering here earns
     * the nav entry, the `access bookings utility` permission and the matching
     * `can:` middleware on the route, all from core. A hand-rolled nav entry
     * would need each of those written out, and the permission is the part
     * people forget.
     */
    protected function bootUtility(): self
    {
        // Registered inside `Utility::extend`, not straight in boot. `__()`
        // called during boot resolves before core's `Localize` middleware has
        // set the user's language, so the title and the description would be
        // frozen in the application locale: a German control panel showing
        // "Bookings" in the nav and an English description on the utilities
        // overview, above a screen that is entirely German.
        Utility::extend(fn () => $this->registerUtility());

        return $this;
    }

    protected function registerUtility(): void
    {
        Utility::register('bookings')
            ->action([BookingsController::class, 'index'])
            ->title(__('statamic-booking::messages.utility_title'))
            ->navTitle(__('statamic-booking::messages.utility_nav'))
            ->icon('calendar')
            ->description(__('statamic-booking::messages.utility_description'))
            ->docsUrl('https://github.com/goldnead/statamic-booking#readme');
    }
}
