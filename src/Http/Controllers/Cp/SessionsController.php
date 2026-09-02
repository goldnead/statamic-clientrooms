<?php

namespace Goldnead\ClientRooms\Http\Controllers\Cp;

use Goldnead\ClientRooms\ClientRoomsManager;
use Goldnead\ClientRooms\Http\Controllers\Cp\Concerns\AuthorizesRooms;
use Goldnead\ClientRooms\Models\ClientRoomSession;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Statamic\Http\Controllers\CP\CpController;

/**
 * The two things a coach decides about a sitting that already happened.
 *
 * **This screen deliberately cannot create or rewrite one.** A sitting is
 * recorded where it takes place — in the cockpit that ran it, which owns the
 * Zoom link, the recording, the transcript and the draft of the write-up. What
 * arrives here is the finished thing. Offering a form to retype the title or
 * the protocol would invite two versions of one hour, and the next import
 * would silently win.
 *
 * What is left is exactly the part that belongs to this side:
 *
 * - **`published_status`** — the line between the coach's desk and the
 *   client's room. The sending system has an opinion about it and it travels
 *   with the import; this is where it can be overruled without going back
 *   there, which is what you want at eleven at night when a write-up went out
 *   half finished.
 * - **`notes`** — the coach's own. It has no reader outside this screen, it is
 *   not in the tag's shape and not in the JSON, and no import overwrites it,
 *   because the sending system does not know it exists.
 *
 * Deleting is here too, for the row that should never have arrived. It strands
 * nothing: a sitting holds no asset of its own, only the way back to one.
 */
class SessionsController extends CpController
{
    use AuthorizesRooms;

    public function __construct(Request $request, protected ClientRoomsManager $rooms)
    {
        parent::__construct($request);
    }

    /**
     * Move the line, or write the note.
     *
     * Both keys are `sometimes`, so the publish switch does not clear a note
     * the coach spent five minutes on, and the note form does not quietly
     * republish a draft.
     */
    public function update(Request $request, int $room, int $session)
    {
        $this->authorize('edit client rooms');

        $room = $this->findRoom($room);
        $session = $this->session($room->id, $session);

        $data = $request->validate([
            // The form offers fewer words than the column accepts, on the same
            // reasoning as the task form: an import may carry a vocabulary
            // nobody here anticipated, a coach should not be able to type one.
            'published_status' => ['sometimes', 'required', Rule::in(ClientRoomSession::PUBLISHED_STATUSES)],
            'notes' => ['sometimes', 'nullable', 'string', 'max:65535'],
        ]);

        $fields = [];

        foreach (['published_status', 'notes'] as $key) {
            if ($request->has($key)) {
                $fields[$key] = $data[$key] ?? null;
            }
        }

        if ($fields !== []) {
            $this->rooms->updateSession($session, $fields);
        }

        return back()->with('success', __('statamic-clientrooms::messages.session_saved'));
    }

    public function destroy(int $room, int $session)
    {
        $this->authorize('edit client rooms');

        $room = $this->findRoom($room);
        $session = $this->session($room->id, $session);

        $this->rooms->removeSession($session);

        return back()->with('success', __('statamic-clientrooms::messages.session_deleted'));
    }

    /**
     * One sitting of that room.
     *
     * Scoped by `room_id` rather than looked up on its own, so an id belonging
     * to another room answers 404 instead of somebody else's hour.
     */
    protected function session(int $roomId, int $sessionId): ClientRoomSession
    {
        return ClientRoomSession::query()->where('room_id', $roomId)->findOrFail($sessionId);
    }
}
