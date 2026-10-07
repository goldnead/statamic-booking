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
 * Also dispatched when a request that needed confirming is accepted: that is
 * the moment the appointment starts to exist.
 */
class BookingMade
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $payload  The provider's booking object as delivered
     *                                         (Cal.com: the `payload` key). The row keeps only what
     *                                         every site needs; whatever a site hangs its own
     *                                         consequences on — `metadata` from the booking link,
     *                                         form `responses` — is here. It carries personal data
     *                                         and is not stored.
     */
    public function __construct(
        public readonly Booking $booking,
        public readonly array $payload = [],
    ) {}
}
