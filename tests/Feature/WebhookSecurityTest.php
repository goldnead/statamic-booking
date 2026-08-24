<?php

namespace Goldnead\StatamicBooking\Tests\Feature;

use Goldnead\StatamicBooking\Models\Booking;
use Goldnead\StatamicBooking\Tests\TestCase;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;

/**
 * Every test here TRIES to write a booking it should not be allowed to write.
 *
 * A webhook endpoint is an unauthenticated write endpoint wearing a signature.
 * Asserting that the happy path works proves nothing about that; only an
 * attempt that must fail does.
 */
class WebhookSecurityTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-booking.endpoints', [
            'beratung' => ['secret' => 'geheim'],
        ]);
    }

    #[Test]
    public function a_delivery_without_a_signature_is_refused(): void
    {
        $this->deliver($this->created(), secret: null)->assertStatus(401);

        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function a_delivery_with_a_wrong_signature_is_refused(): void
    {
        $this->deliver($this->created(), signature: str_repeat('a', 64))->assertStatus(401);

        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function a_signature_over_a_different_body_is_refused(): void
    {
        // The exact forgery a naive implementation lets through: a valid digest,
        // just not of *this* body. Someone who captured one delivery and edits
        // the address would otherwise book in another person's name.
        $otherBody = json_encode($this->created(['payload' => ['uid' => 'cal-999']]));

        $this->deliver($this->created(), signature: hash_hmac('sha256', $otherBody, 'geheim'))
            ->assertStatus(401);

        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function an_endpoint_without_a_secret_refuses_everything(): void
    {
        config(['statamic-booking.endpoints' => ['offen' => ['secret' => null]]]);

        // Fail closed. "The site forgot to configure it" must never be the same
        // thing as "anyone may post here" — that is how an unconfigured install
        // becomes an open write endpoint.
        $this->deliver($this->created(), endpoint: 'offen', secret: null)->assertStatus(401);
        $this->deliver($this->created(), endpoint: 'offen', secret: '')->assertStatus(401);

        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function an_unknown_endpoint_is_a_404(): void
    {
        $this->deliver($this->created(), endpoint: 'gibt-es-nicht')->assertStatus(404);

        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function one_endpoints_secret_does_not_open_another(): void
    {
        config(['statamic-booking.endpoints' => [
            'beratung' => ['secret' => 'geheim'],
            'unterricht' => ['secret' => 'anderes-geheimnis'],
        ]]);

        // Separate funnels exist so that a leaked secret is contained. If any
        // secret opened any endpoint, the separation would be decorative.
        $this->deliver($this->created(), endpoint: 'unterricht', secret: 'geheim')->assertStatus(401);

        $this->assertSame(0, Booking::count());
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function stamped(array $payload, int $timestamp, ?int $signAs = null): TestResponse
    {
        $body = json_encode($payload);

        return $this->call('POST', '/!/statamic-booking/beratung', [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'X-Cal-Timestamp' => (string) $timestamp,
            'X-Cal-Signature-256' => hash_hmac('sha256', ($signAs ?? $timestamp).'.'.$body, 'geheim'),
        ]), $body);
    }

    #[Test]
    public function a_stale_delivery_is_refused(): void
    {
        config([
            'statamic-booking.signature.timestamp_header' => 'X-Cal-Timestamp',
            'statamic-booking.signature.tolerance_seconds' => 300,
        ]);

        $this->stamped($this->created(), now()->subHours(2)->getTimestamp())->assertStatus(401);

        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function a_replayed_delivery_cannot_be_given_a_fresh_timestamp(): void
    {
        config([
            'statamic-booking.signature.timestamp_header' => 'X-Cal-Timestamp',
            'statamic-booking.signature.tolerance_seconds' => 300,
        ]);

        // The actual attack, and the one the first version of this addon let
        // through: an attacker captured a genuine delivery two hours ago and
        // replays it now, simply writing the current time into the header. It
        // only fails because the timestamp is signed WITH the body — checking
        // an unsigned header against a tolerance stops honest senders and
        // nobody else.
        $this->stamped(
            $this->created(),
            timestamp: now()->getTimestamp(),
            signAs: now()->subHours(2)->getTimestamp(),
        )->assertStatus(401);

        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function a_fresh_signed_delivery_with_a_timestamp_is_accepted(): void
    {
        config([
            'statamic-booking.signature.timestamp_header' => 'X-Cal-Timestamp',
            'statamic-booking.signature.tolerance_seconds' => 300,
        ]);

        $this->stamped($this->created(), now()->getTimestamp())->assertOk();

        $this->assertSame(1, Booking::count());
    }

    #[Test]
    public function an_unknown_signature_algorithm_refuses_instead_of_erroring(): void
    {
        config(['statamic-booking.signature.algorithm' => 'sha256-gibt-es-nicht']);

        // A typo in the config would otherwise reach hash_hmac() as a ValueError
        // and answer 500 — where a clean refusal belongs.
        $this->deliver($this->created())->assertStatus(401);
    }

    #[Test]
    public function a_correctly_signed_delivery_is_accepted(): void
    {
        // The control. Without it the tests above would also pass on an endpoint
        // that refuses everything, which would prove nothing at all.
        $this->deliver($this->created())->assertOk();

        $this->assertSame(1, Booking::count());
    }
}
