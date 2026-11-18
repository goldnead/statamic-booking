<?php

namespace Goldnead\StatamicBooking\Tests\Feature;

use Goldnead\StatamicBooking\Models\Booking;
use Goldnead\StatamicBooking\Tests\TestCase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

/**
 * Cal.com sends no timestamp, so a signature alone never expires. Without that
 * header the addon says so once and drops exact replays.
 */
class ReplayDedupeTest extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('statamic-booking.endpoints', [
            'beratung' => ['secret' => 'geheim'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * @return array<string, mixed>
     */
    protected function stamped(string $updatedAt = '2026-09-01T09:00:00Z'): array
    {
        return $this->created(['payload' => ['updatedAt' => $updatedAt]]);
    }

    #[Test]
    public function missing_replay_protection_is_noticed_once(): void
    {
        Log::spy();

        $this->deliver($this->stamped())->assertOk();
        $this->deliver($this->stamped('2026-09-01T09:05:00Z'))->assertOk();

        Log::shouldHaveReceived('notice')->once()->withArgs(fn ($message) => str_contains($message, 'replay'));
    }

    #[Test]
    public function an_exact_replay_is_dropped(): void
    {
        $this->deliver($this->stamped())->assertOk()->assertJson(['recorded' => true]);

        // Row removed behind the addon's back: only a dedupe, not row
        // idempotency, can keep the replay from writing it again.
        Booking::query()->delete();

        $this->deliver($this->stamped())->assertOk()->assertJson(['recorded' => false, 'duplicate' => true]);

        $this->assertSame(0, Booking::count());
    }

    #[Test]
    public function a_changed_update_time_is_a_new_delivery(): void
    {
        $this->deliver($this->stamped())->assertOk();
        Booking::query()->delete();

        $this->deliver($this->stamped('2026-09-01T09:05:00Z'))->assertOk()->assertJson(['recorded' => true]);

        $this->assertSame(1, Booking::count());
    }

    #[Test]
    public function the_same_uid_with_another_trigger_is_not_a_duplicate(): void
    {
        $this->deliver($this->stamped())->assertOk();
        $this->deliver(array_replace($this->stamped(), ['triggerEvent' => 'BOOKING_CANCELLED']))->assertOk();

        $this->assertTrue(Booking::first()->isCancelled());
    }

    #[Test]
    public function a_delivery_without_an_update_time_is_never_deduped(): void
    {
        $this->deliver($this->created())->assertOk();
        Booking::query()->delete();

        $this->deliver($this->created())->assertOk()->assertJson(['recorded' => true]);
    }

    #[Test]
    public function a_failed_delivery_is_not_remembered(): void
    {
        // Refused signature: nothing may be marked as seen, or the provider's
        // genuine retry would be swallowed.
        $this->deliver($this->stamped(), signature: str_repeat('a', 64))->assertStatus(401);

        $this->deliver($this->stamped())->assertOk()->assertJson(['recorded' => true]);
    }
}
