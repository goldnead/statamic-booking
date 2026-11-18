<?php

namespace Goldnead\StatamicBooking\Tests\Feature;

use Goldnead\StatamicBooking\Events\BookingCancelled;
use Goldnead\StatamicBooking\Events\BookingMade;
use Goldnead\StatamicBooking\Events\BookingRequested;
use Goldnead\StatamicBooking\Events\BookingRescheduled;
use Goldnead\StatamicBooking\Models\Booking;
use Goldnead\StatamicBooking\Tests\TestCase;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class EventsAfterCommitTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-booking.endpoints', [
            'beratung' => ['secret' => 'geheim'],
        ]);
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function events(): array
    {
        return [
            'made' => [BookingMade::class],
            'requested' => [BookingRequested::class],
            'rescheduled' => [BookingRescheduled::class],
            'cancelled' => [BookingCancelled::class],
        ];
    }

    #[Test]
    #[DataProvider('events')]
    public function every_booking_event_waits_for_the_commit(string $event): void
    {
        $this->assertTrue(is_subclass_of($event, ShouldDispatchAfterCommit::class));
    }

    #[Test]
    public function a_rolled_back_transaction_fires_no_event(): void
    {
        $heard = 0;
        Event::listen(BookingMade::class, function () use (&$heard) {
            $heard++;
        });

        try {
            DB::transaction(function () {
                BookingMade::dispatch(new Booking, []);

                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame(0, $heard);
    }

    #[Test]
    public function the_event_arrives_once_and_after_the_commit(): void
    {
        $heard = [];
        Event::listen(BookingMade::class, function (BookingMade $event) use (&$heard) {
            $heard[] = [
                'level' => DB::transactionLevel(),
                'rows' => Booking::count(),
            ];
        });

        $this->deliver($this->created())->assertOk();

        $this->assertCount(1, $heard);
        $this->assertSame(0, $heard[0]['level']);
        $this->assertSame(1, $heard[0]['rows']);
    }

    #[Test]
    public function a_throwing_listener_leaves_the_row_standing(): void
    {
        Event::listen(BookingMade::class, function () {
            throw new \RuntimeException('listener failed');
        });

        $this->deliver($this->created());

        $this->assertSame(1, Booking::count());
    }
}
