<?php

namespace Goldnead\StatamicBooking\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * One booking, as the provider reported it.
 *
 * @property string $endpoint
 * @property string $external_id
 * @property string $status
 * @property Carbon|null $scheduled_at
 * @property Carbon|null $cancelled_at
 * @property Carbon|null $created_at
 * @property string|null $timezone
 * @property int|null $duration_minutes
 * @property string|null $name
 * @property string|null $email
 * @property string|null $meeting_url
 * @property array<string, mixed>|null $meta
 */
class Booking extends Model
{
    public const STATUS_BOOKED = 'booked';

    public const STATUS_RESCHEDULED = 'rescheduled';

    public const STATUS_CANCELLED = 'cancelled';

    /** Declined by the organiser. A different fact from "the visitor cancelled". */
    public const STATUS_REJECTED = 'rejected';

    /** Asked for, not yet agreed. Deliberately not "upcoming". */
    public const STATUS_REQUESTED = 'requested';

    protected $guarded = [];

    /**
     * Every status this package writes.
     *
     * One list, so the filter, the screen and the model cannot drift apart.
     *
     * @return list<string>
     */
    public static function statuses(): array
    {
        return [
            self::STATUS_BOOKED,
            self::STATUS_RESCHEDULED,
            self::STATUS_REQUESTED,
            self::STATUS_CANCELLED,
            self::STATUS_REJECTED,
        ];
    }

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'meta' => 'array',
            'duration_minutes' => 'integer',
        ];
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeUpcoming(Builder $query): Builder
    {
        // `requested` is excluded on purpose: it has been asked for, not agreed.
        // Showing it as upcoming is how a calendar tells its owner a lie.
        return $query->whereNull('cancelled_at')
            ->where('status', '!=', self::STATUS_REQUESTED)
            ->where('scheduled_at', '>=', now())
            ->orderBy('scheduled_at');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeForEndpoint(Builder $query, string $endpoint): Builder
    {
        return $query->where('endpoint', $endpoint);
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }
}
