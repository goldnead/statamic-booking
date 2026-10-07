<?php

namespace Goldnead\StatamicBooking\Support;

use Goldnead\StatamicBooking\Events\BookingCancelled;
use Goldnead\StatamicBooking\Events\BookingMade;
use Goldnead\StatamicBooking\Events\BookingRequested;
use Goldnead\StatamicBooking\Events\BookingRescheduled;
use Goldnead\StatamicBooking\Models\Booking;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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

        /*
         * One transaction around the row AND its listeners.
         *
         * Events fire once per real change, so a redelivery finds the row and
         * fires nothing. Without the transaction a listener that threw left the
         * row behind: the provider got a 500, retried, found the row, and the
         * consequence (a credit held, a mail sent) was lost for good while the
         * booking looked recorded. Rolled back, the retry is a first delivery
         * again and the listener gets its second chance.
         */
        return DB::transaction(fn () => match ($trigger) {
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
            'BOOKING_CANCELLED', 'BOOKING_REJECTED' => $this->cancel($endpoint, $externalId, $event, $trigger),

            default => null,
        });
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
            BookingMade::dispatch($booking, $event);

            return $booking;
        }

        /*
         * The organiser accepted a request. Cal.com sends CREATED with the uid
         * the REQUESTED carried, so the row is already there — and until 1.6
         * that was read as a redelivery: the row stayed on `requested`, never
         * showed as upcoming, and nobody heard that the appointment now exists.
         * Promoted under a lock so two deliveries of the acceptance cannot both
         * fire.
         */
        if ($booking->status === Booking::STATUS_REQUESTED) {
            $locked = Booking::query()->whereKey($booking->getKey())->lockForUpdate()->first();

            if ($locked && $locked->status === Booking::STATUS_REQUESTED && ! $locked->isCancelled()) {
                $locked->fill($attributes)->save();

                BookingMade::dispatch($locked, $event);

                return $locked;
            }
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

        // Its own event, not BookingMade. Nothing has been agreed yet, and
        // telling listeners "a booking was made" would have them send a
        // confirmation for an appointment the organiser may still decline.
        if ($booking->wasRecentlyCreated) {
            BookingRequested::dispatch($booking, $event);
        }

        return $booking;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function reschedule(string $endpoint, string $externalId, array $event): Booking
    {
        $existing = Booking::where('endpoint', $endpoint)->where('external_id', $externalId)->first();
        $previousExternalId = null;

        /*
         * Cal.com does not move a booking, it replaces it: the reschedule
         * arrives with a NEW uid, and the old one in `rescheduleUid`. Looked up
         * by the new uid alone, every reschedule was a booking nobody had heard
         * of — a second row, a BookingMade, and the original still upcoming.
         * The original row now follows its appointment to the new uid.
         */
        $previous = Arr::get($event, 'rescheduleUid');

        if (! $existing && is_string($previous) && $previous !== '' && $previous !== $externalId) {
            $existing = Booking::where('endpoint', $endpoint)->where('external_id', $previous)->lockForUpdate()->first();
            $previousExternalId = $existing ? $previous : null;
        }

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
                BookingMade::dispatch($booking, $event);
            }

            return $booking;
        }

        $existing->fill($this->attributes($event) + [
            'external_id' => $externalId,
            'status' => Booking::STATUS_RESCHEDULED,
            'cancelled_at' => null,
        ]);

        // A redelivered reschedule changes nothing and says nothing. Only the
        // first delivery moves the row; a listener told twice would move its
        // own record twice.
        if (! $existing->isDirty()) {
            return $existing;
        }

        $existing->save();

        BookingRescheduled::dispatch($existing, $event, $previousExternalId);

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $event
     */
    protected function cancel(string $endpoint, string $externalId, array $event, string $trigger = 'BOOKING_CANCELLED'): ?Booking
    {
        $status = $trigger === 'BOOKING_REJECTED' ? Booking::STATUS_REJECTED : Booking::STATUS_CANCELLED;

        $booking = Booking::where('endpoint', $endpoint)->where('external_id', $externalId)->lockForUpdate()->first();

        if (! $booking) {
            /*
             * A cancellation for a booking this addon never saw. Dropping it
             * (as before 1.6) lost two things: a site that knew the booking
             * from elsewhere (an import, the system before this one) never
             * heard it was called off; and when the cancellation overtook its
             * own CREATED, the late CREATED then recorded an appointment that
             * no longer exists. Recorded as cancelled, the late CREATED finds
             * the row and does nothing.
             */
            $booking = Booking::createOrFirst(
                ['endpoint' => $endpoint, 'external_id' => $externalId],
                $this->attributes($event) + ['status' => $status, 'cancelled_at' => now()],
            );

            if ($booking->wasRecentlyCreated) {
                BookingCancelled::dispatch($booking, $event);
            }

            return $booking;
        }

        if ($booking->isCancelled()) {
            return $booking;
        }

        $booking->forceFill([
            'status' => $status,
            'cancelled_at' => now(),
        ])->save();

        BookingCancelled::dispatch($booking, $event);

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
