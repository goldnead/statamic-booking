<?php

namespace Goldnead\StatamicBooking\Integrations\Insights;

use Goldnead\StatamicBooking\Models\Booking;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\TableMetric;
use Illuminate\Database\Query\Builder;

/**
 * What every booking figure has in common.
 *
 * The coupling to the analytics addon is one-directional and optional: this
 * package names the sibling's contract, the sibling never names this table.
 * That is the rule the ecosystem plan sets out, and the base class the metrics
 * below extend is the sibling's own {@see TableMetric} — windowing a period,
 * bucketing a timestamp in three SQL dialects and splitting by a column without
 * dropping the null rows are written once, upstream, rather than five times
 * across the family.
 *
 * **Loading this file means Insights is installed.** The registration in the
 * ServiceProvider is guarded by `class_exists` on the sibling's facade, so PHP
 * never reaches this directory when the sibling is absent — which is why
 * `goldnead/statamic-insights` is a `suggest` and not a `require`.
 *
 * Two decisions shape every number in this directory:
 *
 * 1. **A booking has two dates and they answer different questions.**
 *    `scheduled_at` is when the appointment happens, `created_at` is when
 *    somebody booked it, and `cancelled_at` is when it was called off. Each
 *    metric names the one it counts on and says why, because a figure that
 *    never had to choose picks the wrong one silently.
 * 2. **Missing is missing.** A booking with no endpoint is a row keyed `null`,
 *    never a dropped one. A split that quietly excludes rows disagrees with the
 *    total it splits, and nothing on the screen says why.
 *
 * Read with SQL aggregates rather than through the model: an aggregate is a
 * read, not a call, and hydrating ten thousand bookings to add up a column
 * would be slower and no more correct. The status *words* are the exception —
 * a status handle only becomes a word through the translations the Control
 * Panel already uses.
 */
abstract class BookingMetric extends TableMetric
{
    protected function table(): string
    {
        return 'bookings';
    }

    public function group(): string
    {
        return __('statamic-booking::messages.metric_group');
    }

    /**
     * The rows inside the window — and only those that have the date at all.
     *
     * The `whereNotNull` is the whole of this override and it is not
     * decoration. Upstream, windowing is two `when()` clauses on the period's
     * bounds, so a row whose timestamp is null is excluded by the comparisons
     * — a null is never `>=` anything. **On an open-ended period there are no
     * comparisons.** `Period::fromPreset('all')` carries no bounds, both
     * clauses fall away, and every row in the table comes back regardless of
     * whether the thing being counted ever happened.
     *
     * Every column here is nullable, so that is not a hypothetical: over "all
     * time" `booking.cancelled` would have counted every booking ever made as a
     * cancellation, and `booking.scheduled` would have counted the `requested`
     * rows that have no date yet — silently, and only on the one preset nobody
     * tests by hand. It would also have put a `null` bucket into the series,
     * because truncating a null timestamp produces a null key.
     *
     * So the condition is stated rather than inherited from the shape of the
     * window: counting something *on* a date means the date exists.
     */
    protected function inPeriod(MetricQuery $query, ?string $column = null): Builder
    {
        $column ??= $this->timestamp();

        return parent::inPeriod($query, $column)->whereNotNull($column);
    }

    /**
     * The rows of a split, with a status printed the way the screen prints it.
     *
     * `booked` is a handle, "Gebucht" is what a person reads, and the words
     * already exist — the bookings listing uses the same keys. A split that
     * showed the raw handles would be the only place in the Control Panel that
     * does. An unknown status keeps its handle rather than vanishing: the
     * column is a free string, and a provider that starts sending a sixth one
     * must show up in the report rather than be swallowed by it.
     *
     * @param  array<int, array{key: string|null, value: int|float}>  $rows
     * @return array<int, array{key: string|null, label: string, value: int|float}>
     */
    protected function labelled(array $rows, string $dimension): array
    {
        $labelled = parent::labelled($rows, $dimension);

        if ($dimension !== 'status') {
            return $labelled;
        }

        return array_map(function (array $row): array {
            // The null row keeps the words `missingLabel()` gave it.
            if ($row['key'] !== null) {
                $row['label'] = $this->statusLabel($row['key']);
            }

            return $row;
        }, $labelled);
    }

    protected function statusLabel(string $status): string
    {
        return in_array($status, Booking::statuses(), true)
            ? __('statamic-booking::messages.status_'.$status)
            : $status;
    }

    /**
     * What to call the rows that have no value for this split.
     *
     * Its own key per dimension, because "no endpoint" and "no status" read
     * differently and a shared dash tells a reader nothing.
     */
    protected function missingLabel(string $dimension): string
    {
        return __('statamic-booking::messages.metric_no_'.$dimension);
    }
}
