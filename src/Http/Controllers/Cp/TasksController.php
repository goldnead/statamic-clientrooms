<?php

namespace Goldnead\ClientRooms\Http\Controllers\Cp;

use Carbon\Carbon;
use Goldnead\ClientRooms\ClientRoomsManager;
use Goldnead\ClientRooms\Http\Controllers\Cp\Concerns\AuthorizesRooms;
use Goldnead\ClientRooms\Models\ClientRoomTask;
use Goldnead\ClientRooms\Support\Owners;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
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

        $data = $request->validate(array_merge([
            'title' => ['required', 'string', 'max:255'],
            'due_at' => ['nullable', 'date'],
        ], $this->fieldRules()));

        $this->rooms->addTask(
            $room,
            $data['title'],
            ! empty($data['due_at']) ? Carbon::parse($data['due_at'])->endOfDay() : null,
            Owners::currentId(),
            $this->fields($request, $data),
        );

        return back()->with('success', __('statamic-clientrooms::messages.task_added'));
    }

    /**
     * Tick or untick, and change what the task says.
     *
     * One route, two jobs, because the checkbox and the edit form sit on the
     * same row of the same screen. `done` alone is the tick; everything else
     * goes through `updateTask()`, which never touches `done_at`. So a form
     * that sends `status: completed` says what the coach means by it and does
     * not quietly tick the box on his behalf.
     */
    public function update(Request $request, int $room, int $task)
    {
        $this->authorize('edit client rooms');

        $room = $this->findRoom($room);
        $task = $this->task($room->id, $task);

        $data = $request->validate(array_merge([
            'done' => ['sometimes', 'boolean'],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'due_at' => ['sometimes', 'nullable', 'date'],
        ], $this->fieldRules()));

        $fields = $this->fields($request, $data);

        if (array_key_exists('title', $data)) {
            $fields['title'] = $data['title'];
        }

        if (array_key_exists('due_at', $data)) {
            $fields['due_at'] = ! empty($data['due_at']) ? Carbon::parse($data['due_at'])->endOfDay() : null;
        }

        if ($fields !== []) {
            $this->rooms->updateTask($task, $fields);
        }

        if ($request->has('done')) {
            if ($request->boolean('done')) {
                $this->rooms->completeTask($task, Owners::currentId());
            } else {
                $this->rooms->reopenTask($task);
            }
        }

        return back()->with('success', __('statamic-clientrooms::messages.saved'));
    }

    /**
     * The rules for everything a task carries beyond title and due date.
     *
     * `sometimes` throughout: the same set serves the add form, which sends
     * all of them, and an edit that sends one. The vocabularies are checked
     * here and nowhere else — the facade takes what an import brings, because
     * years of a coach's own words must not be lost to a list.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function fieldRules(): array
    {
        return [
            'description' => ['sometimes', 'nullable', 'string', 'max:65535'],
            'type' => ['sometimes', 'nullable', 'string', Rule::in($this->taskTypes())],
            // Not the full vocabulary. `completed` here would set the state
            // without setting `done_at`, and the client would read "done" on a
            // task nobody ticked and then not do it. `overdue` comes from the
            // calendar. Both are derived, never typed. The facade still takes
            // all five, because an import carries states this form cannot make
            // — and an import that carries `completed` must carry `done_at`
            // with it.
            'status' => ['sometimes', 'nullable', 'string', Rule::in([
                ClientRoomTask::STATUS_ASSIGNED,
                ClientRoomTask::STATUS_IN_PROGRESS,
                ClientRoomTask::STATUS_CANCELLED,
            ])],
            'published_status' => ['sometimes', 'string', Rule::in(ClientRoomTask::PUBLISHED_STATUSES)],
            'priority' => ['sometimes', 'nullable', 'string', Rule::in(ClientRoomTask::PRIORITIES)],
            'estimated_minutes' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    /**
     * Only the keys the request actually sent, so an edit of one field leaves
     * the other five where they were.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function fields(Request $request, array $data): array
    {
        $fields = [];

        foreach (['description', 'type', 'status', 'published_status', 'priority', 'estimated_minutes'] as $key) {
            if ($request->has($key)) {
                $fields[$key] = $data[$key] ?? null;
            }
        }

        return $fields;
    }

    /** @return list<string> */
    protected function taskTypes(): array
    {
        return array_values(array_filter((array) config('statamic-clientrooms.task_types', []), 'is_string'));
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
