<?php

namespace Goldnead\ClientRooms\Support\Files;

use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\Models\ClientRoomTaskSubmission;
use Goldnead\ClientRooms\Models\ClientRoomTaskSubmissionFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use Statamic\Facades\Asset;

/**
 * The files a client attaches to a submission.
 *
 * The same container as the room's own documents, because a container is what
 * Statamic grants asset permissions on and a client's work belongs to the same
 * brand as their room. A folder of its own inside it, so a coach opening the
 * container in the Files tool can tell at a glance what they shared from what
 * came back.
 *
 * Everything about *where* files live — which container, which disk, which
 * extensions are accepted — is `RoomFiles`' answer, asked here rather than
 * copied, so a host who changes the container in config changes it once.
 */
class SubmissionFiles
{
    public function __construct(protected RoomFiles $rooms) {}

    /** `room-12/submissions/3` — beside the room's documents, not among them. */
    public function folderFor(ClientRoom $room, ClientRoomTaskSubmission $submission): string
    {
        return $this->rooms->folderFor($room).'/submissions/'.$submission->id;
    }

    /**
     * Store one uploaded file against a submission.
     *
     * The room is passed rather than walked to: a submission reaches its room
     * through task → room, and that walk crosses the brand scope. The caller
     * has the room already.
     */
    public function attach(ClientRoom $room, ClientRoomTaskSubmission $submission, UploadedFile $file): ClientRoomTaskSubmissionFile
    {
        // Read before the upload: afterwards the file has been moved out from
        // under the `UploadedFile`, and asking the fresh asset gives 0 on a
        // disk that has not caught up. The size is recorded, not derived.
        $size = (int) $file->getSize();

        $asset = $this->rooms->storeInto($room, $file, $this->folderFor($room, $submission));

        return $submission->files()->create([
            'container' => $asset->container()->handle(),
            'path' => $asset->path(),
            'size' => $size,
        ]);
    }

    /**
     * Remove the row and the asset behind it.
     *
     * Says whether the file is really gone. An asset that refuses to delete
     * leaves a client's recording in the container, and a screen reporting
     * success over that is lying about where the recording is.
     */
    public function remove(ClientRoomTaskSubmissionFile $file): bool
    {
        $asset = $file->asset();

        if ($asset !== null) {
            $asset->delete();

            // Asked, not assumed. `delete()` hands back the asset rather than
            // a verdict, and an `AssetDeleting` listener may have cancelled
            // it. The container is the only authority on whether the file is
            // gone, so the container is what gets asked.
            if ($file->asset() !== null) {
                return false;
            }
        }

        return $file->delete() !== false;
    }

    /**
     * Everything hanging off one submission, files first.
     *
     * The database would cascade the rows on its own and leave the assets in
     * the container with nothing pointing at them — a client's own recording,
     * kept after the reason to keep it is gone.
     */
    public function removeFor(ClientRoomTaskSubmission $submission): bool
    {
        $clean = true;

        foreach ($submission->files as $file) {
            $clean = $this->remove($file) && $clean;
        }

        return $submission->delete() !== false && $clean;
    }

    /**
     * A link that expires, on a route of its own.
     *
     * Not the room-document route with a wider meaning bolted on: one URL
     * serving two tables would need a discriminator inside the signature, and
     * an id that means different things in different places is the kind of
     * thing that eventually gets confused. Same mechanism, same window, its
     * own name.
     */
    public function signedUrl(ClientRoomTaskSubmissionFile $file): string
    {
        $minutes = max(1, (int) config('statamic-clientrooms.download_ttl_minutes', 30));

        return URL::temporarySignedRoute(
            'statamic-clientrooms.submission-download',
            now()->addMinutes($minutes),
            ['file' => $file->id],
        );
    }
}
