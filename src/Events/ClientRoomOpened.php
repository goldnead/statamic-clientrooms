<?php

namespace Goldnead\ClientRooms\Events;

use Goldnead\ClientRooms\Models\ClientRoom;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A room was opened, or reopened after being closed.
 *
 * Dispatched once per transition, never for an `open()` on a room that was
 * already open — a listener that mails a welcome must not mail it twice
 * because a second product was bought.
 */
class ClientRoomOpened
{
    use Dispatchable;

    public function __construct(
        public readonly ClientRoom $room,
        public readonly bool $reopened = false,
    ) {}
}
