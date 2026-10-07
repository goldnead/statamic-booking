<?php

namespace Goldnead\StatamicBooking\Tests\Feature;

use Goldnead\StatamicBooking\Models\Booking;
use Goldnead\StatamicBooking\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * A site that already has a `bookings` table of its own.
 *
 * `bookings` is the obvious name for anything appointment-shaped, so a site
 * that grew its own before installing this addon is the normal case, not an
 * exotic one. Without a way to choose, `php artisan migrate` fails on install
 * and the addon cannot be used there at all.
 */
class TableNameTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-booking.table', 'calcom_bookings');
        $app['config']->set('statamic-booking.endpoints', [
            'beratung' => ['secret' => 'geheim'],
        ]);
    }

    #[Test]
    public function the_configured_table_is_created_and_written(): void
    {
        $this->assertTrue(Schema::hasTable('calcom_bookings'));
        $this->assertFalse(Schema::hasTable('bookings'));

        $this->deliver($this->created())->assertOk();

        $this->assertSame(1, DB::table('calcom_bookings')->count());
        $this->assertSame('calcom_bookings', (new Booking)->getTable());
        $this->assertSame('calcom_bookings', Booking::tableName());
    }
}
