<?php

namespace Goldnead\StatamicBooking\Tests;

use Goldnead\StatamicBooking\ServiceProvider;
use Illuminate\Testing\TestResponse;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('statamic.system.multisite', false);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
    }

    /**
     * A signed delivery, the way the provider makes one.
     *
     * @param  array<string, mixed>  $payload
     */
    protected function deliver(array $payload, string $endpoint = 'beratung', ?string $secret = 'geheim', ?string $signature = null): TestResponse
    {
        $body = json_encode($payload);

        $headers = ['Content-Type' => 'application/json'];

        if ($signature !== null) {
            $headers['X-Cal-Signature-256'] = $signature;
        } elseif ($secret !== null) {
            $headers['X-Cal-Signature-256'] = hash_hmac('sha256', $body, $secret);
        }

        return $this->call('POST', '/!/statamic-booking/'.$endpoint, [], [], [], $this->transformHeadersToServerVars($headers), $body);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    protected function created(array $overrides = []): array
    {
        return array_replace_recursive([
            'triggerEvent' => 'BOOKING_CREATED',
            'payload' => [
                'uid' => 'cal-1',
                'title' => 'Erstgespräch',
                'startTime' => '2026-09-01T10:00:00Z',
                'endTime' => '2026-09-01T10:30:00Z',
                'eventTypeId' => 7,
                'attendees' => [[
                    'name' => 'Maria Beispiel',
                    'email' => 'maria@example.com',
                    'timeZone' => 'Europe/Berlin',
                ]],
                'metadata' => ['videoCallUrl' => 'https://zoom.example/abc'],
            ],
        ], $overrides);
    }
}
