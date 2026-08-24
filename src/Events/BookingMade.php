<?php

namespace Goldnead\StatamicBooking\Events;

use Goldnead\StatamicBooking\Models\Booking;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The seam. What happens next belongs to the site, not to this addon.
 *
 * Dispatched once per real change, never on a redelivery — the recorder only
 * fires when the row actually moved. A listener may therefore assume it is
 * being told something new.
 */
class BookingMade
{
    use Dispatchable;

    public function __construct(public readonly Booking $booking) {}
}
