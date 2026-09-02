<?php

namespace Goldnead\ClientRooms\Http\Controllers;

use Goldnead\ClientRooms\Models\ClientRoomTaskSubmissionFile;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * A submission file, behind a signed and expiring URL.
 *
 * The sibling of `DownloadController`, and different in one way worth saying
 * out loud: a room document has a `visible_to_client` switch and this has
 * none. The client uploaded this file; there is no state in which they may not
 * read their own work back. What is still checked is the room — a closed room
 * hands out nothing, the same way it hands out no documents.
 *
 * 404 rather than 403 for both refusals, matching `DownloadController`: a 403
 * would confirm the file is there, and a link that has stopped working should
 * not also be an existence oracle.
 */
class SubmissionDownloadController extends Controller
{
    public function __invoke(Request $request, int $file)
    {
        $file = ClientRoomTaskSubmissionFile::query()
            ->with(['submission.task.room' => fn ($q) => $q->withoutGlobalScopes()])
            ->findOrFail($file);

        $room = $file->submission?->task?->room;

        abort_unless($room !== null && $room->isOpen(), 404);

        $asset = $file->asset();

        abort_if($asset === null, 404);

        return $asset->download($file->filename(), [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
