<?php

namespace Goldnead\StatamicBooking\Scopes;

use Statamic\Query\Scopes\Filter;

/**
 * Upcoming or past.
 *
 * The question actually asked of this screen on a Monday morning is "what is
 * coming", and the answer is buried the moment a few hundred past appointments
 * sit above it.
 */
class BookingWhen extends Filter
{
    public $pinned = true;

    public static function title()
    {
        return __('statamic-booking::messages.filter_when');
    }

    public function fieldItems()
    {
        return [
            'when' => [
                'type' => 'select',
                'placeholder' => __('statamic-booking::messages.filter_any_time'),
                'options' => [
                    'upcoming' => __('statamic-booking::messages.filter_upcoming'),
                    'past' => __('statamic-booking::messages.filter_past'),
                ],
            ],
        ];
    }

    public function apply($query, $values)
    {
        $when = $values['when'] ?? null;

        if ($when === 'upcoming') {
            // A booking whose time the provider sent unparseably has no date to
            // compare, and it is deliberately kept. It belongs with "upcoming":
            // it is unresolved, and the point of this filter is what still needs
            // attention.
            $query->where(fn ($q) => $q->where('scheduled_at', '>=', now())->orWhereNull('scheduled_at'));
        }

        if ($when === 'past') {
            $query->where('scheduled_at', '<', now());
        }
    }

    public function badge($values)
    {
        $when = $values['when'] ?? null;

        return match ($when) {
            'upcoming' => __('statamic-booking::messages.filter_upcoming'),
            'past' => __('statamic-booking::messages.filter_past'),
            default => null,
        };
    }

    public function visibleTo($key)
    {
        return $key === 'statamic-booking-bookings';
    }
}
