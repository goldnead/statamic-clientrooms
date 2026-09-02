<?php

namespace Goldnead\ClientRooms\Events;

use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\Models\ClientRoomTask;
use Illuminate\Foundation\Events\Dispatchable;

class ClientRoomTaskCompleted
{
    use Dispatchable;

    public function __construct(
        public readonly ClientRoom $room,
        public readonly ClientRoomTask $task,
    ) {}
}
