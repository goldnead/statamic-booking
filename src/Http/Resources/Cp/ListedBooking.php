<?php

namespace Goldnead\StatamicBooking\Http\Resources\Cp;

use Goldnead\StatamicBooking\Models\Booking;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row.
 *
 * @mixin Booking
 *
 * Names and addresses appear here and nowhere else in this package. The Antlers
 * tags, which anyone can drop into a public template, carry none of it.
 */
class ListedBooking extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'endpoint' => $this->endpoint,
            'scheduled_at' => $this->scheduled_at?->toIso8601String(),
            'duration_minutes' => $this->duration_minutes,
            'status' => $this->status,
            'status_label' => $this->statusLabel(),
            'name' => $this->name,
            'email' => $this->email,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }

    /**
     * A status the package does not know shows its raw value rather than a
     * missing translation key. `statamic-booking::messages.status_xyz` in the
     * interface tells the reader nothing and looks like a broken install.
     */
    protected function statusLabel(): string
    {
        $key = 'statamic-booking::messages.status_'.$this->status;
        $label = __($key);

        return $label === $key ? (string) $this->status : $label;
    }
}
