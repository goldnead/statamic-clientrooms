<?php

namespace Goldnead\ClientRooms\Support\Files;

use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\Models\ClientRoomFile;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use RuntimeException;
use Statamic\Assets\Asset as StatamicAsset;
use Statamic\Assets\AssetContainer as StatamicContainer;
use Statamic\Contracts\Assets\AssetContainer as ContainerContract;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

/**
 * The documents of a room, as Statamic assets in one container.
 *
 * One folder per room inside the configured container. The container is
 * created by `clientrooms:install`; a missing one is a loud error rather than
 * a silent upload into nowhere, because "the file did not arrive" is the
 * failure a client notices first.
 */
class RoomFiles
{
    public function containerHandle(): string
    {
        return (string) config('statamic-clientrooms.container', 'clientrooms');
    }

    public function container(): ?ContainerContract
    {
        return AssetContainer::findByHandle($this->containerHandle());
    }

    /**
     * Create the container when it does not exist. Returns whether it was created now.
     */
    public function ensureContainer(): bool
    {
        if ($this->container() !== null) {
            return false;
        }

        $container = AssetContainer::make($this->containerHandle());
        $container->title(__('statamic-clientrooms::messages.container_title'));

        // `disk()` is on the class, not on the contract the facade promises.
        if ($container instanceof StatamicContainer) {
            $container->disk((string) config('statamic-clientrooms.disk', 'local'));
        }

        $container->save();

        return true;
    }

    public function folderFor(ClientRoom $room): string
    {
        return 'room-'.$room->id;
    }

    public function attach(ClientRoom $room, UploadedFile $file, ?string $title = null, bool $visibleToClient = true, ?string $uploadedBy = null): ClientRoomFile
    {
        $container = $this->container();

        if ($container === null) {
            throw new RuntimeException(sprintf(
                'statamic-clientrooms: the asset container [%s] does not exist. Run `php please clientrooms:install`.',
                $this->containerHandle(),
            ));
        }

        $asset = Asset::make();

        if (! $asset instanceof StatamicAsset) {
            throw new RuntimeException('statamic-clientrooms: the asset repository handed back something that is not an asset.');
        }

        $asset->container($container);
        $asset->path($this->folderFor($room).'/'.$file->getClientOriginalName());

        // `upload()` writes the file, deduplicates the name and saves the
        // asset; its path afterwards is the one that actually exists. Checked
        // on disk rather than by return value: an `AssetCreating` listener may
        // cancel it, and then there is nothing to record.
        $asset->upload($file);

        if (! $asset->exists()) {
            throw new RuntimeException('statamic-clientrooms: the upload was refused by an AssetCreating listener.');
        }

        $record = ClientRoomFile::create([
            'room_id' => $room->id,
            'container' => $container->handle(),
            'path' => $asset->path(),
            'title' => $title !== null && trim($title) !== '' ? trim($title) : $file->getClientOriginalName(),
            'visible_to_client' => $visibleToClient,
            'uploaded_by' => $uploadedBy,
        ]);

        $room->touchActivity();

        return $record;
    }

    /** Remove the row and the asset behind it. */
    public function remove(ClientRoomFile $file): void
    {
        $asset = $file->asset();

        if ($asset !== null) {
            $asset->delete();
        }

        $file->delete();
    }

    /**
     * A link the client may follow for a while, never the storage path.
     *
     * Signed with the application key and bound to the file id, so a link
     * cannot be edited into another file's; expires after the configured
     * window, so a link pasted somewhere stops working on its own.
     */
    public function signedUrl(ClientRoomFile $file): string
    {
        $minutes = max(1, (int) config('statamic-clientrooms.download_ttl_minutes', 30));

        return URL::temporarySignedRoute(
            'statamic-clientrooms.download',
            now()->addMinutes($minutes),
            ['file' => $file->id],
        );
    }
}
