<?php

namespace Goldnead\ClientRooms\Http\Controllers\Cp;

use Carbon\Carbon;
use Goldnead\ClientRooms\ClientRoomsManager;
use Goldnead\ClientRooms\Http\Controllers\Cp\Concerns\AuthorizesRooms;
use Goldnead\ClientRooms\Models\ClientRoomTask;
use Goldnead\ClientRooms\Support\Owners;
use Illuminate\Http\Request;
use Statamic\Http\Controllers\CP\CpController;

class TasksController extends CpController
{
    use AuthorizesRooms;

    public function __construct(Request $request, protected ClientRoomsManager $rooms)
    {
        parent::__construct($request);
    }

    public function store(Request $request, int $room)
    {
        $this->authorize('edit client rooms');

        $room = $this->findRoom($room);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'due_at' => ['nullable', 'date'],
        ]);

        $this->rooms->addTask(
            $room,
            $data['title'],
            ! empty($data['due_at']) ? Carbon::parse($data['due_at'])->endOfDay() : null,
            Owners::currentId(),
        );

        return back()->with('success', __('statamic-clientrooms::messages.task_added'));
    }

    /** Tick or untick. */
    public function update(Request $request, int $room, int $task)
    {
        $this->authorize('edit client rooms');

        $room = $this->findRoom($room);
        $task = $this->task($room->id, $task);

        $data = $request->validate([
            'done' => ['required', 'boolean'],
        ]);

        if ($request->boolean('done')) {
            $this->rooms->completeTask($task, Owners::currentId());
        } else {
            $this->rooms->reopenTask($task);
        }

        return back()->with('success', __('statamic-clientrooms::messages.saved'));
    }

    public function destroy(int $room, int $task)
    {
        $this->authorize('edit client rooms');

        $room = $this->findRoom($room);
        $this->task($room->id, $task)->delete();
        $room->touchActivity();

        return back()->with('success', __('statamic-clientrooms::messages.task_deleted'));
    }

    protected function task(int $roomId, int $taskId): ClientRoomTask
    {
        // Through the room, so a task id from another room — or another
        // brand's room — is a 404 and not somebody else's tick.
        return ClientRoomTask::query()->where('room_id', $roomId)->findOrFail($taskId);
    }
}
