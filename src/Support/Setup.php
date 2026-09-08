<?php

namespace Goldnead\StatamicBooking\Support;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The check the bookings screen runs before its first query.
 *
 * This addon can be installed without its migrations having run — composer
 * pulls the package in, the utility registers itself, the nav item appears,
 * and `bookings` still does not exist. The first thing the screen does is ask
 * the table a question, so the visitor gets HTTP 500 and a stack trace for
 * what is really an unfinished setup.
 *
 * A missing table is an operator's to-do, and it owes the reader a sentence
 * instead. But the reason must not vanish along with the crash: every guarded
 * page that turns somebody away writes why to the log first. A page that shows
 * an empty state and says nothing anywhere would be worse than the 500 it
 * replaced — the site would look installed and never work.
 */
final class Setup
{
    /**
     * The setup screen for a CP listing, or null when the page can run.
     *
     * @param  string  $title  The page's own heading, so the screen still reads as that page.
     * @param  string  ...$tables  Every table the listing touches while rendering.
     */
    public static function guard(string $title, string ...$tables): ?Response
    {
        $missing = array_values(array_filter(
            $tables,
            fn (string $table) => ! Schema::hasTable($table)
        ));

        if ($missing === []) {
            return null;
        }

        Log::error(sprintf(
            'statamic-booking: the CP page "%s" cannot load because these database tables do not exist: %s. Run `php artisan migrate`.',
            $title,
            implode(', ', $missing)
        ));

        return Inertia::render('statamic-booking::SetupRequired', [
            'title' => $title,
            'heading' => __('statamic-booking::messages.setup_required_heading'),
            'description' => __('statamic-booking::messages.setup_required_description'),
            'tables' => $missing,
        ]);
    }
}
