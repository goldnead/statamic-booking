<?php

namespace Goldnead\StatamicBooking\Tags;

use Goldnead\StatamicBooking\Models\Booking;
use Illuminate\Support\Collection;
use Statamic\Tags\Tags;

class Bookings extends Tags
{
    protected static $handle = 'bookings';

    /**
     * {{ bookings endpoint="beratung" limit="5" }} … {{ /bookings }}
     *
     * Upcoming, soonest first, cancellations left out. That is the only
     * question a public page ever asks; anything else is a report, and a report
     * belongs behind a login rather than in a template tag.
     *
     * **Nobody's name or address is exposed, and that includes the title.**
     * Cal.com's default title is "30 Min Meeting between {organiser} and
     * {attendee}" — a field that looks harmless and carries the booker. The tag
     * yields only what a public page can safely print; whoever needs the rest
     * has the model.
     *
     * Like every Statamic tag pair, an empty result still parses the block once
     * with `no_results` set — so a template that prints a row unconditionally
     * prints one empty row. The README shows the `{{ if no_results }} … {{ else }}`
     * form for that reason.
     *
     * @return array<int, array<string, mixed>>
     */
    public function index(): array
    {
        $query = Booking::query()->upcoming();

        if ($endpoint = $this->params->get('endpoint')) {
            $query->forEndpoint((string) $endpoint);
        }

        /** @var Collection<int, Booking> $bookings */
        $bookings = $query->limit((int) $this->params->get('limit', 10))->get();

        return $bookings
            ->map(fn (Booking $booking): array => [
                'id' => $booking->id,
                'endpoint' => $booking->endpoint,
                'scheduled_at' => $booking->scheduled_at,
                'timezone' => $booking->timezone,
                'duration_minutes' => $booking->duration_minutes,
                'status' => $booking->status,
            ])
            ->all();
    }

    /**
     * {{ bookings:count endpoint="beratung" }}
     *
     * How many upcoming bookings there are. Enough for "fully booked this
     * week" without handing a template the people.
     */
    public function count(): int
    {
        $query = Booking::query()->upcoming();

        if ($endpoint = $this->params->get('endpoint')) {
            $query->forEndpoint((string) $endpoint);
        }

        return $query->count();
    }
}
