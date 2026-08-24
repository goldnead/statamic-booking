<?php

namespace Goldnead\StatamicBooking\Tests\Feature;

use Goldnead\StatamicBooking\Events\BookingCancelled;
use Goldnead\StatamicBooking\Events\BookingMade;
use Goldnead\StatamicBooking\Events\BookingRescheduled;
use Goldnead\StatamicBooking\Models\Booking;
use Goldnead\StatamicBooking\Tests\TestCase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

class BookingLifecycleTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-booking.endpoints', [
            'beratung' => ['secret' => 'geheim'],
        ]);
    }

    #[Test]
    public function it_records_what_the_provider_sent(): void
    {
        $this->deliver($this->created())->assertOk();

        $booking = Booking::first();

        $this->assertSame('beratung', $booking->endpoint);
        $this->assertSame('cal-1', $booking->external_id);
        $this->assertSame('maria@example.com', $booking->email);
        $this->assertSame('Maria Beispiel', $booking->name);
        $this->assertSame('Europe/Berlin', $booking->timezone);
        $this->assertSame(30, $booking->duration_minutes);
        $this->assertSame('https://zoom.example/abc', $booking->meeting_url);
    }

    #[Test]
    public function the_same_delivery_twice_makes_one_booking(): void
    {
        // Providers redeliver. Cal.com retries on any non-2xx, and a proxy can
        // duplicate a request nobody retried. Two rows would mean two
        // appointments in every downstream count.
        $this->deliver($this->created())->assertOk();
        $this->deliver($this->created())->assertOk();
        $this->deliver($this->created())->assertOk();

        $this->assertSame(1, Booking::count());
    }

    #[Test]
    public function a_redelivery_fires_no_second_event(): void
    {
        Event::fake([BookingMade::class]);

        $this->deliver($this->created());
        $this->deliver($this->created());

        // The seam must be able to assume it is being told something new —
        // otherwise every listener has to carry its own idempotency, and one of
        // them will forget.
        Event::assertDispatchedTimes(BookingMade::class, 1);
    }

    #[Test]
    public function the_same_id_on_a_different_endpoint_is_a_different_booking(): void
    {
        config(['statamic-booking.endpoints' => [
            'beratung' => ['secret' => 'geheim'],
            'unterricht' => ['secret' => 'geheim'],
        ]]);

        $this->deliver($this->created());
        $this->deliver($this->created(), endpoint: 'unterricht');

        // Two funnels are two Cal.com accounts as far as ids go; assuming they
        // share a namespace would silently drop one of the two bookings.
        $this->assertSame(2, Booking::count());
    }

    #[Test]
    public function a_reschedule_moves_the_booking_rather_than_adding_one(): void
    {
        Event::fake([BookingMade::class, BookingRescheduled::class]);

        $this->deliver($this->created());
        $this->deliver($this->created([
            'triggerEvent' => 'BOOKING_RESCHEDULED',
            'payload' => ['startTime' => '2026-09-02T14:00:00Z', 'endTime' => '2026-09-02T15:00:00Z'],
        ]));

        $this->assertSame(1, Booking::count());

        $booking = Booking::first();
        $this->assertSame(Booking::STATUS_RESCHEDULED, $booking->status);
        $this->assertSame(60, $booking->duration_minutes);
        $this->assertSame('2026-09-02', $booking->scheduled_at->toDateString());

        Event::assertDispatched(BookingRescheduled::class);
    }

    #[Test]
    public function a_reschedule_for_an_unknown_booking_becomes_a_booking(): void
    {
        Event::fake([BookingMade::class]);

        // The create webhook can be lost. The visitor still has an appointment,
        // and dropping it because we missed the first message would be the
        // worse failure.
        $this->deliver($this->created(['triggerEvent' => 'BOOKING_RESCHEDULED']));

        $this->assertSame(1, Booking::count());
        $this->assertSame(Booking::STATUS_BOOKED, Booking::first()->status);
        Event::assertDispatched(BookingMade::class);
    }

    #[Test]
    public function a_cancellation_marks_the_booking_without_deleting_it(): void
    {
        Event::fake([BookingCancelled::class]);

        $this->deliver($this->created());
        $this->deliver($this->created(['triggerEvent' => 'BOOKING_CANCELLED']));

        $booking = Booking::first();

        // Kept, not deleted: "there was an appointment and it was cancelled" is
        // a different fact from "there was never one", and only one of them can
        // be reconstructed later.
        $this->assertNotNull($booking);
        $this->assertTrue($booking->isCancelled());
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->status);
        Event::assertDispatchedTimes(BookingCancelled::class, 1);
    }

    #[Test]
    public function cancelling_twice_fires_one_event(): void
    {
        Event::fake([BookingCancelled::class]);

        $this->deliver($this->created());
        $this->deliver($this->created(['triggerEvent' => 'BOOKING_CANCELLED']));
        $this->deliver($this->created(['triggerEvent' => 'BOOKING_CANCELLED']));

        Event::assertDispatchedTimes(BookingCancelled::class, 1);
    }

    #[Test]
    public function a_payload_without_the_providers_id_is_dropped(): void
    {
        // Nothing to be idempotent about. Recording it would mean a redelivery
        // creates a second booking, which is worse than losing one malformed
        // call — and the signature already proved it came from the provider,
        // so this is their bug to see in our log, not ours to paper over.
        $this->deliver($this->created(['payload' => ['uid' => null]]))
            ->assertOk()
            ->assertJson(['recorded' => false]);

        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function an_unhandled_trigger_is_accepted_and_ignored(): void
    {
        // 200, deliberately: a trigger this addon does not handle is not the
        // provider's fault, and anything else makes Cal.com retry it forever.
        $this->deliver($this->created(['triggerEvent' => 'MEETING_ENDED']))
            ->assertOk()
            ->assertJson(['recorded' => false]);

        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function an_unparseable_time_still_records_the_booking(): void
    {
        $this->deliver($this->created(['payload' => ['startTime' => 'gestern']]))->assertOk();

        $this->assertSame(1, Booking::count());
        $this->assertNull(Booking::first()->scheduled_at);
    }

    #[Test]
    public function a_reschedule_after_a_cancellation_does_not_revive_it(): void
    {
        Event::fake([BookingRescheduled::class]);

        $this->deliver($this->created());
        $this->deliver($this->created(['triggerEvent' => 'BOOKING_CANCELLED']));

        // Providers retry on any non-2xx and the order is not guaranteed, so a
        // reschedule landing after a cancellation is ordinary. Reviving the row
        // would put a cancelled appointment back on the site and tell every
        // listener it had moved.
        $this->deliver($this->created(['triggerEvent' => 'BOOKING_RESCHEDULED']));

        $booking = Booking::first();

        $this->assertTrue($booking->isCancelled());
        $this->assertSame(Booking::STATUS_CANCELLED, $booking->status);
        Event::assertNotDispatched(BookingRescheduled::class);
    }

    #[Test]
    public function a_rejected_request_stops_being_upcoming(): void
    {
        $this->deliver($this->created(['triggerEvent' => 'BOOKING_REQUESTED']));
        $this->deliver($this->created(['triggerEvent' => 'BOOKING_REJECTED']));

        // Before this was handled, a declined request stayed on `booked` for
        // ever and showed on the site as an appointment that will never happen.
        $booking = Booking::first();

        $this->assertSame(Booking::STATUS_REJECTED, $booking->status);
        $this->assertTrue($booking->isCancelled());
        $this->assertSame(0, Booking::query()->upcoming()->count());
    }

    #[Test]
    public function a_request_is_recorded_but_is_not_yet_an_appointment(): void
    {
        Event::fake([BookingMade::class]);

        $this->deliver($this->created(['triggerEvent' => 'BOOKING_REQUESTED']));

        $this->assertSame(1, Booking::count());
        $this->assertSame(Booking::STATUS_REQUESTED, Booking::first()->status);

        // Not upcoming, and no event: nothing has been agreed, and a listener
        // told "a booking was made" would confirm an appointment the organiser
        // may still decline.
        $this->assertSame(0, Booking::query()->upcoming()->count());
        Event::assertNotDispatched(BookingMade::class);
    }

    #[Test]
    public function an_accepted_request_becomes_a_booking(): void
    {
        Event::fake([BookingMade::class]);

        $this->deliver($this->created(['triggerEvent' => 'BOOKING_REQUESTED']));
        $this->deliver($this->created());

        $this->assertSame(1, Booking::count());
        Event::assertDispatchedTimes(BookingMade::class, 0);
    }
}
