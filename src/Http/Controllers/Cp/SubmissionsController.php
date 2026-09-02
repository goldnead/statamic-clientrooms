<?php

namespace Goldnead\ClientRooms\Http\Controllers\Cp;

use Goldnead\ClientRooms\ClientRoomsManager;
use Goldnead\ClientRooms\Http\Controllers\Cp\Concerns\AuthorizesRooms;
use Goldnead\ClientRooms\Models\ClientRoomTaskSubmission;
use Goldnead\ClientRooms\Models\ClientRoomTaskSubmissionFile;
use Illuminate\Http\Request;
use Statamic\Http\Controllers\CP\CpController;

/**
 * What the client handed back, seen from the coach's side.
 *
 * The client reads their own submissions through a signed link that expires.
 * Staff read them through here instead: a Control Panel route behind the
 * permission, which does not expire while somebody is working and needs no
 * link minted for a page that may sit open for an hour.
 *
 * Every lookup walks room → task → submission through the brand-scoped query,
 * so a submission id from another room — or another brand's room — is a 404
 * and not somebody else's client's recording.
 */
class SubmissionsController extends CpController
{
    use AuthorizesRooms;

    public function __construct(Request $request, protected ClientRoomsManager $rooms)
    {
        parent::__construct($request);
    }

    public function download(int $room, int $file)
    {
        $this->authorize('view client rooms');

        $room = $this->findRoom($room);
        $file = $this->file($room->id, $file);

        $asset = $file->asset();

        abort_if($asset === null, 404);

        return $asset->download($file->filename(), [
            'Cache-Control' => 'private, no-store',
        ]);
    }

    public function destroy(int $room, int $submission)
    {
        $this->authorize('edit client rooms');

        $room = $this->findRoom($room);

        // Asked, not assumed: an asset that refuses to go leaves a client's
        // recording in the container, and a green toast over that is a lie
        // about where the recording is.
        if (! $this->rooms->removeSubmission($this->submission($room->id, $submission))) {
            return back()->withErrors(['submission' => __('statamic-clientrooms::messages.submission_delete_failed')]);
        }

        $room->touchActivity();

        return back()->with('success', __('statamic-clientrooms::messages.submission_deleted'));
    }

    protected function submission(int $roomId, int $submissionId): ClientRoomTaskSubmission
    {
        return ClientRoomTaskSubmission::query()
            ->whereHas('task', fn ($q) => $q->where('room_id', $roomId))
            ->findOrFail($submissionId);
    }

    protected function file(int $roomId, int $fileId): ClientRoomTaskSubmissionFile
    {
        return ClientRoomTaskSubmissionFile::query()
            ->whereHas('submission.task', fn ($q) => $q->where('room_id', $roomId))
            ->findOrFail($fileId);
    }
}
