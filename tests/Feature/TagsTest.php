<?php

namespace Goldnead\StatamicBooking\Tests\Feature;

use Goldnead\StatamicBooking\Models\Booking;
use Goldnead\StatamicBooking\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Antlers;

class TagsTest extends TestCase
{
    protected function booking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'endpoint' => 'beratung',
            'external_id' => 'cal-'.uniqid(),
            'status' => Booking::STATUS_BOOKED,
            'scheduled_at' => now()->addDays(3),
            'name' => 'Maria Beispiel',
            'email' => 'maria@example.com',
            // Cal.com's real default title, not a tidy one. The earlier fixture
            // said "Erstgespräch" and the privacy test passed for the wrong
            // reason.
            'meta' => ['title' => '30 Min Meeting between Adrian Goldner and Maria Beispiel'],
        ], $overrides));
    }

    /**
     * The third argument marks the template as trusted. Without it Antlers
     * treats the string as user data and skips every tag without a word of
     * complaint — the test then asserts against an empty string and passes for
     * the wrong reason.
     */
    protected function parse(string $template): string
    {
        return (string) Antlers::parse($template, [], true);
    }

    #[Test]
    public function it_never_exposes_an_address(): void
    {
        $this->booking();

        $html = $this->parse('{{ bookings }}[{{ email }}|{{ name }}|{{ title }}]{{ /bookings }}');

        // One careless template is all it takes to publish the bookers. The tag
        // does not carry them, so the careless template cannot.
        $this->assertStringNotContainsString('maria@example.com', $html);
        $this->assertStringNotContainsString('Maria Beispiel', $html);
    }

    #[Test]
    public function it_lists_upcoming_bookings_soonest_first(): void
    {
        $this->booking(['scheduled_at' => now()->addDays(9), 'status' => 'spaet']);
        $this->booking(['scheduled_at' => now()->addDay(), 'status' => 'frueh']);

        $html = $this->parse('{{ bookings }}[{{ status }}]{{ /bookings }}');

        $this->assertLessThan(strpos($html, 'spaet'), strpos($html, 'frueh'));
    }

    #[Test]
    public function it_leaves_out_the_past_and_the_cancelled(): void
    {
        $this->booking(['scheduled_at' => now()->subDay(), 'status' => 'vorbei']);
        $this->booking(['cancelled_at' => now(), 'status' => 'abgesagt']);
        $this->booking(['status' => 'gilt']);

        $html = $this->parse('{{ bookings }}[{{ status }}]{{ /bookings }}');

        $this->assertStringContainsString('gilt', $html);
        $this->assertStringNotContainsString('vorbei', $html);
        $this->assertStringNotContainsString('abgesagt', $html);
    }

    #[Test]
    public function it_can_be_narrowed_to_one_endpoint(): void
    {
        $this->booking(['endpoint' => 'beratung', 'status' => 'beratung-eins']);
        $this->booking(['endpoint' => 'unterricht', 'status' => 'unterricht-eins']);

        $html = $this->parse('{{ bookings endpoint="beratung" }}[{{ status }}]{{ /bookings }}');

        $this->assertStringContainsString('beratung-eins', $html);
        $this->assertStringNotContainsString('unterricht-eins', $html);
    }

    #[Test]
    public function the_count_tag_counts_the_same_set(): void
    {
        $this->booking();
        $this->booking(['scheduled_at' => now()->subDay()]);
        $this->booking(['cancelled_at' => now()]);

        $this->assertSame('1', trim($this->parse('{{ bookings:count }}')));
    }

    #[Test]
    public function an_empty_endpoint_offers_a_clean_empty_state(): void
    {
        // A Statamic tag pair parses its block once even with no results, with
        // `no_results` set — so the row markup has to sit in the `else` branch,
        // or an empty endpoint prints one blank row above the notice. That is
        // the idiom the README shows, and this is what checks it stays true.
        $out = $this->parse('{{ bookings endpoint="leer" }}{{ if no_results }}<p>nichts</p>{{ else }}<li>{{ scheduled_at }}</li>{{ /if }}{{ /bookings }}');

        $this->assertSame('<p>nichts</p>', trim($out));
    }
}
