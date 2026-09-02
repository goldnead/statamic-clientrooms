<?php

namespace Goldnead\ClientRooms\Events;

use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\Models\ClientRoomTask;
use Goldnead\ClientRooms\Models\ClientRoomTaskSubmission;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * The client handed something back.
 *
 * Not the same moment as `ClientRoomTaskCompleted`, which is the coach ticking
 * the box. Handing in and being finished are two different claims, made by two
 * different people, and a host who wants to be told "there is something to
 * look at" wants this one.
 */
class ClientRoomTaskSubmitted
{
    use Dispatchable;

    public function __construct(
        public readonly ClientRoom $room,
        public readonly ClientRoomTask $task,
        public readonly ClientRoomTaskSubmission $submission,
    ) {}
}
