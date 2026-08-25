<?php

namespace Goldnead\StatamicBooking\Tests\Feature;

use Goldnead\StatamicBooking\Models\Booking;
use Goldnead\StatamicBooking\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Role;
use Statamic\Facades\User;

/**
 * The Control Panel screen.
 *
 * Two things are worth a test here and both have bitten this addon already:
 * who may look, and what an Inertia visit gets back.
 */
class CpScreenTest extends TestCase
{
    protected function booking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'endpoint' => 'beratung',
            'external_id' => 'cal-'.uniqid(),
            'status' => Booking::STATUS_BOOKED,
            'scheduled_at' => now()->addDays(3),
            'duration_minutes' => 30,
            'name' => 'Maria Beispiel',
            'email' => 'maria@example.com',
        ], $overrides));
    }

    protected function user()
    {
        return tap(User::make()->email(uniqid().'@example.com')->makeSuper())->save();
    }

    /**
     * Signed in, may open the Control Panel, may not open this screen.
     *
     * The interesting case. A user with no permissions at all never reaches the
     * screen anyway — the CP turns them away at the door, which proves nothing
     * about this route. This one gets through the door.
     *
     * (`makeSuper()` takes no argument. Passing `false` still makes a
     * superuser, which is how the first version of this test proved the
     * opposite of what it claimed.)
     */
    protected function userWithoutPermission()
    {
        $role = tap(Role::make('nur-cp')->addPermission('access cp'))->save();

        return tap(User::make()->email(uniqid().'@example.com')->assignRole($role))->save();
    }

    #[Test]
    public function it_is_closed_to_anyone_not_signed_in(): void
    {
        $this->booking();

        // Bookings carry names and addresses. An open screen would publish them
        // to anyone who guessed the URL.
        $this->get('/cp/utilities/bookings')->assertRedirect();
    }

    #[Test]
    public function a_user_without_the_permission_is_refused(): void
    {
        $this->booking(['name' => 'Maria Beispiel', 'email' => 'maria@example.com']);

        $user = $this->userWithoutPermission();

        // The page itself: Statamic sends an unauthorised CP user back to the
        // dashboard rather than showing them a 403.
        $this->actingAs($user)
            ->get('/cp/utilities/bookings')
            ->assertRedirect(cp_route('index'));

        // The data behind it, which is the part that would actually leak.
        $json = $this->actingAs($user)->getJson('/cp/utilities/bookings');

        $json->assertForbidden();
        $json->assertDontSee('Maria Beispiel');
        $json->assertDontSee('maria@example.com');
    }

    #[Test]
    public function an_inertia_visit_gets_a_page_and_not_a_bare_array(): void
    {
        $this->booking();

        // An Inertia visit asks for JSON too. When the controller answered it
        // with the plain listing array, the Control Panel could not read the
        // response and showed "Something went wrong" — on every load of the
        // screen, with nothing in any log. Found by opening it, not by testing.
        $response = $this->actingAs($this->user())
            ->withHeaders(['X-Inertia' => 'true', 'X-Inertia-Version' => ''])
            ->getJson('/cp/utilities/bookings');

        $response->assertOk();
        $response->assertHeader('x-inertia', 'true');
        $this->assertSame('statamic-booking::Bookings/Index', $response->json('component'));
    }

    #[Test]
    public function the_listing_fetches_its_rows_as_json(): void
    {
        $this->booking(['name' => 'Jonas Weber', 'email' => 'jonas@example.com']);

        $response = $this->actingAs($this->user())
            ->getJson('/cp/utilities/bookings');

        $response->assertOk();
        $this->assertSame('Jonas Weber', $response->json('data.0.name'));
        $this->assertSame(1, $response->json('meta.total'));
    }

    #[Test]
    public function every_listing_response_carries_its_columns(): void
    {
        $this->booking();

        // The Listing component reads `meta.columns` from each response. Left
        // out, it throws inside its own promise and the Control Panel shows a
        // red "Something went wrong" — while the rows, the search and the
        // paging all still work, so nothing looks broken enough to chase.
        // Found by opening the screen; no request had failed.
        $response = $this->actingAs($this->user())->getJson('/cp/utilities/bookings');

        $this->assertIsArray($response->json('meta.columns'));
        $this->assertNotEmpty($response->json('meta.columns'));
        $this->assertSame('scheduled_at', $response->json('meta.columns.0.field'));
    }

    /** Filters travel as base64-encoded JSON, the way the Listing sends them. */
    protected function filter(array $filters): string
    {
        return base64_encode(json_encode($filters));
    }

    #[Test]
    public function it_searches_and_filters(): void
    {
        $this->booking(['name' => 'Maria Beispiel', 'email' => 'maria@example.com']);
        $this->booking(['name' => 'Jonas Weber', 'email' => 'jonas@example.com', 'status' => Booking::STATUS_CANCELLED]);

        $user = $this->user();

        $this->assertSame(1, $this->actingAs($user)->getJson('/cp/utilities/bookings?search=jonas')->json('meta.total'));

        $cancelled = $this->filter(['booking_status' => ['status' => 'cancelled']]);
        $this->assertSame(1, $this->actingAs($user)->getJson('/cp/utilities/bookings?filters='.$cancelled)->json('meta.total'));

        // A status nobody registered filters nothing rather than everything. An
        // empty list would read as "there are no bookings", which is a
        // different and worrying statement.
        $erfunden = $this->filter(['booking_status' => ['status' => 'erfunden']]);
        $this->assertSame(2, $this->actingAs($user)->getJson('/cp/utilities/bookings?filters='.$erfunden)->json('meta.total'));
    }

    #[Test]
    public function it_refuses_to_sort_by_a_column_it_does_not_offer(): void
    {
        // Two rows whose order by `external_id` is the opposite of their order
        // by `scheduled_at`. Asserting only `assertOk()` proved nothing: it
        // would pass just as happily if `sort` went straight into `orderBy`.
        $this->booking(['external_id' => 'aaa', 'scheduled_at' => now()->addDays(9)]);
        $this->booking(['external_id' => 'zzz', 'scheduled_at' => now()->addDay()]);

        $response = $this->actingAs($this->user())
            ->getJson('/cp/utilities/bookings?sort=external_id&order=asc');

        $response->assertOk();

        // Fell back to `scheduled_at`, so the soonest is first — not `aaa`.
        $this->assertSame('zzz', Booking::find($response->json('data.0.id'))->external_id);
    }

    #[Test]
    public function the_screen_writes_nothing(): void
    {
        $booking = $this->booking();
        $user = $this->user();

        // Read-only is a claim about a route, and a route that only registers
        // GET is the proof. Cal.com owns these appointments; a write here would
        // put the site and the calendar out of step.
        $this->actingAs($user)->post('/cp/utilities/bookings')->assertNotFound();
        $this->actingAs($user)->delete('/cp/utilities/bookings/'.$booking->id)->assertNotFound();
        $this->actingAs($user)->patch('/cp/utilities/bookings/'.$booking->id)->assertNotFound();

        $this->actingAs($user)->getJson('/cp/utilities/bookings')->assertOk();

        $this->assertSame(1, Booking::count());
        $this->assertSame(Booking::STATUS_BOOKED, Booking::first()->status);
    }

    #[Test]
    public function a_wildcard_in_the_search_is_not_a_wildcard(): void
    {
        $this->booking(['name' => 'Maria Beispiel']);
        $this->booking(['name' => '50% Rabatt', 'external_id' => 'prozent']);

        $user = $this->user();

        // `%` and `_` are LIKE wildcards. Unescaped, a search for "%" returns
        // everything and reads as a filter that does not work.
        $this->assertSame(1, $this->actingAs($user)->getJson('/cp/utilities/bookings?search=50%25')->json('meta.total'));
        $this->assertSame(0, $this->actingAs($user)->getJson('/cp/utilities/bookings?search=_aria')->json('meta.total'));

        // And no injection either way: the value is bound, never concatenated.
        $this->assertSame(0, $this->actingAs($user)->getJson("/cp/utilities/bookings?search=' OR 1=1 --")->json('meta.total'));
        $this->assertSame(2, Booking::count());
    }

    #[Test]
    public function the_column_choice_is_honoured_and_remembered(): void
    {
        $this->booking();
        $user = $this->user();

        // Before this, the server answered every request with the same fixed
        // set of visible columns. Since the Listing takes its columns from each
        // response, the picker sprang back, "These are now your default
        // columns" appeared, and the preference was written and then ignored
        // for ever.
        $response = $this->actingAs($user)
            ->getJson('/cp/utilities/bookings?columns=status,endpoint');

        $visible = collect($response->json('meta.columns'))->where('visible', true)->pluck('field')->all();

        $this->assertSame(['status', 'endpoint'], $visible);
    }

    #[Test]
    public function the_address_is_hidden_unless_it_is_asked_for(): void
    {
        $this->booking(['email' => 'maria@example.com']);

        // The most sensitive thing on the screen. A listing shared on a
        // projector should not carry it by default.
        $columns = collect(
            $this->actingAs($this->user())->getJson('/cp/utilities/bookings')->json('meta.columns')
        )->keyBy('field');

        $this->assertFalse($columns['email']['visible']);
        $this->assertTrue($columns['scheduled_at']['visible']);
    }
}
