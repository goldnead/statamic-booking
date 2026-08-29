<?php

namespace Goldnead\StatamicBooking\Tests\Feature;

use Goldnead\StatamicBooking\Integrations\Insights\CancellationRate;
use Goldnead\StatamicBooking\Integrations\Insights\Cancelled;
use Goldnead\StatamicBooking\Integrations\Insights\HoursBooked;
use Goldnead\StatamicBooking\Integrations\Insights\Scheduled;
use Goldnead\StatamicBooking\Models\Booking;
use Goldnead\StatamicBooking\Tests\TestCase;
use Goldnead\StatamicInsights\Contracts\Metric;
use Goldnead\StatamicInsights\Facades\Insights as InsightsStandIn;
use Goldnead\StatamicInsights\Support\MetricQuery;
use Goldnead\StatamicInsights\Support\Period;
use Goldnead\StatamicInsights\Support\Unit;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The four numbers this addon offers the analytics addon.
 *
 * Every expectation below is worked out by hand from one small fixture, because
 * the whole risk in a metric is arithmetic that looks right: a figure counted on
 * the wrong date, a split that silently drops a row, a rate that answers 0 %
 * where it has no denominator. None of those throw. They are only ever caught by
 * somebody re-adding the numbers, which is what this file does once and for all.
 *
 * The fixture is built around the two dates a booking has. It holds an
 * appointment inside the window, one outside it, one with no date at all, and —
 * the case worth the whole file — an appointment in **September** that was
 * cancelled in **August**. That row is counted by `booking.cancelled` and by
 * neither `booking.scheduled` nor the cancellation rate, which is exactly why
 * the rate is not the division of the two tiles beside it.
 *
 * Tested against a stand-in for the contract rather than the real package: the
 * sibling is optional, and a test that needed it installed would be proving the
 * opposite of what this addon claims. See `tests/Fakes/insights-contracts.php`
 * for why those are required files and not autoload entries, and
 * `InsightsContractsMatchTest` for what holds the copies to account.
 *
 * Time is frozen. The buckets are asserted as literal dates, and a suite that
 * ran across midnight would otherwise fail once a night for reasons that have
 * nothing to do with the code.
 */
class InsightsMetricsTest extends TestCase
{
    /** The day everything below is measured from. */
    protected const HEUTE = '2026-08-20 12:00:00';

    /** Collects what the service provider registers. */
    protected object $insights;

    protected function setUp(): void
    {
        // Before the application exists, all three. The contracts have to be
        // there before a metric class is loaded, the base class after them and
        // before the same moment, and the facade before the provider's
        // `booted()` callback asks whether it is there — a callback that has
        // already run cannot be given a second chance.
        require_once __DIR__.'/../Fakes/insights-contracts.php';

        if (! class_exists('Goldnead\StatamicInsights\Support\TableMetric')) {
            require_once __DIR__.'/../Fakes/insights-table-metric.php';
        }

        require_once __DIR__.'/../Fakes/insights-facade.php';

        $this->insights = new class
        {
            /** @var array<string, string> */
            public array $registered = [];

            /**
             * Stricter than the real manager on purpose.
             *
             * The genuine one accepts a metric without a handle and works one
             * out by constructing it. Accepting that here would let the
             * provider drop the handle and still look correct — and the handle
             * is the half that ends up in saved dashboards and URLs.
             */
            public function registerMetric(string|Metric|\Closure $metric, ?string $handle = null): void
            {
                if (! is_string($metric) || $handle === null) {
                    throw new \InvalidArgumentException('This addon registers metrics lazily: a class name and a handle.');
                }

                $this->registered[$handle] = $metric;
            }
        };

        InsightsStandIn::$root = $this->insights;

        parent::setUp();

        Carbon::setTestNow(Carbon::parse(self::HEUTE));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        InsightsStandIn::$root = null;

        parent::tearDown();
    }

    // -- The fixture --------------------------------------------------------

