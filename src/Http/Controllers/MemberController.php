<?php

namespace Goldnead\ClientRooms\Http\Controllers;

use Goldnead\ClientRooms\ClientRoomsManager;
use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\Models\ClientRoomFile;
use Goldnead\ClientRooms\Models\ClientRoomTask;
use Goldnead\ClientRooms\Models\ClientRoomTaskSubmission;
use Goldnead\ClientRooms\Models\ClientRoomTaskSubmissionFile;
use Goldnead\ClientRooms\Support\Owners;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Validation\ValidationException;
use Statamic\Facades\User;

/**
 * The client's own room, as JSON, for a members area that is not Antlers.
 *
 * The tag renders a room into a page; this hands the same room to a front end
 * that renders itself. Both answer one question — *your* room — and neither
 * takes an id for it: the room is found from the signed-in user's address, so
 * there is no parameter anybody could change into somebody else's. A task id
 * is checked against that room before anything happens to it, and a task the
 * coach has not published cannot be addressed at all, exactly as in the tag.
 *
 * Values here are **not** HTML-escaped, unlike the tag's. Antlers prints into
 * a document and does not escape on output; JSON is data, escaped by whoever
 * renders it. Escaping here would send `&amp;` to a front end that then shows
 * it to the client literally.
 */
class MemberController extends Controller
{
    public function __construct(protected ClientRoomsManager $rooms) {}

    /** Everything the client may see, in one request. */
    public function show(): JsonResponse
    {
        $room = $this->room();

        return response()->json([
            'room' => [
                'id' => $room->id,
                'name' => $room->displayName(),
                'email' => $room->email,
                'status' => $room->status,
                'opened_at' => $room->opened_at?->toIso8601String(),
                'owner_name' => Owners::label($room->owner_user_id),
                'notes_for_client' => $room->client_notes,
            ],
            'tasks' => $this->tasks($room),
            'files' => $room->files()
                ->where('visible_to_client', true)
                ->get()
                ->map(fn (ClientRoomFile $file): array => [
                    'id' => $file->id,
                    'title' => $file->displayTitle(),
                    'filename' => basename($file->path),
                    'url' => $this->rooms->downloadUrl($file),
                    'uploaded_at' => $file->created_at?->toIso8601String(),
                ])->values()->all(),
        ]);
    }

    /** Tick a task, or take the tick back. */
    public function update(Request $request, int $task): JsonResponse
    {
        $room = $this->room();
        $task = $this->task($room, $task);

        $data = $request->validate(['done' => ['required', 'boolean']]);

        // `Owners::resolveId()` would refuse this: it only knows staff. The
        // client is not staff, and the id recorded here is theirs.
        $by = (string) User::current()?->getAuthIdentifier();

        if ($data['done']) {
            $this->rooms->completeTask($task, $by);
        } else {
            $this->rooms->reopenTask($task);
        }

        return response()->json(['task' => $this->presentTask($task->refresh()->load('submissions.files'))]);
    }

    /** Hand a task back, with text, files, or both. */
    public function submit(Request $request, int $task): JsonResponse
    {
        $room = $this->room();
        $task = $this->task($room, $task);

        $extensions = $this->rooms->files()->allowedExtensions();

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:65535'],
            'files' => ['nullable', 'array', 'max:10'],
            // An empty `allowed_extensions` accepts anything, so the rule is
            // left out entirely rather than passed an empty list, which
            // `mimes:` would read as "accept nothing".
            'files.*' => $extensions === []
                ? ['file', 'max:'.$this->maxUploadKilobytes()]
                : ['file', 'max:'.$this->maxUploadKilobytes(), 'mimes:'.implode(',', $extensions)],
        ]);

        $files = array_values($request->file('files') ?? []);

        if (($data['body'] ?? null) === null && $files === []) {
            // The rule the facade enforces, said in the language of a form so
            // the front end can show it on the field instead of as a crash.
            throw ValidationException::withMessages([
                'body' => __('statamic-clientrooms::messages.submission_empty'),
            ]);
        }

        $submission = $this->rooms->submitTask(
            $task,
            $data['body'] ?? null,
            $files,
            (string) User::current()?->getAuthIdentifier(),
        );

        return response()->json(['submission' => $this->presentSubmission($submission)], 201);
    }

    /**
     * The signed-in user's open room, or a 404.
     *
     * Never an id from the request. A closed room is gone as far as the
     * members area is concerned, the same as no room at all.
     */
    protected function room(): ClientRoom
    {
        $room = $this->rooms->forUser(User::current());

        abort_unless($room !== null && $room->isOpen(), 404);

        return $room;
    }

    /**
     * One task of that room, published.
     *
     * A draft is not merely kept off the list, it cannot be addressed: a
     * client who guesses the id of something the coach is still writing gets
     * the same 404 as for a task that was never there.
     */
    protected function task(ClientRoom $room, int $task): ClientRoomTask
    {
        return $room->tasks()->published()->findOrFail($task);
    }

    /** @return list<array<string, mixed>> */
    protected function tasks(ClientRoom $room): array
    {
        return $room->tasks()
            ->published()
            ->with('submissions.files')
            ->get()
            ->map(fn (ClientRoomTask $task): array => $this->presentTask($task))
            ->values()
            ->all();
    }

    /** @return array<string, mixed> */
    protected function presentTask(ClientRoomTask $task): array
    {
        return [
            'id' => $task->id,
            'title' => $task->title,
            'description' => $task->description,
            'type' => $task->type,
            'priority' => $task->priority,
            'status' => $task->workflowStatus(),
            'estimated_minutes' => $task->estimated_minutes,
            'due_at' => $task->due_at?->toIso8601String(),
            'done' => $task->isDone(),
            'done_at' => $task->done_at?->toIso8601String(),
            'overdue' => $task->isOverdue(),
            'submissions' => $task->submissions
                ->map(fn (ClientRoomTaskSubmission $submission): array => $this->presentSubmission($submission))
                ->values()
                ->all(),
        ];
    }

    /** @return array<string, mixed> */
    protected function presentSubmission(ClientRoomTaskSubmission $submission): array
    {
        return [
            'id' => $submission->id,
            'body' => $submission->body,
            'submitted_at' => $submission->handedInAt()?->toIso8601String(),
            'files' => $submission->files->map(fn (ClientRoomTaskSubmissionFile $file): array => [
                'id' => $file->id,
                'filename' => $file->filename(),
                'size' => $file->size,
                // A link that expires, never the path it points at.
                'url' => $this->rooms->submissionDownloadUrl($file),
            ])->values()->all(),
        ];
    }

    protected function maxUploadKilobytes(): int
    {
        return max(1, (int) config('statamic-clientrooms.member_upload_max_kb', 51200));
    }
}
