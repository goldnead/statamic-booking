<?php

namespace Goldnead\StatamicBooking\Tests\Feature;

use Goldnead\StatamicBooking\Models\Booking;
use Goldnead\StatamicBooking\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\Test;

class PruneTest extends TestCase
{
    protected function booking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'endpoint' => 'beratung',
            'external_id' => 'cal-'.uniqid(),
            'status' => Booking::STATUS_BOOKED,
            'scheduled_at' => now()->addDay(),
            'name' => 'Maria Beispiel',
            'email' => 'maria@example.com',
        ], $overrides));
    }

    #[Test]
    public function it_deletes_only_what_is_past_the_retention(): void
    {
        config(['statamic-booking.keep_days' => 30]);

        $this->booking(['scheduled_at' => now()->subDays(40), 'external_id' => 'alt']);
        $this->booking(['scheduled_at' => now()->subDays(10), 'external_id' => 'neu']);

        Artisan::call('statamic:booking:prune');

        $this->assertSame(['neu'], Booking::pluck('external_id')->all());
    }

    #[Test]
    public function a_booking_without_a_time_is_not_kept_for_ever(): void
    {
        config(['statamic-booking.keep_days' => 30]);

        // The recorder deliberately keeps bookings whose time the provider sent
        // unparseably, and those rows carry a name and an address.
        // `WHERE scheduled_at < x` skips NULL, so before this they outlived
        // every retention setting — against the promise the command exists for.
        $alt = $this->booking(['scheduled_at' => null, 'external_id' => 'ohne-zeit-alt']);
        $alt->forceFill(['created_at' => now()->subDays(90)])->save();

        $this->booking(['scheduled_at' => null, 'external_id' => 'ohne-zeit-neu']);

        Artisan::call('statamic:booking:prune');

        $this->assertSame(['ohne-zeit-neu'], Booking::pluck('external_id')->all());
    }

    #[Test]
    public function without_a_retention_it_deletes_nothing_and_says_so(): void
    {
        config(['statamic-booking.keep_days' => null]);

        $this->booking(['scheduled_at' => now()->subYears(9)]);

        Artisan::call('statamic:booking:prune');

        $this->assertSame(1, Booking::count());
        $this->assertStringContainsString('nothing is ever deleted', Artisan::output());
    }
}
