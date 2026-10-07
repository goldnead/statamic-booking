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
 *
 * Cal.com gives a rescheduled appointment a **new** id. The row follows it, so
 * `$booking->external_id` is the new one; whatever a site keyed on the old id
 * finds it in `$previousExternalId`.
 */
class BookingRescheduled
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload  The provider's booking object as delivered. See {@see BookingMade}.
     * @param  string|null  $previousExternalId  The provider's id before the move, null when it kept its id.
     */
    public function __construct(
        public readonly Booking $booking,
        public readonly array $payload = [],
        public readonly ?string $previousExternalId = null,
    ) {}
}
