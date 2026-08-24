<?php

namespace Goldnead\StatamicBooking\Support;

use Goldnead\StatamicBooking\Events\BookingCancelled;
use Goldnead\StatamicBooking\Events\BookingMade;
use Goldnead\StatamicBooking\Events\BookingRescheduled;
use Goldnead\StatamicBooking\Models\Booking;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;

/**
 * Turns one provider payload into one row, and says what happened.
 *
 * The mapping is Cal.com's shape. It lives here rather than in the controller
 * so that a second provider is a second mapper and not a second endpoint.
 */
class BookingRecorder
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function record(string $endpoint, array $payload): ?Booking
    {
        $trigger = (string) Arr::get($payload, 'triggerEvent', '');
        $event = (array) Arr::get($payload, 'payload', []);
        $externalId = Arr::get($event, 'uid');

        if (! is_string($externalId) || $externalId === '') {
            // Without the provider's own id there is nothing to be idempotent
            // about. Recording it anyway would mean a redelivery creates a
            // second booking, which is worse than dropping one malformed call.
            return null;
        }

        return match ($trigger) {
            'BOOKING_CREATED' => $this->create($endpoint, $externalId, $event),

            // A booking that needs confirming arrives as REQUESTED first, and
            // is not an appointment yet. Recorded so the site can see it, but
            // kept out of `upcoming()` — showing an unconfirmed request as a
            // booked slot is how a calendar tells its owner a lie.
            'BOOKING_REQUESTED' => $this->request($endpoint, $externalId, $event),

            'BOOKING_RESCHEDULED' => $this->reschedule($endpoint, $externalId, $event),

            // REJECTED is the other end of REQUESTED and was missing entirely:
            // ignoring it left the row on `booked` forever, so a request the
            // organiser declined stayed in `{{ bookings }}` as an appointment
            // that will never happen.
            'BOOKING_CANCELLED', 'BOOKING_REJECTED' => $this->cancel($endpoint, $externalId, $trigger),

            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function create(string $endpoint, string $externalId, array $event): Booking
    {
        $attributes = $this->attributes($event) + [
            'status' => Booking::STATUS_BOOKED,
            'cancelled_at' => null,
        ];

        // firstOrCreate against the unique key, not updateOrCreate: a
        // redelivered "created" must not overwrite a booking the visitor has
        // since rescheduled. The database holds the invariant either way.
        $booking = Booking::firstOrCreate(
            ['endpoint' => $endpoint, 'external_id' => $externalId],
            $attributes,
        );

        if ($booking->wasRecentlyCreated) {
            BookingMade::dispatch($booking);
        }

        return $booking;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function request(string $endpoint, string $externalId, array $event): Booking
    {
        $booking = Booking::firstOrCreate(
            ['endpoint' => $endpoint, 'external_id' => $externalId],
            $this->attributes($event) + ['status' => Booking::STATUS_REQUESTED, 'cancelled_at' => null],
        );

        // No event. Nothing has been agreed yet, and telling listeners "a
        // booking was made" would have them send a confirmation for an
        // appointment the organiser may still decline.
        return $booking;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function reschedule(string $endpoint, string $externalId, array $event): Booking
    {
        $existing = Booking::where('endpoint', $endpoint)->where('external_id', $externalId)->first();

        // A reschedule that arrives after a cancellation must not resurrect it.
        // Providers retry on any non-2xx and the order is not guaranteed, so
        // this sequence is ordinary rather than exotic — and reviving the row
        // would put a cancelled appointment back into `{{ bookings }}` and fire
        // BookingRescheduled at every listener.
        if ($existing && $existing->isCancelled()) {
            return $existing;
        }

        if (! $existing) {
            // The create webhook can be lost; the visitor still has an
            // appointment. createOrFirst, not save(), so two simultaneous
            // deliveries cannot both insert.
            $booking = Booking::createOrFirst(
                ['endpoint' => $endpoint, 'external_id' => $externalId],
                $this->attributes($event) + ['status' => Booking::STATUS_BOOKED, 'cancelled_at' => null],
            );

            if ($booking->wasRecentlyCreated) {
                BookingMade::dispatch($booking);
            }

            return $booking;
        }

        $existing->fill($this->attributes($event) + [
            'status' => Booking::STATUS_RESCHEDULED,
            'cancelled_at' => null,
        ])->save();

        BookingRescheduled::dispatch($existing);

        return $existing;
    }

    protected function cancel(string $endpoint, string $externalId, string $trigger = 'BOOKING_CANCELLED'): ?Booking
    {
        $booking = Booking::where('endpoint', $endpoint)->where('external_id', $externalId)->first();

        if (! $booking || $booking->isCancelled()) {
            return $booking;
        }

        $booking->forceFill([
            'status' => $trigger === 'BOOKING_REJECTED' ? Booking::STATUS_REJECTED : Booking::STATUS_CANCELLED,
            'cancelled_at' => now(),
        ])->save();

        BookingCancelled::dispatch($booking);

        return $booking;
    }

    /**
     * @param  array<string, mixed>  $event
     * @return array<string, mixed>
     */
    protected function attributes(array $event): array
    {
        $attendee = (array) Arr::get($event, 'attendees.0', []);
        $start = $this->time(Arr::get($event, 'startTime'));
        $end = $this->time(Arr::get($event, 'endTime'));

        return [
            'scheduled_at' => $start,
            'timezone' => Arr::get($attendee, 'timeZone') ?: null,
            'duration_minutes' => $start && $end ? max(0, $start->diffInMinutes($end)) : null,
            'name' => Arr::get($attendee, 'name') ?: null,
            'email' => Arr::get($attendee, 'email') ?: null,
            'meeting_url' => Arr::get($event, 'metadata.videoCallUrl') ?: null,
            'meta' => [
                /*
                 * Cal.com's default title is "30 Min Meeting between {organiser}
                 * and {attendee}" — it carries the booker's name. Kept for the
                 * site, which already has the name in its own column, but the
                 * Antlers tags do not expose it: one careless template would
                 * otherwise publish the people who booked.
                 */
                'title' => Arr::get($event, 'title'),
                'event_type_id' => Arr::get($event, 'eventTypeId'),
            ],
        ];
    }

    protected function time(mixed $value): ?Carbon
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            // A provider that sends an unparseable time still made a booking;
            // losing the row over the clock would be the worse trade.
            return null;
        }
    }
}
