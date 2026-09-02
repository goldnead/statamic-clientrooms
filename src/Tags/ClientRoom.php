<?php

namespace Goldnead\ClientRooms\Tags;

use Goldnead\ClientRooms\ClientRoomsManager;
use Goldnead\ClientRooms\Models\ClientRoom as Room;
use Goldnead\ClientRooms\Models\ClientRoomFile;
use Goldnead\ClientRooms\Models\ClientRoomSession;
use Goldnead\ClientRooms\Models\ClientRoomTask;
use Goldnead\ClientRooms\Models\ClientRoomTaskSubmission;
use Goldnead\ClientRooms\Models\ClientRoomTaskSubmissionFile;
use Goldnead\ClientRooms\Support\Owners;
use Statamic\Facades\User;
use Statamic\Tags\Tags;

/**
 * `{{ client_room }}` — the signed-in user's room, for the members area.
 *
 * Finds the room by the current Statamic user's e-mail address; nothing else
 * can address it, so a template cannot render somebody else's. Yields only
 * what the client may see: `client_notes` under `notes_for_client`, files
 * marked visible with a signed URL each, and the tasks the coach has
 * published. The coach's own `notes` never leave the Control Panel, and
 * neither does a task still on `draft` or put away on `archived`. Nobody
 * signed in, no room, or a closed room: `no_results`.
 */
class ClientRoom extends Tags
{
    protected static $handle = 'client_room';

    public function __construct(protected ClientRoomsManager $rooms) {}

    /**
     * {{ client_room }} … {{ /client_room }}
     *
     * @return array<string, mixed>
     */
    public function index(): array
    {
        $room = $this->room();

        if ($room === null) {
            // An empty array is what makes Antlers parse the block once with
            // `no_results` set, the way every core tag pair behaves.
            return [];
        }

        // Every free-text value is HTML-escaped here, once. Names, titles and
        // notes are typed by staff and by whatever a payment webhook carried,
        // and Antlers does not escape on output. A template that wants to
        // escape again should use `sanitize:0` (double_encode off) so the
        // entities are not encoded twice.
        return [
            'id' => $room->id,
            'name' => e($room->displayName()),
            'email' => e($room->email),
            'status' => $room->status,
            'opened_at' => $room->opened_at,
            'owner_name' => ($owner = Owners::label($room->owner_user_id)) !== null ? e($owner) : null,
            'notes_for_client' => $room->client_notes !== null ? e($room->client_notes) : null,
            // `published()` is the line. A task the coach is still writing, or
            // one put away as archived, is not in this list at all — not
            // greyed out, not flagged, absent. The template cannot leak what
            // it never receives.
            'tasks' => $room->tasks()->published()->with('submissions.files')->get()->map(fn (ClientRoomTask $task): array => [
                'id' => $task->id,
                'title' => e($task->title),
                'description' => $task->description !== null ? e($task->description) : null,
                'type' => $task->type !== null ? e($task->type) : null,
                'priority' => $task->priority !== null ? e($task->priority) : null,
                'status' => $task->workflowStatus(),
                'estimated_minutes' => $task->estimated_minutes,
                'due_at' => $task->due_at,
                'done' => $task->isDone(),
                'done_at' => $task->done_at,
                'overdue' => $task->isOverdue(),
                'submitted' => $task->submissions->isNotEmpty(),
                'submission_count' => $task->submissions->count(),
                // The client's own work, handed back to them. No visibility
                // switch here as there is on a document: they wrote it.
                'submissions' => $task->submissions->map(fn (ClientRoomTaskSubmission $submission): array => [
                    'id' => $submission->id,
                    'body' => $submission->body !== null ? e($submission->body) : null,
                    'submitted_at' => $submission->handedInAt(),
                    'files' => $submission->files->map(fn (ClientRoomTaskSubmissionFile $file): array => [
                        'id' => $file->id,
                        'filename' => e($file->filename()),
                        'size' => $file->size,
                        'url' => $this->rooms->submissionDownloadUrl($file),
                    ])->values()->all(),
                ])->values()->all(),
            ])->values()->all(),
            // Same line as the tasks above, for the same reason: a sitting the
            // coach has not published is absent, not hidden. `notes` is not in
            // the shape at all — that column is the coach's desk, and this tag
            // renders into the client's page.
            //
            // The links come from the accessors, so one whose signature has
            // run out arrives as null and the template shows no button. The
            // `has_*` flags still say the recording exists, which is what lets
            // a page say "ask your coach" instead of nothing at all.
            'sessions' => $room->sessions()->published()->get()->map(fn (ClientRoomSession $session): array => [
                'id' => $session->id,
                'title' => e($session->title),
                'held_at' => $session->held_at,
                'duration_minutes' => $session->duration_minutes,
                'status' => $session->status !== null ? e($session->status) : null,
                'agenda' => $session->agenda !== null ? e($session->agenda) : null,
                'summary' => $session->summary !== null ? e($session->summary) : null,
                'protocol' => $session->protocol !== null ? e($session->protocol) : null,
                'coach_name' => $session->coach_name !== null ? e($session->coach_name) : null,
                'has_protocol' => $session->hasProtocol(),
                'has_recording' => $session->hasRecording(),
                'recording_url' => $session->recordingUrl(),
                'has_transcript' => $session->hasTranscript(),
                'transcript_url' => $session->transcriptUrl(),
            ])->values()->all(),
            'files' => $room->files()->where('visible_to_client', true)->get()->map(fn (ClientRoomFile $file): array => [
                'id' => $file->id,
                'title' => e($file->displayTitle()),
                'filename' => e(basename($file->path)),
                'url' => $this->rooms->downloadUrl($file),
                'uploaded_at' => $file->created_at,
            ])->values()->all(),
        ];
    }

    /** {{ if {client_room:exists} }} — whether the signed-in user has an open room. */
    public function exists(): bool
    {
        return $this->room() !== null;
    }

    protected function room(): ?Room
    {
        $room = $this->rooms->forUser(User::current());

        return $room !== null && $room->isOpen() ? $room : null;
    }
}
