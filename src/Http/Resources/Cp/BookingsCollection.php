<?php

namespace Goldnead\StatamicBooking\Http\Resources\Cp;

use Illuminate\Http\Resources\Json\ResourceCollection;
use Statamic\CP\Column;
use Statamic\CP\Columns;
use Statamic\Http\Resources\CP\Concerns\HasRequestedColumns;

/**
 * The listing payload, built the way core builds its own.
 *
 * The part worth spelling out is `HasRequestedColumns` together with
 * `setPreferred()`. Without them the server answers every request with the same
 * fixed set of visible columns, and since the Listing takes its columns from
 * each response, the column picker becomes a control that reports success and
 * changes nothing: the tick springs back, "These are now your default columns"
 * appears, and the preference is written and then ignored for ever.
 */
class BookingsCollection extends ResourceCollection
{
    use HasRequestedColumns;

    public $collects = ListedBooking::class;

    protected $columns;

    protected ?string $columnPreferenceKey = null;

    public function columnPreferenceKey(string $key): self
    {
        $this->columnPreferenceKey = $key;

        return $this;
    }

    private function setColumns(): self
    {
        $columns = new Columns([
            Column::make('scheduled_at')->label(__('statamic-booking::messages.column_when'))->sortable(true)->defaultOrder(1),
            Column::make('name')->label(__('statamic-booking::messages.column_who'))->sortable(true)->defaultOrder(2),
            Column::make('status')->label(__('statamic-booking::messages.column_status'))->sortable(true)->defaultOrder(3),
            Column::make('duration_minutes')->label(__('statamic-booking::messages.column_duration'))->sortable(false)->defaultOrder(4),
            Column::make('endpoint')->label(__('statamic-booking::messages.column_endpoint'))->sortable(true)->defaultOrder(5),
            // Off unless asked for. The address is the most sensitive thing on
            // this screen, and a screen shared in a meeting should not carry it
            // by default.
            Column::make('email')->label(__('statamic-booking::messages.column_email'))->sortable(true)->defaultOrder(6)->defaultVisibility(false)->visible(false),
            Column::make('created_at')->label(__('statamic-booking::messages.column_received'))->sortable(true)->defaultOrder(7)->defaultVisibility(false)->visible(false),
        ]);

        if ($key = $this->columnPreferenceKey) {
            $columns->setPreferred($key);
        }

        $this->columns = $columns->rejectUnlisted()->values();

        return $this;
    }

    public function toArray($request)
    {
        $this->setColumns();

        return $this->collection;
    }

    public function with($request)
    {
        return [
            'meta' => [
                // Read out of every response by the Listing. Missing, the read
                // throws inside the component's own promise, lands in its catch
                // and shows "Something went wrong" over a screen where the rows,
                // the search and the paging all still work.
                'columns' => $this->visibleColumns(),
            ],
        ];
    }
}
