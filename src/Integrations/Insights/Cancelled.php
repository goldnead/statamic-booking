<?php

namespace Goldnead\StatamicBooking\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How many bookings were called off in the period.
 *
 * **On `cancelled_at`, the day the cancellation arrived** — not the day of the
 * appointment it removed. A Tuesday in which four September dates were dropped
 * is a Tuesday on which four things happened, and dating them to September
 * would hide the day somebody would want to look at.
 *
 * That is also why this figure is **not** the numerator of
 * {@see CancellationRate}: this one counts cancellations as events in the
 * window, the rate counts the window's appointments that did not survive. On a
 * quiet week the two can differ in both directions, which is the honest shape
 * of two different questions rather than an inconsistency.
 *
 * `cancelled_at` is written for `cancelled` **and** `rejected` — the visitor
 * calling off and the organiser declining are different facts, and the `status`
 * split of {@see Scheduled} keeps them apart. Both mean the appointment is not
 * happening, which is what this counts.
 */
class Cancelled extends BookingMetric
{
    protected function timestamp(): string
    {
        return 'cancelled_at';
    }

    public function handle(): string
    {
        return 'booking.cancelled';
    }

    public function label(): string
    {
        return __('statamic-booking::messages.metric_cancelled');
    }

    public function description(): ?string
    {
        return __('statamic-booking::messages.metric_cancelled_description');
    }

    public function unit(): string
    {
        return Unit::COUNT;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        return (int) $this->inPeriod($query)->count();
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured) => (int) $measured,
            $this->bucketed($this->inPeriod($query), $query, 'count(*)'),
        );
    }
}
