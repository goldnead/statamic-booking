<?php

namespace Goldnead\StatamicBooking\Integrations\Insights;

use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Unit;

/**
 * How many of the period's appointments fell through.
 *
 * **A cohort on `scheduled_at`, not a division of the two tiles beside it.**
 * The denominator is exactly {@see Scheduled} — the appointments that were
 * meant to happen in this window — and the numerator is the ones among *them*
 * that carry a `cancelled_at`, whenever that cancellation was made. So the
 * question is "how many of that fortnight's dates fell through", which is what
 * a person means, and the answer can never exceed 100 %.
 *
 * Dividing {@see Cancelled} by {@see Scheduled} instead would mix two axes: a
 * week in which somebody cleared out thirty appointments of the coming autumn
 * would report a cancellation rate of several hundred per cent against the four
 * dates that week actually held. The neighbouring tile therefore counts
 * cancellations on the day they arrived and this one does not use it — two
 * numbers that look like they should divide into each other and deliberately
 * do not.
 *
 * The criterion is `cancelled_at is not null` rather than `status = cancelled`.
 * It covers the visitor calling off and the organiser declining alike — both
 * mean the appointment did not happen — and it is the same column
 * {@see Cancelled} counts on, so the two metrics cannot drift apart in what
 * they consider called off.
 *
 * **Null, not zero per cent.** A window with no appointments in it has no rate:
 * printing 0 % there states that nothing was cancelled, which is a claim about
 * appointments that do not exist. The same rule applies per bucket — a day with
 * no dates is left out of the series rather than drawn as a bar of nothing.
 */
class CancellationRate extends BookingMetric
{
    protected function timestamp(): string
    {
        return 'scheduled_at';
    }

    public function handle(): string
    {
        return 'booking.cancellation_rate';
    }

    public function label(): string
    {
        return __('statamic-booking::messages.metric_cancellation_rate');
    }

    public function description(): ?string
    {
        return __('statamic-booking::messages.metric_cancellation_rate_description');
    }

    public function unit(): string
    {
        return Unit::PERCENT;
    }

    public function value(MetricQuery $query): int|float|null
    {
        if (! $this->available()) {
            return null;
        }

        $appointments = (int) $this->inPeriod($query)->count();

        if ($appointments === 0) {
            return null;
        }

        $lost = (int) $this->inPeriod($query)->whereNotNull('cancelled_at')->count();

        // One decimal. A cancellation rate is read to compare weeks, and
        // "33.3333 %" asserts a precision that three appointments cannot carry.
        return round($lost / $appointments * 100, 1);
    }

    /**
     * A rate per bucket, and only where there is something to divide by.
     *
     * Both halves are bucketed on `scheduled_at`, so a bucket's numerator is
     * drawn from its own denominator and the two cannot disagree about which
     * day an appointment belongs to.
     */
    public function series(MetricQuery $query): array
    {
        if (! $this->available()) {
            return [];
        }

        $appointments = $this->bucketed($this->inPeriod($query), $query, 'count(*)');
        $lost = $this->bucketed($this->inPeriod($query)->whereNotNull('cancelled_at'), $query, 'count(*)');

        $buckets = [];

        foreach ($appointments as $bucket => $held) {
            if ((int) $held > 0) {
                $buckets[$bucket] = round((int) ($lost[$bucket] ?? 0) / (int) $held * 100, 1);
            }
        }

        ksort($buckets);

        return $buckets;
    }
}
