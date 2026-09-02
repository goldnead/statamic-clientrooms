<?php

namespace Goldnead\ClientRooms\Events;

use Goldnead\ClientRooms\Models\ClientRoom;
use Illuminate\Foundation\Events\Dispatchable;

class ClientRoomClosed
{
    use Dispatchable;

    public function __construct(public readonly ClientRoom $room) {}
}
