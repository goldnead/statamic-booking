<?php

namespace Goldnead\StatamicBooking\Integrations\Insights;

use Goldnead\StatamicInsights\Contracts\HasBreakdowns;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How many appointments fall into the period.
 *
 * **On `scheduled_at`, which is when the appointment happens — not when it was
 * booked.** Both are real questions and this one answers the calendar's: how
 * full was that fortnight. "How many bookings came in that fortnight" is the
 * other question, it counts on `created_at`, and an appointment booked in June
 * for September belongs to September here and to June there. A metric that
 * never had to choose would pick one of them silently, so this one says which.
 *
 * Every status counts, cancelled ones included: they were appointments in that
 * week, and the `status` split below is what tells a reader how many of them
 * survived. Filtering them out here would make this figure disagree with its
 * own split and with the cancellation rate that divides by it.
 *
 * A booking with no date at all — `requested`, asked for but not yet agreed —
 * has a null `scheduled_at` and falls outside every window: SQL comparisons
 * against null are never true, so it is left out rather than counted at the
 * edge. That is correct and worth knowing: this figure is about dates, and that
 * row has none yet.
 */
class Scheduled extends BookingMetric implements HasBreakdowns
{
    protected function timestamp(): string
    {
        return 'scheduled_at';
    }

    public function handle(): string
    {
        return 'booking.scheduled';
    }

    public function label(): string
    {
        return __('statamic-booking::messages.metric_scheduled');
    }

    public function description(): ?string
    {
        return __('statamic-booking::messages.metric_scheduled_description');
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

    public function breakdowns(): array
    {
        return [
            'status' => __('statamic-booking::messages.metric_breakdown_status'),
            'endpoint' => __('statamic-booking::messages.metric_breakdown_endpoint'),
        ];
    }

    public function breakdown(MetricQuery $query, string $dimension, int $limit = 20): array
    {
        if (! $this->available() || ! array_key_exists($dimension, $this->breakdowns())) {
            return [];
        }

        return $this->labelled(
            $this->splitByColumn($this->inPeriod($query), $query, $dimension, 'count(*)', $limit),
            $dimension,
        );
    }
}
