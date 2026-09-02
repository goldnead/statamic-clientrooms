<?php

namespace Goldnead\ClientRooms\Http\Resources\Cp;

use Goldnead\ClientRooms\Support\Brands;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Statamic\CP\Column;
use Statamic\CP\Columns;
use Statamic\Http\Resources\CP\Concerns\HasRequestedColumns;

/**
 * The listing payload, built the way core builds its own.
 */
class RoomsCollection extends ResourceCollection
{
    use HasRequestedColumns;

    public $collects = ListedRoom::class;

    protected $columns;

    protected ?string $columnPreferenceKey = null;

    public function columnPreferenceKey(string $key): self
    {
        $this->columnPreferenceKey = $key;

        return $this;
    }

    private function setColumns(): self
    {
        $multiBrand = Brands::multiBrand();

        $columns = new Columns([
            Column::make('name')->label(__('statamic-clientrooms::messages.column_name'))->sortable(true)->defaultOrder(1),
            Column::make('email')->label(__('statamic-clientrooms::messages.column_email'))->sortable(true)->defaultOrder(2),
            Column::make('status')->label(__('statamic-clientrooms::messages.column_status'))->sortable(true)->defaultOrder(3),
            Column::make('open_tasks')->label(__('statamic-clientrooms::messages.column_open_tasks'))->sortable(true)->numeric(true)->defaultOrder(4),
            Column::make('last_activity_at')->label(__('statamic-clientrooms::messages.column_last_activity'))->sortable(true)->defaultOrder(5),
            // Only worth a column where there is more than one brand.
            Column::make('brand')->label(__('statamic-clientrooms::messages.column_brand'))->sortable(true)->defaultOrder(6)->defaultVisibility($multiBrand)->visible($multiBrand),
            Column::make('opened_at')->label(__('statamic-clientrooms::messages.column_opened_at'))->sortable(true)->defaultOrder(7)->defaultVisibility(false)->visible(false),
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
                'columns' => $this->visibleColumns(),
            ],
        ];
    }
}
