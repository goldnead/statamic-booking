<?php

namespace Goldnead\StatamicBooking\Tests\Feature;

use Goldnead\StatamicBooking\Events\BookingCancelled;
use Goldnead\StatamicBooking\Events\BookingMade;
use Goldnead\StatamicBooking\Events\BookingRequested;
use Goldnead\StatamicBooking\Events\BookingRescheduled;
use Goldnead\StatamicBooking\Models\Booking;
use Goldnead\StatamicBooking\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/**
 * What a site needs from the seam to hang real consequences on it.
 *
 * The sequences here are Cal.com's own, read off real deliveries: an event
 * type that needs confirming sends REQUESTED and then CREATED with the same
 * uid; a reschedule creates a new booking and sends RESCHEDULED with the new
 * uid in `uid` and the old one in `rescheduleUid`.
 */
class HostSeamTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-booking.endpoints', [
            'beratung' => ['secret' => 'geheim'],
        ]);
    }

    #[Test]
    public function a_confirmed_request_becomes_a_booking_and_says_so_once(): void
    {
        Event::fake([BookingMade::class, BookingRequested::class]);
        $this->travelTo('2026-08-01 12:00:00');

        $this->deliver($this->created(['triggerEvent' => 'BOOKING_REQUESTED']));
        $this->assertSame(0, Booking::query()->upcoming()->count());

        $this->deliver($this->created());
        $this->deliver($this->created());

        $this->assertSame(1, Booking::count());
        $this->assertSame(Booking::STATUS_BOOKED, Booking::first()->status);
        $this->assertSame(1, Booking::query()->upcoming()->count());

        Event::assertDispatchedTimes(BookingRequested::class, 1);
        Event::assertDispatchedTimes(BookingMade::class, 1);
    }

    #[Test]
    public function every_event_carries_what_the_provider_sent(): void
    {
        Event::fake([BookingMade::class]);

        $this->deliver($this->created(['payload' => ['metadata' => ['package' => 'p-1']]]));

        Event::assertDispatched(BookingMade::class, function (BookingMade $event) {
            return $event->payload['metadata']['package'] === 'p-1'
                && $event->payload['uid'] === 'cal-1';
        });
    }

    #[Test]
    public function a_reschedule_moves_the_original_booking_to_its_new_uid(): void
    {
        Event::fake([BookingMade::class, BookingRescheduled::class]);

        $this->deliver($this->created());
        $this->deliver($this->rescheduled());

        $this->assertSame(1, Booking::count());

        $booking = Booking::first();
        $this->assertSame('cal-2', $booking->external_id);
        $this->assertSame(Booking::STATUS_RESCHEDULED, $booking->status);
        $this->assertSame('2026-09-03', $booking->scheduled_at->toDateString());

        // One appointment that moved, not a second one: BookingMade would make
        // every listener book it a second time.
        Event::assertDispatchedTimes(BookingMade::class, 1);
        Event::assertDispatched(BookingRescheduled::class, fn (BookingRescheduled $event) => $event->previousExternalId === 'cal-1');
    }

    #[Test]
    public function a_redelivered_reschedule_fires_nothing_new(): void
    {
        Event::fake([BookingRescheduled::class]);

        $this->deliver($this->created());
        $this->deliver($this->rescheduled());
        $this->deliver($this->rescheduled());

        $this->assertSame(1, Booking::count());
        Event::assertDispatchedTimes(BookingRescheduled::class, 1);
    }

    #[Test]
    public function the_moved_booking_can_still_be_cancelled_under_its_new_uid(): void
    {
        Event::fake([BookingCancelled::class]);

        $this->deliver($this->created());
        $this->deliver($this->rescheduled());
        $this->deliver($this->created(['triggerEvent' => 'BOOKING_CANCELLED', 'payload' => ['uid' => 'cal-2']]));

        $this->assertTrue(Booking::first()->isCancelled());
        Event::assertDispatchedTimes(BookingCancelled::class, 1);
    }

    #[Test]
    public function a_late_redelivery_for_the_old_uid_does_not_bring_the_old_slot_back(): void
    {
        Event::fake([BookingMade::class, BookingCancelled::class]);

        $this->deliver($this->created());
        $this->deliver($this->rescheduled());

        // The first CREATED timed out and Cal.com delivers it again; a stray
        // cancellation for the old uid arrives too. Cal.com closes the old
        // booking itself on a reschedule, the appointment lives on.
        $this->deliver($this->created());
        $this->deliver($this->created(['triggerEvent' => 'BOOKING_REQUESTED']));
        $this->deliver($this->created(['triggerEvent' => 'BOOKING_CANCELLED']));

        $this->assertSame(1, Booking::count());
        $this->assertSame('cal-2', Booking::first()->external_id);
        $this->assertFalse(Booking::first()->isCancelled());
        Event::assertDispatchedTimes(BookingMade::class, 1);
        Event::assertNotDispatched(BookingCancelled::class);
    }

    #[Test]
    public function a_cancellation_that_overtakes_its_reschedule_closes_the_original_too(): void
    {
        Event::fake([BookingCancelled::class, BookingRescheduled::class]);
        $this->travelTo('2026-08-01 12:00:00');

        $this->deliver($this->created());
        $this->deliver($this->created(['triggerEvent' => 'BOOKING_CANCELLED', 'payload' => ['uid' => 'cal-2']]));
        $this->deliver($this->rescheduled());

        // Neither the original slot nor the new one is an appointment now.
        $this->assertSame(0, Booking::query()->upcoming()->count());
        $this->assertTrue(Booking::where('external_id', 'cal-1')->first()->isCancelled());
        Event::assertDispatchedTimes(BookingCancelled::class, 2);
        Event::assertNotDispatched(BookingRescheduled::class);
    }

    #[Test]
    public function a_moved_request_is_still_a_request(): void
    {
        $this->travelTo('2026-08-01 12:00:00');

        $this->deliver($this->created(['triggerEvent' => 'BOOKING_REQUESTED']));
        $this->deliver($this->rescheduled());

        $this->assertSame(Booking::STATUS_REQUESTED, Booking::first()->status);
        $this->assertSame(0, Booking::query()->upcoming()->count());
    }

    #[Test]
    public function a_cancellation_for_an_unknown_booking_is_passed_on_and_wins_over_a_late_creation(): void
    {
        Event::fake([BookingCancelled::class, BookingMade::class]);
        $this->travelTo('2026-08-01 12:00:00');

        // A booking the site knew from somewhere else (an import, the system
        // before this one), or a CREATED that has not arrived yet.
        $this->deliver($this->created(['triggerEvent' => 'BOOKING_CANCELLED']))
            ->assertOk()
            ->assertJson(['recorded' => true]);

        Event::assertDispatchedTimes(BookingCancelled::class, 1);

        // The overtaken CREATED arrives late. The appointment no longer exists.
        $this->deliver($this->created());
        $this->deliver($this->created(['triggerEvent' => 'BOOKING_CANCELLED']));

        $this->assertSame(1, Booking::count());
        $this->assertTrue(Booking::first()->isCancelled());
        $this->assertSame(0, Booking::query()->upcoming()->count());
        Event::assertNotDispatched(BookingMade::class);
        Event::assertDispatchedTimes(BookingCancelled::class, 1);
    }

    #[Test]
    public function a_failing_listener_leaves_nothing_behind_so_the_retry_runs_it_again(): void
    {
        $calls = 0;

        Event::listen(BookingMade::class, function () use (&$calls) {
            $calls++;

            if ($calls === 1) {
                throw new RuntimeException('Datenbank kurz weg');
            }
        });

        $this->withoutExceptionHandling();

        try {
            $this->deliver($this->created());
            $this->fail('Der Fehler des Zuhoerers muss beim Anbieter ankommen, sonst wiederholt er nicht.');
        } catch (RuntimeException) {
            // expected
        }

        // Before: the row was written, the listener failed, and the provider's
        // retry found the row and fired nothing. The consequence was lost for
        // good while the booking looked recorded.
        $this->assertSame(0, Booking::count());

        $this->deliver($this->created())->assertOk();

        $this->assertSame(1, Booking::count());
        $this->assertSame(2, $calls);
    }

    /**
     * @return array<string, mixed>
     */
    private function rescheduled(): array
    {
        return $this->created([
            'triggerEvent' => 'BOOKING_RESCHEDULED',
            'payload' => [
                'uid' => 'cal-2',
                'rescheduleUid' => 'cal-1',
                'startTime' => '2026-09-03T10:00:00Z',
                'endTime' => '2026-09-03T10:30:00Z',
            ],
        ]);
    }
}
