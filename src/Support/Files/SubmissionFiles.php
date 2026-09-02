<?php

namespace Goldnead\ClientRooms\Support\Files;

use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\Models\ClientRoomTaskSubmission;
use Goldnead\ClientRooms\Models\ClientRoomTaskSubmissionFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use RuntimeException;
use Statamic\Assets\Asset as StatamicAsset;
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
        if (! $this->rooms->isAllowed($file)) {
            throw new InvalidArgumentException(__('statamic-clientrooms::messages.file_type_refused', [
                'extensions' => implode(', ', $this->rooms->allowedExtensions()),
            ]));
        }

        $brandId = (int) $room->brand_id;

        $this->rooms->ensureContainer($brandId);
        $container = $this->rooms->container($brandId);

        if ($container === null) {
            throw new RuntimeException(sprintf(
                'statamic-clientrooms: the asset container [%s] could not be created. Run `php please clientrooms:install`.',
                $this->rooms->containerHandle($brandId),
            ));
        }

        $asset = Asset::make();

        if (! $asset instanceof StatamicAsset) {
            throw new RuntimeException('statamic-clientrooms: the asset repository handed back something that is not an asset.');
        }

        // Read before the upload: afterwards the file has been moved out from
        // under the `UploadedFile`, and asking the fresh asset gives 0 on a
        // disk that has not caught up. The size is recorded, not derived.
        $size = (int) $file->getSize();

        $asset->container($container);
        $asset->path($this->folderFor($room, $submission).'/'.$file->getClientOriginalName());

        // `upload()` writes, deduplicates the name and saves; the path
        // afterwards is the one that exists. Checked on disk rather than by
        // return value, because an `AssetCreating` listener may cancel it and
        // then there is nothing to record.
        $asset->upload($file);

        if (! $asset->exists()) {
            throw new RuntimeException('statamic-clientrooms: the upload was refused by an AssetCreating listener.');
        }

        return $submission->files()->create([
            'container' => $container->handle(),
            'path' => $asset->path(),
            'size' => $size,
        ]);
    }

    /** Remove the row and the asset behind it. */
    public function remove(ClientRoomTaskSubmissionFile $file): void
    {
        $asset = $file->asset();

        if ($asset !== null) {
            $asset->delete();
        }

        $file->delete();
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
