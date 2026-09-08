<?php

namespace Goldnead\StatamicBooking\Http\Controllers\Cp;

use Goldnead\StatamicBooking\Http\Resources\Cp\BookingsCollection;
use Goldnead\StatamicBooking\Models\Booking;
use Goldnead\StatamicBooking\Support\Setup;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Statamic\Facades\Scope;
use Statamic\Http\Controllers\CP\CpController;
use Statamic\Http\Requests\FilteredRequest;
use Statamic\Query\Scopes\Filters\Concerns\QueriesFilters;
use Statamic\Statamic;

/**
 * The bookings screen in the Control Panel.
 *
 * Read-only on purpose. Cal.com owns these appointments; a cancel button here
 * would put the site and the calendar out of step, with the site being the one
 * that is wrong.
 *
 * The Inertia response deliberately carries no rows. The Listing fetches them
 * itself, so handing them over as well would mean every page load queries the
 * same list twice — and it is what core's own listings do.
 */
class BookingsController extends CpController
{
    use QueriesFilters;

    /** The key filters are registered and looked up under. */
    public const SCOPE = 'statamic-booking-bookings';

    public function index(FilteredRequest $request)
    {
        // The utility route already carries `can:access bookings utility`.
        // This is the second lock, for the day someone points a route of their
        // own at this action.
        $this->authorizeAccess();

        // Before the first query, and before the JSON branch below, because
        // that one queries too: without the migrations there is no `bookings`
        // table and either path would answer 500 instead of saying so.
        if ($setup = Setup::guard(__('statamic-booking::messages.utility_title'), 'bookings')) {
            return $setup;
        }

        if ($request->wantsJson() && ! $request->header('X-Inertia')) {
            return $this->json($request);
        }

        return Inertia::render('statamic-booking::Bookings/Index', [
            'listingUrl' => cp_route('utilities.bookings'),
            'filters' => Scope::filters(self::SCOPE),
            'sortColumn' => 'scheduled_at',
            'sortDirection' => 'asc',
            // Whether anything exists *at all*, which is a different question
            // from whether this search found anything. Driving the empty state
            // off the filtered result made a fruitless search claim "no
            // bookings yet, check your endpoint secret" — wrong, and with the
            // search box hidden there was no way back.
            'hasAny' => Booking::query()->exists(),
        ]);
    }

    protected function json(FilteredRequest $request)
    {
        $query = Booking::query();

        if ($search = $this->search($request)) {
            $this->applySearch($query, $search);
        }

        $activeFilterBadges = $this->queryFilters($query, $request->filters, ['scope' => self::SCOPE]);

        [$column, $direction] = $this->order($request);
        $query->orderBy($column, $direction);

        $bookings = $query->paginate(Statamic::cpPerPage($request->get('perPage')));

        return (new BookingsCollection($bookings))
            ->columnPreferenceKey('statamic-booking.bookings.columns')
            ->additional(['meta' => ['activeFilterBadges' => $activeFilterBadges]]);
    }

    protected function authorizeAccess(): void
    {
        // Through the Gate, which is where `Utility::register` puts the
        // permission and what the route's `can:` middleware consults. Asking
        // the user object instead means asking whichever guard happens to be
        // the default, and on a site with its own guard that answers null.
        abort_unless(Gate::allows('access bookings utility'), 403);
    }

    protected function search(FilteredRequest $request): ?string
    {
        $term = trim((string) $request->get('search', $request->get('q', '')));

        return $term === '' ? null : $term;
    }

    /**
     * @param  Builder<Booking>  $query
     */
    protected function applySearch(Builder $query, string $term): void
    {
        // `%` and `_` are wildcards in LIKE. Left alone, a search for "50%"
        // matches everything, which reads as a filter that does not work.
        //
        // The `ESCAPE` clause has to be spelled out: MySQL and Postgres treat a
        // backslash as an escape by default, **SQLite does not**, so without it
        // the escaping silently stops working on exactly the database a small
        // client site is most likely to run. Raw SQL only for the clause; the
        // column names come from the list below and the value stays bound.
        $escaped = addcslashes($term, '%_\\');

        $query->where(function (Builder $q) use ($escaped) {
            foreach (['name', 'email', 'endpoint', 'external_id'] as $column) {
                $q->orWhereRaw($column." LIKE ? ESCAPE '\\'", ['%'.$escaped.'%']);
            }
        });
    }

    /**
     * @return array{0: string, 1: string}
     */
    protected function order(FilteredRequest $request): array
    {
        // A positive list, not a filter. `sort` arrives from the query string,
        // and passed through it would let anyone order by any column in the
        // table — including the ones this screen deliberately hides.
        $sortable = ['scheduled_at', 'created_at', 'status', 'endpoint', 'name', 'email'];
        $column = (string) $request->get('sort', 'scheduled_at');
        $direction = strtolower((string) $request->get('order', 'asc')) === 'desc' ? 'desc' : 'asc';

        return [in_array($column, $sortable, true) ? $column : 'scheduled_at', $direction];
    }
}
