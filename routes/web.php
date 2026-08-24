<?php

use Goldnead\StatamicBooking\Http\Controllers\WebhookController;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Support\Facades\Route;

/*
 * CSRF is dropped: the caller is a server, not a browser, and it authenticates
 * with an HMAC signature over the raw body — a stronger proof than a session
 * token, and the only one a provider can give.
 */
Route::post('/!/statamic-booking/{endpoint}', WebhookController::class)
    // Named limiter, not an inline value: an inline `throttle:60,1` is baked in
    // by `route:cache`, so raising the limit after an incident would need a
    // cache clear nobody remembers. The limiter resolves per request.
    ->middleware(['throttle:statamic-booking'])
    ->withoutMiddleware([
        // Drei Namen, nicht einer. In Laravel 12/13 steht in der `web`-Gruppe
        // `PreventRequestForgery`; `VerifyCsrfToken` ist dessen **Unterklasse**,
        // und `Router::resolveMiddleware()` entfernt nur, was Unterklasse des
        // Ausgeschlossenen ist — die Unterklasse auszuschliessen entfernt die
        // Oberklasse also nicht. Die Prüfung bleibt stehen, der Anbieter
        // schickt kein Token, und jede echte Zustellung endet mit 419.
        //
        // Im Testlauf ist das unsichtbar: `PreventRequestForgery::handle()`
        // steigt bei `runningUnitTests()` sofort aus. Deshalb prüft ein Test
        // die aufgesammelte Middleware-Liste statt eine Anfrage.
        //
        // Dieselben drei Namen schliesst Statamic selbst aus
        // (`vendor/statamic/cms/routes/web.php:106`).
        'App\Http\Middleware\VerifyCsrfToken',
        'Illuminate\Foundation\Http\Middleware\VerifyCsrfToken',
        'Illuminate\Foundation\Http\Middleware\ValidateCsrfToken',
        'Illuminate\Foundation\Http\Middleware\PreventRequestForgery',
    ])
    ->where('endpoint', '[A-Za-z0-9_-]+')
    ->name('statamic-booking.webhook');
