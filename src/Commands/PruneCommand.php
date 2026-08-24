<?php

namespace Goldnead\StatamicBooking\Commands;

use Goldnead\StatamicBooking\Models\Booking;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

class PruneCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'statamic:booking:prune';

    protected $description = 'Delete bookings whose appointment is older than the configured retention';

    public function handle(): int
    {
        $days = config('statamic-booking.keep_days');

        if (! is_numeric($days)) {
            $this->components->warn('keep_days is not set, so nothing is ever deleted. A booking carries a name and an address; decide how long you keep them.');

            return self::SUCCESS;
        }

        $cutoff = now()->subDays((int) $days);

        // A NULL appointment time is not "never old". The recorder deliberately
        // keeps bookings whose time the provider sent unparseably, and those
        // rows carry a name and an address — `WHERE scheduled_at < x` skips
        // NULL, so without this they would be kept forever, against the very
        // promise this command exists to keep.
        $deleted = Booking::query()
            ->where(function ($query) use ($cutoff): void {
                $query->where('scheduled_at', '<', $cutoff)
                    ->orWhere(function ($query) use ($cutoff): void {
                        $query->whereNull('scheduled_at')->where('created_at', '<', $cutoff);
                    });
            })
            ->delete();

        $this->components->info($deleted === 0
            ? 'Nothing older than '.(int) $days.' days.'
            : $deleted.' booking(s) older than '.(int) $days.' days deleted.');

        return self::SUCCESS;
    }
}
