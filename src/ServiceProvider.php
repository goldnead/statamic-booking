<?php

namespace Goldnead\StatamicBooking;

use Goldnead\BrandContext\Settings\SettingsRegistry;
use Goldnead\StatamicBooking\Http\Controllers\Cp\BookingsController;
use Goldnead\StatamicBooking\Integrations\Insights\CancellationRate;
use Goldnead\StatamicBooking\Integrations\Insights\Cancelled;
use Goldnead\StatamicBooking\Integrations\Insights\HoursBooked;
use Goldnead\StatamicBooking\Integrations\Insights\Scheduled;
use Goldnead\StatamicBooking\Support\BookingRecorder;
use Goldnead\StatamicBooking\Support\Settings;
use Goldnead\StatamicBooking\Support\SignatureVerifier;
use Goldnead\StatamicPayments\Cp\SuiteNav;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Facades\Utility;
use Statamic\Providers\AddonServiceProvider;
use Throwable;

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

    /**
     * In `boot()`, nicht in `bootAddon()`, und das ist keine Stilfrage.
     *
     * brand-context legt die gespeicherten Werte aus einem `app->booted()` auf
     * die Config, absichtlich erst dann, damit jedes Provider-`boot()` seine
     * Anmeldung hinter sich hat. `bootAddon()` läuft selbst aus einem
     * `app->booted()`, und welches der beiden zuerst feuert, hängt an der
     * Ladereihenfolge der Pakete.
     */
    public function boot(): void
    {
        parent::boot();

        $this->app->make(SettingsRegistry::class)->register(Settings::class);
    }

    public function bootAddon()
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'statamic-booking');

        $this->bootUtility();
        $this->bootPermissions();
        $this->registerInsightsMetrics();

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
     * The metric handles this addon contributes, and the classes behind them.
     *
     * Handle and class both, so the registry can store the class name without
     * constructing anything to find out what it is called. Naming the handle
     * twice is the price of that laziness, and it is the cheaper half of the
     * trade: an install with twenty addons would otherwise build every metric
     * object of every one of them on a request that renders none.
     *
     * The handles are frozen from the moment they are registered — they end up
     * in saved dashboards and in URLs. Renaming one is a breaking change.
     *
     * @var array<class-string, string>
     */
    protected const INSIGHTS_METRICS = [
        Scheduled::class => 'booking.scheduled',
        Cancelled::class => 'booking.cancelled',
        CancellationRate::class => 'booking.cancellation_rate',
        HoursBooked::class => 'booking.hours_booked',
    ];

    /**
     * Offer the booking figures to the analytics addon, if it is there.
     *
     * From an `app->booted()` callback rather than from `bootAddon()`: the
     * sibling's container bindings only exist once its own provider has booted,
     * and this one may boot first. Registering earlier registers into nothing,
     * silently — an empty screen with no error anywhere, which is the worst
     * shape this failure could take.
     *
     * **Nothing here throws, ever.** A missing, half-installed or mid-upgrade
     * analytics addon must cost a few tiles on a screen nobody has open, never
     * a booking webhook. The guards are three, and each one has caught a real
     * variation of "installed but not quite": the class may be absent, the
     * container may refuse to build the manager, and an older release of the
     * sibling may have the facade without this method on it.
     *
     * The metric classes name the sibling's contract in their `extends` and
     * their type hints, which is safe precisely because of the first guard: PHP
     * loads a class when something touches it, and nothing touches these unless
     * the facade exists. Hence `suggest` in composer.json rather than `require`
     * — an install of this addon alone must not drag an analytics package in.
     */
    protected function registerInsightsMetrics(): void
    {
        $this->app->booted(function (): void {
            $facade = '\Goldnead\StatamicInsights\Facades\Insights';

            if (! class_exists($facade)) {
                return;
            }

            try {
                $manager = $facade::getFacadeRoot();

                // Asked of the object, never of the facade: a facade forwards
                // through `__callStatic` and declares none of what it forwards,
                // so the probe on the facade itself is always false.
                if (! is_object($manager) || ! method_exists($manager, 'registerMetric')) {
                    return;
                }

                foreach (self::INSIGHTS_METRICS as $class => $handle) {
                    $manager->registerMetric($class, $handle);
                }
            } catch (Throwable $e) {
                Log::warning('statamic-booking: the insights metrics could not be registered.', [
                    'exception' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Das Recht, das den Abschnitt dieses Addons auf der geteilten
     * Einstellungsseite freigibt.
     *
     * Ein eigenes, nicht `access bookings utility`: wer die Buchungsliste
     * ansehen darf, darf deshalb noch nicht die Aufbewahrungsfrist oder die
     * Signaturprüfung verstellen.
     */
    protected function bootPermissions(): self
    {
        Permission::extend(function (): void {
            Permission::group('statamic-booking', __('statamic-booking::settings.permission_group'), function (): void {
                Permission::register('manage booking settings')
                    ->label(__('statamic-booking::settings.permission_manage'));
            });
        });

        return $this;
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

        // Der Bildschirm bleibt eine Utility — dieselbe Route, dasselbe Recht.
        // Was fehlte, war der Weg dorthin: unter „Hilfsmittel" steht er
        // zwischen Cache und PHP-Info (Adrian, 03.09.2026).
        //
        // Anders als offers, funnels und products haengt dieses Addon nicht an
        // `statamic-payments`, kann den gemeinsamen Abschnittsnamen also nicht
        // voraussetzen. Steht payments daneben, landen beide im selben
        // Abschnitt; laeuft booking allein, bekommt es seinen eigenen. Das ist
        // die Absicht — ein Abschnitt „Verkauf" mit einem einzigen Eintrag
        // waere in einer Installation ohne Kasse eine Ueberschrift ohne Inhalt.
        Nav::extend(function ($nav) {
            // Erst aushaengen, dann einhaengen — sonst steht der Bildschirm
            // zweimal da: einmal unter „Hilfsmittel", wohin `Utility::register`
            // ihn haengt, und einmal hier. Die Registrierung bleibt, sie traegt
            // Route, Recht und Middleware.
            $nav->remove('Tools', 'Utilities', __('statamic-booking::messages.utility_nav'));

            $section = class_exists(SuiteNav::class)
                ? SuiteNav::section()
                : __('statamic-booking::messages.utility_nav');

            $nav->create(__('statamic-booking::messages.utility_nav'))
                ->section($section)
                ->icon('calendar')
                ->route('utilities.bookings')
                ->can('access bookings utility');
        });

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
