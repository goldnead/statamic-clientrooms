<?php

namespace Goldnead\ClientRooms\Http\Resources\Cp;

use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\Support\Brands;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One row.
 *
 * @mixin ClientRoom
 *
 * @property int $open_tasks_count
 */
class ListedRoom extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->displayName(),
            'has_name' => $this->name !== null && trim($this->name) !== '',
            'email' => $this->email,
            'status' => $this->status,
            'status_label' => __('statamic-clientrooms::messages.status_'.$this->status),
            'is_open' => $this->isOpen(),
            'open_tasks' => (int) ($this->open_tasks_count ?? 0),
            'last_activity_at' => $this->last_activity_at?->toIso8601String(),
            'last_activity_human' => $this->last_activity_at?->diffForHumans(),
            'opened_at' => $this->opened_at?->toIso8601String(),
            'opened_human' => $this->opened_at?->diffForHumans(),
            'brand' => Brands::label((int) $this->brand_id),
            'show_url' => cp_route('client-rooms.show', $this->id),
        ];
    }
}
