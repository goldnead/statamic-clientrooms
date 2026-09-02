<?php

namespace Goldnead\ClientRooms\Http\Controllers;

use Goldnead\ClientRooms\ClientRoomsManager;
use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\Models\ClientRoomFile;
use Goldnead\ClientRooms\Models\ClientRoomSession;
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
            'sessions' => $this->sessions($room),
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

        // Their own id, recorded as theirs. The manager resolves it and keeps
        // it either way; a client is a Statamic user like any other, and the
        // point here is only that the tick is attributed to the person who
        // made it rather than to the coach.
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
        $user = User::current();

        // 401 before 404, and said here rather than by the `auth` middleware:
        // that one decides between JSON and a redirect from the Accept header
        // as it throws, and Laravel's middleware priority puts it ahead of
        // anything that could set that header. A host without a `login` route
        // then gets an exception instead of an answer.
        abort_if($user === null, 401);

        $room = $this->rooms->forUser($user);

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

    /** @return list<array<string, mixed>> */
    protected function sessions(ClientRoom $room): array
    {
        return $room->sessions()
            ->published()
            ->get()
            ->map(fn (ClientRoomSession $session): array => $this->presentSession($session))
            ->values()
            ->all();
    }

    /**
     * One sitting, as the client may see it.
     *
     * `notes` is absent on purpose and must stay absent: that column is the
     * coach's own desk, and this is the one place where forgetting that would
     * hand it to the person it is about.
     *
     * The two links come from the model's accessors rather than the columns,
     * so an expired one arrives as null. A dead link is worse than none — it
     * looks like it works, and the client finds out otherwise. `has_recording`
     * and `has_transcript` say that something exists regardless, which is what
     * lets a front end show the row and ask for a fresh link instead of
     * pretending the hour was never recorded.
     *
     * @return array<string, mixed>
     */
    protected function presentSession(ClientRoomSession $session): array
    {
        return [
            'id' => $session->id,
            'title' => $session->title,
            'held_at' => $session->held_at?->toIso8601String(),
            'duration_minutes' => $session->duration_minutes,
            'status' => $session->status,
            'agenda' => $session->agenda,
            'summary' => $session->summary,
            // The same two names the tag uses, and for the same reason. Before
            // this, `protocol` meant plain text in one shape and raw HTML in
            // the other, which is a trap either way: a front end that escapes
            // it dutifully shows the client `<h3>` in words, and one that does
            // not renders markup from another system without ever having been
            // told that is what it was doing.
            'protocol' => $session->protocolText(),
            'protocol_html' => $session->protocol,
            'has_protocol' => $session->hasProtocol(),
            'coach_name' => $session->coach_name,
            'has_recording' => $session->hasRecording(),
            'recording_url' => $session->recordingUrl(),
            'has_transcript' => $session->hasTranscript(),
            'transcript_url' => $session->transcriptUrl(),
        ];
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
