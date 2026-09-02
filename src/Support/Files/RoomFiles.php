<?php

namespace Goldnead\ClientRooms\Support\Files;

use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\Models\ClientRoomFile;
use Goldnead\ClientRooms\Support\Brands;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\URL;
use InvalidArgumentException;
use RuntimeException;
use Statamic\Assets\Asset as StatamicAsset;
use Statamic\Assets\AssetContainer as StatamicContainer;
use Statamic\Contracts\Assets\AssetContainer as ContainerContract;
use Statamic\Facades\Asset;
use Statamic\Facades\AssetContainer;

/**
 * The documents of a room, as Statamic assets.
 *
 * One container per brand, one folder per room inside it. A container is the
 * unit Statamic grants asset permissions on, so a multi-brand host can give a
 * brand's staff their own container and no other; on a single-brand install
 * there is exactly one. The container is created by `clientrooms:install` and
 * again, if missing, at the first upload, so a brand added later is not a
 * silent upload into nowhere.
 */
class RoomFiles
{
    /** The handle for a brand: the configured base, suffixed on a multi-brand install. */
    public function containerHandle(int $brandId = Brands::NONE): string
    {
        $base = (string) config('statamic-clientrooms.container', 'clientrooms');

        if ($brandId === Brands::NONE || ! Brands::multiBrand()) {
            return $base;
        }

        return $base.'-'.$brandId;
    }

    public function containerHandleFor(ClientRoom $room): string
    {
        return $this->containerHandle((int) $room->brand_id);
    }

    public function container(int $brandId = Brands::NONE): ?ContainerContract
    {
        return AssetContainer::findByHandle($this->containerHandle($brandId));
    }

    /**
     * Create the container for a brand when it does not exist. Returns
     * whether it was created now.
     */
    public function ensureContainer(int $brandId = Brands::NONE): bool
    {
        if ($this->container($brandId) !== null) {
            return false;
        }

        $title = __('statamic-clientrooms::messages.container_title');

        if (($label = Brands::label($brandId)) !== null) {
            $title .= ' · '.$label;
        }

        $container = AssetContainer::make($this->containerHandle($brandId));
        $container->title($title);

        // `disk()` is on the class, not on the contract the facade promises.
        if ($container instanceof StatamicContainer) {
            $container->disk((string) config('statamic-clientrooms.disk', 'local'));
        }

        $container->save();

        return true;
    }

    /**
     * Every brand that needs a container: the base one, plus one per brand on
     * a multi-brand install.
     *
     * @return list<int>
     */
    public function brandIds(): array
    {
        return array_values(array_unique([Brands::NONE, ...Brands::ids()]));
    }

    public function folderFor(ClientRoom $room): string
    {
        return 'room-'.$room->id;
    }

    /**
     * The file extensions a room accepts, lower-cased, from config.
     *
     * @return list<string>
     */
    public function allowedExtensions(): array
    {
        $configured = (array) config('statamic-clientrooms.allowed_extensions', []);

        return array_values(array_unique(array_map(
            fn ($ext) => ltrim(mb_strtolower(trim((string) $ext)), '.'),
            array_filter($configured, fn ($ext) => is_string($ext) && trim($ext) !== ''),
        )));
    }

    public function isAllowed(UploadedFile $file): bool
    {
        $allowed = $this->allowedExtensions();

        if ($allowed === []) {
            return true;
        }

        return in_array(mb_strtolower((string) $file->getClientOriginalExtension()), $allowed, true);
    }

    public function attach(ClientRoom $room, UploadedFile $file, ?string $title = null, bool $visibleToClient = true, ?string $uploadedBy = null): ClientRoomFile
    {
        if (! $this->isAllowed($file)) {
            throw new InvalidArgumentException(__('statamic-clientrooms::messages.file_type_refused', [
                'extensions' => implode(', ', $this->allowedExtensions()),
            ]));
        }

        $brandId = (int) $room->brand_id;

        $this->ensureContainer($brandId);
        $container = $this->container($brandId);

        if ($container === null) {
            throw new RuntimeException(sprintf(
                'statamic-clientrooms: the asset container [%s] could not be created. Run `php please clientrooms:install`.',
                $this->containerHandle($brandId),
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
     * window, so a link pasted somewhere stops working on its own. It is a
     * bearer link: whoever holds it within the window may download.
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
