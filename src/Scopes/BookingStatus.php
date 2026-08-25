<?php

namespace Goldnead\StatamicBooking\Scopes;

use Goldnead\StatamicBooking\Models\Booking;
use Statamic\Query\Scopes\Filter;

/**
 * The status filter, as a real Statamic scope.
 *
 * Not as a query parameter the controller reads: `Listing` builds its requests
 * from a fixed set of keys and rewrites the address bar from the same set, so a
 * parameter it does not know about survives the first page load and then
 * disappears. A scope is the shape core understands — it renders the filter
 * menu, carries the active badge, and stays put across sorting and paging.
 */
class BookingStatus extends Filter
{
    public $pinned = true;

    public static function title()
    {
        return __('statamic-booking::messages.column_status');
    }

    public function fieldItems()
    {
        return [
            'status' => [
                'type' => 'select',
                'placeholder' => __('statamic-booking::messages.filter_any_status'),
                'options' => [
                    Booking::STATUS_BOOKED => __('statamic-booking::messages.status_booked'),
                    Booking::STATUS_RESCHEDULED => __('statamic-booking::messages.status_rescheduled'),
                    Booking::STATUS_REQUESTED => __('statamic-booking::messages.status_requested'),
                    Booking::STATUS_CANCELLED => __('statamic-booking::messages.status_cancelled'),
                    Booking::STATUS_REJECTED => __('statamic-booking::messages.status_rejected'),
                ],
            ],
        ];
    }

    public function apply($query, $values)
    {
        $status = $values['status'] ?? null;

        // Anything unrecognised filters nothing. An empty list would read as
        // "there are no bookings", which is a different and worrying statement.
        if (! in_array($status, Booking::statuses(), true)) {
            return;
        }

        $query->where('status', $status);
    }

    public function badge($values)
    {
        $status = $values['status'] ?? null;

        if (! in_array($status, Booking::statuses(), true)) {
            return null;
        }

        return __('statamic-booking::messages.column_status').': '.__('statamic-booking::messages.status_'.$status);
    }

    public function visibleTo($key)
    {
        return $key === 'statamic-booking-bookings';
    }
}
