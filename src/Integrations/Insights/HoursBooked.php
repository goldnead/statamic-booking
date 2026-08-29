<?php

namespace Goldnead\StatamicBooking\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Database\Query\Builder;

/**
 * How much time the period's appointments take up.
 *
 * On `scheduled_at`, like {@see Scheduled}, and for the same reason: this is
 * the calendar's question. An hour booked in June for September fills a
 * September afternoon, not a June one.
 *
 * **Cancelled appointments do not count.** An hour that was called off is an
 * hour nobody spent, and a diary figure that included it would answer a
 * question nobody asks — this is the number a person looks at to see how full a
 * month was. It is the one place in this directory where the window is narrowed
 * for everything at once, which is why the condition sits in `inPeriod()`
 * rather than in the figure: the series and any future split inherit it and
 * cannot forget it.
 *
 * The unit is whole seconds, as the contract requires; the screen turns them
 * into something readable. `duration_minutes` is nullable — a provider that
 * reports no length contributes nothing rather than a guess.
 */
class HoursBooked extends BookingMetric
{
    protected function timestamp(): string
    {
        return 'scheduled_at';
    }

    public function handle(): string
    {
        return 'booking.hours_booked';
    }

    public function label(): string
    {
        return __('statamic-booking::messages.metric_hours_booked');
    }

    public function description(): ?string
    {
        return __('statamic-booking::messages.metric_hours_booked_description');
    }

    public function unit(): string
    {
        return Unit::DURATION;
    }

    /** The appointments that are actually happening. */
    protected function inPeriod(MetricQuery $query, ?string $column = null): Builder
    {
        return parent::inPeriod($query, $column)->whereNull('cancelled_at');
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        return ((int) $this->inPeriod($query)->sum('duration_minutes')) * 60;
    }

    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        return array_map(
            fn ($measured) => ((int) $measured) * 60,
            $this->bucketed($this->inPeriod($query), $query, 'sum(duration_minutes)'),
        );
    }
}
