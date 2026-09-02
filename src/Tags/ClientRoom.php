<?php

namespace Goldnead\ClientRooms\Tags;

use Goldnead\ClientRooms\ClientRoomsManager;
use Goldnead\ClientRooms\Models\ClientRoom as Room;
use Goldnead\ClientRooms\Models\ClientRoomFile;
use Goldnead\ClientRooms\Models\ClientRoomTask;
use Goldnead\ClientRooms\Support\Owners;
use Statamic\Facades\User;
use Statamic\Tags\Tags;

/**
 * `{{ client_room }}` — the signed-in user's room, for the members area.
 *
 * Finds the room by the current Statamic user's e-mail address; nothing else
 * can address it, so a template cannot render somebody else's. Yields only
 * what the client may see: `client_notes` under `notes_for_client`, files
 * marked visible with a signed URL each, and the tasks. The coach's own
 * `notes` never leave the Control Panel. Nobody signed in, no room, or a
 * closed room: `no_results`.
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

        return [
            'id' => $room->id,
            'name' => $room->displayName(),
            'email' => $room->email,
            'status' => $room->status,
            'opened_at' => $room->opened_at,
            'owner_name' => Owners::label($room->owner_user_id),
            'notes_for_client' => $room->client_notes,
            'tasks' => $room->tasks()->get()->map(fn (ClientRoomTask $task): array => [
                'id' => $task->id,
                'title' => $task->title,
                'due_at' => $task->due_at,
                'done' => $task->isDone(),
                'done_at' => $task->done_at,
            ])->values()->all(),
            'files' => $room->files()->where('visible_to_client', true)->get()->map(fn (ClientRoomFile $file): array => [
                'id' => $file->id,
                'title' => $file->displayTitle(),
                'filename' => basename($file->path),
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
