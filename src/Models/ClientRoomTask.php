<?php

namespace Goldnead\ClientRooms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One thing to do in a room.
 *
 * @property int $id
 * @property int $room_id
 * @property string $title
 * @property Carbon|null $due_at
 * @property Carbon|null $done_at
 * @property string|null $done_by
 * @property string|null $created_by
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ClientRoomTask extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'done_at' => 'datetime',
            'position' => 'integer',
        ];
    }

    /** @return BelongsTo<ClientRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ClientRoom::class, 'room_id');
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isDone() && $this->due_at !== null && $this->due_at->isPast();
    }
}
