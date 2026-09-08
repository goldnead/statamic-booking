<?php

namespace Goldnead\StatamicBooking\Tests\Feature;

use Goldnead\StatamicBooking\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\User;

/**
 * The bookings screen with the addon installed and its migrations not run.
 *
 * That combination is ordinary — composer pulls the package in, the utility
 * registers, the nav item shows up, and `bookings` still does not exist. The
 * screen asked the table a question first thing and answered HTTP 500. These
 * tests reproduce that database and hold the page to an empty state plus a
 * line in the log.
 */
class SetupGuardTest extends TestCase
{
    private function admin()
    {
        return tap(User::make()->email('setup@example.test')->makeSuper())->save();
    }

    private function dropAddonTables(): void
    {
        Schema::dropIfExists('bookings');
    }

    #[Test]
    public function the_index_answers_200_when_its_table_is_missing(): void
    {
        $this->dropAddonTables();

        $this->actingAs($this->admin())
            ->get('/cp/utilities/bookings')
            ->assertOk();
    }

    #[Test]
    public function the_index_renders_the_setup_screen_and_names_the_missing_table(): void
    {
        $this->dropAddonTables();

        $response = $this->actingAs($this->admin())
            ->get('/cp/utilities/bookings')
            ->assertOk();

        $page = $response->viewData('page');

        $this->assertSame('statamic-booking::SetupRequired', $page['component']);
        $this->assertContains('bookings', $page['props']['tables']);
        $this->assertNotEmpty($page['props']['heading']);
        $this->assertNotEmpty($page['props']['description']);
    }

    /**
     * The point of the guard is a readable page, not a quiet one. If this test
     * ever goes red the addon has traded a visible 500 for a silent nothing.
     */
    #[Test]
    public function the_reason_reaches_the_log(): void
    {
        $this->dropAddonTables();

        Log::spy();

        $this->actingAs($this->admin())
            ->get('/cp/utilities/bookings')
            ->assertOk();

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'statamic-booking')
                && str_contains($message, 'php artisan migrate'))
            ->once();
    }

    /**
     * The Listing fetches its rows from the same action over JSON. Guarding
     * only the Inertia render would leave that request answering 500 — with
     * the screen looking fine and the rows never arriving.
     */
    #[Test]
    public function the_json_listing_is_guarded_too(): void
    {
        $this->dropAddonTables();

        $this->actingAs($this->admin())
            ->getJson('/cp/utilities/bookings')
            ->assertOk();
    }

    #[Test]
    public function a_migrated_install_still_renders_the_listing(): void
    {
        $response = $this->actingAs($this->admin())
            ->get('/cp/utilities/bookings')
            ->assertOk();

        $this->assertSame('statamic-booking::Bookings/Index', $response->viewData('page')['component']);
    }
}
