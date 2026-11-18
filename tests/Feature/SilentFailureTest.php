<?php

namespace Goldnead\StatamicBooking\Tests\Feature;

use Goldnead\StatamicBooking\Events\BookingMade;
use Goldnead\StatamicBooking\Models\Booking;
use Goldnead\StatamicBooking\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

/**
 * Deliveries that used to be dropped without a trace.
 */
class SilentFailureTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-booking.endpoints', [
            'beratung' => ['secret' => 'geheim'],
        ]);
    }

    #[Test]
    public function a_delivery_without_a_uid_is_a_422_and_logged(): void
    {
        Log::spy();

        $payload = $this->created();
        unset($payload['payload']['uid']);

        $this->deliver($payload)->assertStatus(422);

        $this->assertSame(0, Booking::count());
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'uid'));
    }

    #[Test]
    public function a_body_that_is_not_json_is_a_422(): void
    {
        Log::spy();

        $body = 'kein json';

        $this->call('POST', '/!/statamic-booking/beratung', [], [], [], $this->transformHeadersToServerVars([
            'Content-Type' => 'application/json',
            'X-Cal-Signature-256' => hash_hmac('sha256', $body, 'geheim'),
        ]), $body)->assertStatus(422);

        Log::shouldHaveReceived('warning')->once();
    }

    #[Test]
    public function an_unhandled_trigger_stays_a_200_but_is_logged(): void
    {
        Log::spy();

        $this->deliver($this->created(['triggerEvent' => 'MEETING_ENDED']))
            ->assertOk()
            ->assertJson(['recorded' => false]);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'MEETING_ENDED'));
    }

    #[Test]
    public function an_unreadable_start_time_is_logged(): void
    {
        Log::spy();

        $this->deliver($this->created(['payload' => ['startTime' => 'irgendwann']]))->assertOk();

        $this->assertNull(Booking::first()->scheduled_at);
        Log::shouldHaveReceived('warning')->once()->withArgs(fn ($message) => str_contains($message, 'startTime'));
    }

    #[Test]
    public function a_reschedule_whose_original_was_cancelled_still_books_the_new_uid(): void
    {
        Event::fake([BookingMade::class]);

        $this->deliver($this->created());
        $this->deliver($this->created(['triggerEvent' => 'BOOKING_CANCELLED']));

        // Cal.com cancels the old booking and issues a new uid. The new
        // appointment must exist, not the cancelled row handed back.
        $this->deliver($this->created([
            'triggerEvent' => 'BOOKING_RESCHEDULED',
            'payload' => ['uid' => 'cal-2', 'rescheduleUid' => 'cal-1', 'startTime' => '2026-09-03T10:00:00Z', 'endTime' => '2026-09-03T10:30:00Z'],
        ]))->assertOk();

        $this->assertSame(2, Booking::count());

        $new = Booking::where('external_id', 'cal-2')->first();
        $this->assertNotNull($new);
        $this->assertSame(Booking::STATUS_BOOKED, $new->status);
        $this->assertFalse($new->isCancelled());
        $this->assertTrue(Booking::where('external_id', 'cal-1')->first()->isCancelled());
        Event::assertDispatchedTimes(BookingMade::class, 2);
    }
}
