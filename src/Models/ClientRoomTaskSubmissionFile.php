<?php

namespace Goldnead\ClientRooms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Statamic\Assets\Asset as StatamicAsset;
use Statamic\Facades\Asset;

/**
 * One file the client attached to a submission.
 *
 * Deliberately not a `ClientRoomFile`. That table is what the coach shared and
 * carries a `visible_to_client` switch; this is the client's own work coming
 * the other way, and there is no switch to operate on it. Two tables, so that
 * neither list can accidentally show the other's contents.
 *
 * @property int $id
 * @property int $submission_id
 * @property string $container
 * @property string $path
 * @property int $size
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ClientRoomTaskSubmissionFile extends Model
{
    protected $table = 'client_room_task_submission_files';

    /** @var list<string> */
    protected $fillable = ['container', 'path', 'size'];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /** @return BelongsTo<ClientRoomTaskSubmission, $this> */
    public function submission(): BelongsTo
    {
        return $this->belongsTo(ClientRoomTaskSubmission::class, 'submission_id');
    }

    /**
     * The asset behind the row, or null once it has left the container. The
     * concrete class rather than the contract, for the reason `ClientRoomFile`
     * gives: the contract declares neither `size()` nor `delete()`.
     */
    public function asset(): ?StatamicAsset
    {
        $asset = Asset::find($this->container.'::'.$this->path);

        return $asset instanceof StatamicAsset ? $asset : null;
    }

    public function filename(): string
    {
        return basename($this->path);
    }
}
