<?php

namespace Goldnead\StatamicBooking\Events;

use Goldnead\StatamicBooking\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Somebody asked for an appointment that the organiser still has to accept.
 *
 * Deliberately a different event from {@see BookingMade}: nothing has been
 * agreed yet, and a listener that confirms appointments must not hear about
 * this one. A listener that only notes the interest (a lead, a notice to the
 * organiser) may. If the request is accepted, {@see BookingMade} follows for
 * the same row; if it is declined, {@see BookingCancelled}.
 */
class BookingRequested
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload  The provider's booking object as delivered. See {@see BookingMade}.
     */
    public function __construct(
        public readonly Booking $booking,
        public readonly array $payload = [],
    ) {}
}