    /**
     * Four appointments in the window, three cancellations, two strays.
     *
     * Small enough to add up in the head, and every awkward case is in it: two
     * appointments on one day, a cancelled one, a rejected one, a booking with
     * no date yet, an appointment beyond the window, and an appointment beyond
     * the window that was cancelled inside it.
     *
     * In the window (11.–20. August): four appointments, of which two fell
     * through, and 90 minutes that are actually going to happen.
     */
    protected function fixture(): void
    {
        // Two on the same day, both happening. 60 + 30 minutes.
        $this->booking(['external_id' => 'cal-1', 'endpoint' => 'beratung', 'scheduled_at' => '2026-08-12 10:00:00', 'duration_minutes' => 60]);
        $this->booking(['external_id' => 'cal-2', 'endpoint' => 'beratung', 'scheduled_at' => '2026-08-12 14:00:00', 'duration_minutes' => 30]);

        // Called off by the visitor, four days before it would have happened.
        $this->booking([
            'external_id' => 'cal-3',
            'endpoint' => 'unterricht',
            'status' => Booking::STATUS_CANCELLED,
            'scheduled_at' => '2026-08-15 09:00:00',
            'cancelled_at' => '2026-08-13 08:00:00',
            'duration_minutes' => 90,
        ]);

        // Declined by the organiser. A different fact, the same consequence:
        // the appointment is not happening, and `cancelled_at` says so.
        $this->booking([
            'external_id' => 'cal-4',
            'endpoint' => 'unterricht',
            'status' => Booking::STATUS_REJECTED,
            'scheduled_at' => '2026-08-18 09:00:00',
            'cancelled_at' => '2026-08-17 08:00:00',
            'duration_minutes' => 45,
        ]);

        // September. Not in a single figure of this window.
        $this->booking(['external_id' => 'cal-5', 'endpoint' => 'beratung', 'scheduled_at' => '2026-09-01 10:00:00', 'duration_minutes' => 120]);

        // Asked for, no date agreed. Falls out of every window, because a null
        // is never inside one — and that is the honest place for it.
        $this->booking([
            'external_id' => 'cal-6',
            'endpoint' => 'beratung',
            'status' => Booking::STATUS_REQUESTED,
            'scheduled_at' => null,
            'duration_minutes' => 60,
        ]);

        // The row this whole file is built around: a September appointment
        // called off in August. A cancellation in this window, an appointment
        // in none of it.
        $this->booking([
            'external_id' => 'cal-7',
            'endpoint' => 'unterricht',
            'status' => Booking::STATUS_CANCELLED,
            'scheduled_at' => '2026-09-05 09:00:00',
            'cancelled_at' => '2026-08-16 08:00:00',
            'duration_minutes' => 30,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    protected function booking(array $overrides = []): Booking
    {
        return Booking::create(array_merge([
            'endpoint' => 'beratung',
            'external_id' => 'cal-'.uniqid(),
            'status' => Booking::STATUS_BOOKED,
            'scheduled_at' => '2026-08-12 10:00:00',
            'timezone' => 'Europe/Berlin',
            'duration_minutes' => 60,
            'name' => 'Maria Beispiel',
            'email' => 'maria@example.com',
        ], $overrides));
    }

    /** The ten days the fixture lives in, bucketed by day. */
    protected function frage(array $filters = [], string $bucket = MetricQuery::BUCKET_DAY): MetricQuery
    {
        return new MetricQuery(
            Period::between(Carbon::parse('2026-08-11')->startOfDay(), Carbon::parse('2026-08-20')->endOfDay()),
            $bucket,
            $filters,
        );
    }

    /** @return array<string, int|float> */
    protected function keyed(array $rows): array
    {
        $keyed = [];

        foreach ($rows as $row) {
            $keyed[$row['key'] ?? ''] = $row['value'];
        }

        return $keyed;
    }

    /**
     * By key, for comparing a split whose rows are the same size.
     *
     * @param  array<string, int|float>  $keyed
     * @return array<string, int|float>
     */
    protected function sortiert(array $keyed): array
    {
        ksort($keyed);

        return $keyed;
    }

    // -- The four numbers ---------------------------------------------------

    /**
     * Every figure at once, against hand-worked totals.
     *
     * One test rather than four, deliberately: they are read side by side on a
     * screen and have to agree with each other. A count that changed without
     * the rate following it is the failure worth catching, and four separate
     * tests are four chances to fix one of them and leave the rest.
     */
    #[Test]
    public function the_four_figures_are_the_ones_a_person_would_count(): void
    {
        $this->fixture();
        $frage = $this->frage();

        $this->assertSame(4, (new Scheduled)->value($frage), 'appointments on the 12th (two), the 15th and the 18th');
        $this->assertSame(3, (new Cancelled)->value($frage), 'cancelled on the 13th, the 16th and the 17th');
        $this->assertSame(50.0, (new CancellationRate)->value($frage), 'two of the window’s four appointments fell through');
        $this->assertSame(5400, (new HoursBooked)->value($frage), '(60 + 30) minutes that are happening, in seconds');
    }

    /**
     * The rate is not the two tiles beside it divided into each other.
     *
     * Three cancellations arrived in the window and four appointments were in
     * it, which would be 75 %. The honest answer is 50 %: one of those three
     * cancellations removed a September date that was never part of this
     * window's four. Mixing the two axes is how a report claims that more
     * appointments were called off than existed.
     */
    #[Test]
    public function the_rate_counts_the_window_s_appointments_not_the_window_s_cancellations(): void
    {
        $this->fixture();
        $frage = $this->frage();

        $this->assertSame(3, (new Cancelled)->value($frage));
        $this->assertSame(4, (new Scheduled)->value($frage));
        $this->assertNotSame(75.0, (new CancellationRate)->value($frage));
        $this->assertSame(50.0, (new CancellationRate)->value($frage));
    }

    /**
     * A cancelled hour is not a booked hour.
     *
     * The window holds 60 + 30 minutes that are happening and 90 + 45 that were
     * called off. A figure that added all four would tell somebody their August
     * was three and a half hours fuller than it is.
     */
    #[Test]
    public function cancelled_appointments_do_not_fill_the_diary(): void
    {
        $this->fixture();

        $this->assertSame(5400, (new HoursBooked)->value($this->frage()), '90 minutes, not 225');
        $this->assertSame(['2026-08-12' => 5400], (new HoursBooked)->series($this->frage()));
    }

    /** Whole seconds, as the contract requires — never minutes, never hours. */
    #[Test]
    public function a_duration_is_reported_in_whole_seconds(): void
    {
        $this->booking(['external_id' => 'cal-dauer', 'scheduled_at' => '2026-08-14 09:00:00', 'duration_minutes' => 45]);

        $this->assertSame(2700, (new HoursBooked)->value($this->frage()), '45 minutes × 60');
        $this->assertSame(Unit::DURATION, (new HoursBooked)->unit());
    }

    /** A provider that reports no length contributes nothing, not a guess. */
    #[Test]
    public function a_booking_without_a_length_adds_no_time(): void
    {
        $this->booking(['external_id' => 'cal-ohne', 'scheduled_at' => '2026-08-14 09:00:00', 'duration_minutes' => null]);

        $this->assertSame(1, (new Scheduled)->value($this->frage()), 'it is still an appointment');
        $this->assertSame(0, (new HoursBooked)->value($this->frage()));
    }

    /** The handles are a contract. They end up in saved dashboards and in URLs. */
    #[Test]
    public function the_handles_and_units_are_the_ones_that_were_promised(): void
    {
        $erwartet = [
            [Scheduled::class, 'booking.scheduled', Unit::COUNT],
            [Cancelled::class, 'booking.cancelled', Unit::COUNT],
            [CancellationRate::class, 'booking.cancellation_rate', Unit::PERCENT],
            [HoursBooked::class, 'booking.hours_booked', Unit::DURATION],
        ];

        foreach ($erwartet as [$klasse, $handle, $unit]) {
            $metrik = new $klasse;

            $this->assertSame($handle, $metrik->handle());
            $this->assertSame($unit, $metrik->unit());
            $this->assertSame(__('statamic-booking::messages.metric_group'), $metrik->group());
            $this->assertNotSame('', $metrik->label());
            $this->assertNotEmpty($metrik->description());

            // Nothing here is money or needs a unit the formatter cannot infer.
            $this->assertSame([], $metrik->meta($this->frage()));
        }
    }

    /** The group is one translated heading, not four. */
    #[Test]
    public function every_figure_sits_under_the_same_translated_heading(): void
    {
        $this->assertSame('Buchungen', __('statamic-booking::messages.metric_group', [], 'de'));
        $this->assertSame('Bookings', __('statamic-booking::messages.metric_group', [], 'en'));
    }

    // -- Nothing to measure -------------------------------------------------

    /**
     * No table, no answer — and not a zero.
     *
     * "Nothing to measure" and "measured nothing" are different statements, and
     * a zero for the first is the quiet kind of wrong: it puts a confident
     * "0 appointments" on a dashboard for a site that has not installed this
     * addon's migrations at all.
     */
    #[Test]
    public function a_metric_cannot_answer_without_the_table(): void
    {
        $this->assertTrue((new Scheduled)->available());

        // A second, empty database rather than dropping the table in this one.
        // Dropping it would leave the suite unable to roll its own migrations
        // back, and a test that breaks its neighbours' teardown reports the
        // wrong failure everywhere afterwards.
        config()->set('database.connections.ohne_buchungen', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $vorher = DB::getDefaultConnection();
        DB::purge('ohne_buchungen');
        DB::setDefaultConnection('ohne_buchungen');

        try {
            foreach ([Scheduled::class, Cancelled::class, CancellationRate::class, HoursBooked::class] as $klasse) {
                $metrik = new $klasse;

                $this->assertFalse($metrik->available(), $klasse.' answered without a bookings table.');
                $this->assertNull($metrik->value($this->frage()), $klasse.' produced a figure without a table.');
                $this->assertSame([], $metrik->series($this->frage()));
            }

            $this->assertSame([], (new Scheduled)->breakdown($this->frage(), 'status'));
        } finally {
            DB::setDefaultConnection($vorher);
        }
    }

    // -- A rate with no denominator -----------------------------------------

    /**
     * A rate against nothing is a question, not a small number.
     *
     * The window holds a cancellation and no appointments, which is what a
     * September date called off in August looks like from a single day in
     * August. "0 %" would sit on the screen directly beside the cancellation
     * that disproves it.
     */
    #[Test]
    public function the_rate_is_null_when_the_window_holds_no_appointments(): void
    {
        $this->fixture();

        $einTag = new MetricQuery(
            Period::between(Carbon::parse('2026-08-16')->startOfDay(), Carbon::parse('2026-08-16')->endOfDay()),
        );

        $this->assertSame(0, (new Scheduled)->value($einTag), 'nothing was due that day');
        $this->assertSame(1, (new Cancelled)->value($einTag), 'and yet something was called off');
        $this->assertNull((new CancellationRate)->value($einTag), 'so there is no rate to state');
        $this->assertSame([], (new CancellationRate)->series($einTag));
    }

    /** Nothing at all in the window, and still no invented zero. */
    #[Test]
    public function an_empty_window_has_counts_but_no_rate(): void
    {
        $this->fixture();

        $leer = new MetricQuery(
            Period::between(Carbon::parse('2025-01-01')->startOfDay(), Carbon::parse('2025-01-31')->endOfDay()),
        );

        $this->assertSame(0, (new Scheduled)->value($leer));
        $this->assertSame(0, (new Cancelled)->value($leer));
        $this->assertSame(0, (new HoursBooked)->value($leer));
        $this->assertNull((new CancellationRate)->value($leer));
    }

    // -- The window that has no edges ---------------------------------------

    /**
     * Over all time, a figure still counts only the rows that have its date.
     *
     * The one preset with no bounds, and the one where a metric can quietly
     * count everything: windowing is two comparisons against the period's ends,
     * and with no ends there are no comparisons. Every date on a booking is
     * nullable, so without a condition of its own `booking.cancelled` would
     * report all seven bookings as cancellations here — a number that is wrong
     * by more than a factor of two and looks perfectly ordinary on a dashboard.
     *
     * Seven bookings exist: six carry a date, one was only ever requested;
     * three were called off; two of the dated ones are cancelled or rejected
     * and 60 + 30 + 120 minutes are still going to happen.
     */
    #[Test]
    public function an_open_ended_period_still_counts_only_the_rows_that_have_the_date(): void
    {
        $this->fixture();

        $immer = new MetricQuery(Period::fromPreset('all'));

        $this->assertSame(6, (new Scheduled)->value($immer), 'the requested booking has no date yet');
        $this->assertSame(3, (new Cancelled)->value($immer), 'and not all seven');
        $this->assertSame(12600, (new HoursBooked)->value($immer), '(60 + 30 + 120) minutes in seconds');
        $this->assertSame(50.0, (new CancellationRate)->value($immer), 'three of six dated appointments fell through');

        // And no bucket keyed by nothing: a truncated null is a null key, which
        // would reach the chart as a column with no date on the axis.
        foreach ([new Scheduled, new Cancelled, new HoursBooked] as $metrik) {
            $this->assertSame([], array_filter(array_keys($metrik->series($immer)), fn ($bucket) => $bucket === '' || $bucket === null));
        }
    }

    // -- Over time ----------------------------------------------------------

    /**
     * Only the buckets that have something in them.
     *
     * The empty days are Insights' job — it fills the range for every metric at
     * once. A metric that filled its own would be filled twice, and one that
     * invented a bucket outside the range would draw a column the axis has no
     * place for.
     */
    #[Test]
    public function a_series_returns_only_the_buckets_that_have_data(): void
    {
        $this->fixture();
        $frage = $this->frage();

        $this->assertSame(
            ['2026-08-12' => 2, '2026-08-15' => 1, '2026-08-18' => 1],
            (new Scheduled)->series($frage),
        );

        // The cancellations sit on the days they arrived, not on the days of
        // the appointments they removed — which is why this series shares not a
        // single bucket with the one above it.
        $this->assertSame(
            ['2026-08-13' => 1, '2026-08-16' => 1, '2026-08-17' => 1],
            (new Cancelled)->series($frage),
        );
    }

    /**
     * A rate per bucket, and only where there is something to divide by.
     *
     * The 13th, 16th and 17th hold cancellations and no appointments, so they
     * have no rate at all — correctly, because a rate needs a denominator and
     * those days have none.
     */
    #[Test]
    public function the_rate_series_skips_the_days_with_no_appointments(): void
    {
        $this->fixture();

        $this->assertSame(
            ['2026-08-12' => 0.0, '2026-08-15' => 100.0, '2026-08-18' => 100.0],
            (new CancellationRate)->series($this->frage()),
        );
    }

    /**
     * The grain comes from the question, not from the period.
     *
     * Insights decides the grain and puts it in the query. A metric that worked
     * it out again from the period length could disagree with the axis it is
     * drawn on.
     */
    #[Test]
    public function a_monthly_question_gets_monthly_buckets(): void
    {
        $this->fixture();

        $monatlich = $this->frage([], MetricQuery::BUCKET_MONTH);

        $this->assertSame(['2026-08' => 4], (new Scheduled)->series($monatlich));
        $this->assertSame(['2026-08' => 5400], (new HoursBooked)->series($monatlich));
        $this->assertSame(['2026-08' => 50.0], (new CancellationRate)->series($monatlich));
    }

    // -- The splits ---------------------------------------------------------

    /** Both splits add up to the figure they split. */
    #[Test]
    public function the_splits_add_up_to_the_figure_they_split(): void
    {
        $this->fixture();
        $frage = $this->frage();

        $nachStatus = (new Scheduled)->breakdown($frage, 'status');
        $nachEndpunkt = (new Scheduled)->breakdown($frage, 'endpoint');

        // Sorted by key before comparing, because rows of equal size tie and no
        // database promises an order between them. The ordering that is
        // promised — largest first — is asserted where it is unambiguous.
        $this->assertSame(
            ['booked' => 2, 'cancelled' => 1, 'rejected' => 1],
            $this->sortiert($this->keyed($nachStatus)),
        );

        $this->assertSame(
            ['beratung' => 2, 'unterricht' => 2],
            $this->sortiert($this->keyed($nachEndpunkt)),
        );

        $this->assertSame(4, array_sum(array_column($nachStatus, 'value')));
        $this->assertSame(4, array_sum(array_column($nachEndpunkt, 'value')));
    }

    /**
     * A status is printed the way the rest of the Control Panel prints it.
     *
     * `booked` is a handle and "Gebucht" is what a person reads. The words
     * already exist — the bookings listing uses the same keys — and a split
     * showing raw handles would be the only screen in the panel that does.
     */
    #[Test]
    public function a_status_reaches_the_screen_as_a_word_and_not_as_a_handle(): void
    {
        $this->fixture();

        $zeilen = (new Scheduled)->breakdown($this->frage(), 'status');

        // Largest first: two `booked` against one each of the others.
        $this->assertSame('booked', $zeilen[0]['key']);
        $this->assertSame(__('statamic-booking::messages.status_booked'), $zeilen[0]['label']);

        // The endpoint is a configured name and has no translation to make.
        $this->assertSame(
            ['beratung', 'unterricht'],
            collect((new Scheduled)->breakdown($this->frage(), 'endpoint'))->pluck('label')->sort()->values()->all(),
        );
    }

    /** An unknown status keeps its handle rather than vanishing from the report. */
    #[Test]
    public function a_status_this_package_does_not_know_still_shows_up(): void
    {
        $this->booking(['external_id' => 'cal-fremd', 'status' => 'pending_payment', 'scheduled_at' => '2026-08-14 09:00:00']);

        $zeilen = (new Scheduled)->breakdown($this->frage(), 'status');

        $this->assertSame('pending_payment', $zeilen[0]['key']);
        $this->assertSame('pending_payment', $zeilen[0]['label']);
    }

    /**
     * A row with no value in the dimension is a row, not an omission.
     *
     * `status` and `endpoint` are `NOT NULL`, so the empty case arrives as an
     * empty string rather than as a null — written here through the query
     * builder, because the model would not normally produce one. It has to
     * appear as a keyless row with words of its own: a split that quietly
     * dropped it would disagree with the total it splits, and nothing on the
     * screen would say why.
     */
    #[Test]
    public function a_booking_with_no_endpoint_or_status_keeps_its_place_in_the_split(): void
    {
        $this->fixture();

        DB::table('bookings')->insert([
            'endpoint' => '',
            'external_id' => 'cal-leer',
            'status' => '',
            'scheduled_at' => '2026-08-19 09:00:00',
            'duration_minutes' => 15,
            'created_at' => '2026-08-19 09:00:00',
            'updated_at' => '2026-08-19 09:00:00',
        ]);

        $frage = $this->frage();

        $this->assertSame(5, (new Scheduled)->value($frage));

        $nachStatus = (new Scheduled)->breakdown($frage, 'status');
        $nachEndpunkt = (new Scheduled)->breakdown($frage, 'endpoint');

        $ohneStatus = collect($nachStatus)->firstWhere('key', null);
        $ohneEndpunkt = collect($nachEndpunkt)->firstWhere('key', null);

        $this->assertNotNull($ohneStatus, 'the row without a status was dropped from the split');
        $this->assertNotNull($ohneEndpunkt, 'the row without an endpoint was dropped from the split');

        $this->assertSame(1, $ohneStatus['value']);
        $this->assertSame(1, $ohneEndpunkt['value']);

        $this->assertSame(__('statamic-booking::messages.metric_no_status'), $ohneStatus['label']);
        $this->assertSame(__('statamic-booking::messages.metric_no_endpoint'), $ohneEndpunkt['label']);

        // And the split still adds up to the figure it splits.
        $this->assertSame(5, array_sum(array_column($nachStatus, 'value')));
        $this->assertSame(5, array_sum(array_column($nachEndpunkt, 'value')));
    }

    /** A split nobody offers is empty, not an error. */
    #[Test]
    public function an_unknown_split_is_empty(): void
    {
        $this->fixture();

        $this->assertSame([], (new Scheduled)->breakdown($this->frage(), 'weather'));
        $this->assertSame(['status', 'endpoint'], array_keys((new Scheduled)->breakdowns()));
    }

    /** Largest first, and no more than asked for. */
    #[Test]
    public function a_split_is_ordered_by_size_and_respects_the_limit(): void
    {
        $this->fixture();

        $zeilen = (new Scheduled)->breakdown($this->frage(), 'status', 2);

        $this->assertCount(2, $zeilen);
        $this->assertSame('booked', $zeilen[0]['key'], 'the largest row comes first');
        $this->assertGreaterThanOrEqual($zeilen[1]['value'], $zeilen[0]['value']);
    }

    // -- The two tiles that read like a bug ---------------------------------

    /**
     * "Cancellations 2" beside "Cancellation rate 0 %", and the words that make
     * it readable.
     *
     * Both figures are right and they still look wrong together, which is what
     * was reported from the running screen. Two September appointments called
     * off in August: `Cancelled` windows on `cancelled_at` and counts them,
     * `CancellationRate` windows on `scheduled_at` and never sees them, and the
     * window's own appointments all held. A reader dividing one tile by the
     * other gets 200 % from numbers that are individually correct.
     *
     * The fix is not arithmetic — changing either figure would make it answer a
     * question nobody asked. It is the sentence under the rate, which has to say
     * that it measures a cohort. So the sentence is asserted here: a description
     * that quietly went back to "how many of the period's appointments fell
     * through" leaves the pair below unexplained again, and nothing else in the
     * suite would notice.
     */
    #[Test]
    public function the_rate_says_in_words_why_it_is_not_the_two_tiles_divided(): void
    {
        // Two September dates, called off inside the window.
        $this->booking(['external_id' => 'cal-sep-1', 'scheduled_at' => '2026-09-03 10:00:00', 'status' => Booking::STATUS_CANCELLED, 'cancelled_at' => '2026-08-14 09:00:00']);
        $this->booking(['external_id' => 'cal-sep-2', 'scheduled_at' => '2026-09-04 10:00:00', 'status' => Booking::STATUS_CANCELLED, 'cancelled_at' => '2026-08-15 09:00:00']);

        // One appointment of the window itself, which held.
        $this->booking(['external_id' => 'cal-aug', 'scheduled_at' => '2026-08-13 10:00:00']);

        $frage = $this->frage();

        $this->assertSame(2, (new Cancelled)->value($frage), 'two cancellations arrived in this window');
        $this->assertSame(0.0, (new CancellationRate)->value($frage), 'none of this window’s own appointments fell through');

        foreach (['en', 'de'] as $locale) {
            $satz = __('statamic-booking::messages.metric_cancellation_rate_description', [], $locale);

            $this->assertNotSame('statamic-booking::messages.metric_cancellation_rate_description', $satz);
            $this->assertStringContainsString(
                $locale === 'de' ? 'Nicht ' : 'Not ',
                $satz,
                'the rate has to say that it is not the tile beside it divided by its neighbour.',
            );
            $this->assertStringContainsString(
                $locale === 'de' ? 'Tag der Absage' : 'day they arrived',
                $satz,
                'and it has to name the other axis, or the denial explains nothing.',
            );
        }
    }

    // -- The wiring ---------------------------------------------------------

    /**
     * The provider hands all four to the sibling, lazily and by handle.
     *
     * By class name rather than instance, so booting this addon does not build
     * four metric objects on a request that renders none of them.
     */
    #[Test]
    public function the_service_provider_offers_every_metric_to_the_sibling(): void
    {
        $this->assertSame([
            'booking.scheduled' => Scheduled::class,
            'booking.cancelled' => Cancelled::class,
            'booking.cancellation_rate' => CancellationRate::class,
            'booking.hours_booked' => HoursBooked::class,
        ], $this->insights->registered);
    }
}
