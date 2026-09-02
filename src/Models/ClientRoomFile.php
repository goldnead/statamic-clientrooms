<?php

namespace Goldnead\ClientRooms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Statamic\Assets\Asset as StatamicAsset;
use Statamic\Facades\Asset;

/**
 * One document shared in a room, pointing at a Statamic asset.
 *
 * @property int $id
 * @property int $room_id
 * @property string $container
 * @property string $path
 * @property string|null $title
 * @property bool $visible_to_client
 * @property string|null $uploaded_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ClientRoomFile extends Model
{
    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'visible_to_client' => 'boolean',
        ];
    }

    /** @return BelongsTo<ClientRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ClientRoom::class, 'room_id');
    }

    /**
     * The asset behind the row, or null when it has been removed from the
     * container. The concrete class rather than the contract: the contract
     * declares neither `size()` nor `delete()`, and every asset is one.
     */
    public function asset(): ?StatamicAsset
    {
        $asset = Asset::find($this->container.'::'.$this->path);

        return $asset instanceof StatamicAsset ? $asset : null;
    }

    public function displayTitle(): string
    {
        return $this->title !== null && trim($this->title) !== '' ? $this->title : basename($this->path);
    }
}
