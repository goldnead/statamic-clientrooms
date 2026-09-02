<?php

namespace Goldnead\ClientRooms\Models;

use Goldnead\ClientRooms\Models\Concerns\BelongsToBrand;
use Goldnead\ClientRooms\Support\Files\RoomFiles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The relationship with one client, as a thing with state.
 *
 * @property int $id
 * @property int $brand_id
 * @property int|null $contact_id
 * @property string $email
 * @property string|null $name
 * @property string|null $owner_user_id
 * @property string $status
 * @property Carbon|null $opened_at
 * @property Carbon|null $closed_at
 * @property Carbon|null $last_activity_at
 * @property string|null $notes
 * @property string|null $client_notes
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ClientRoom extends Model
{
    use BelongsToBrand;

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    protected $guarded = [];

    /** @return list<string> */
    public static function statuses(): array
    {
        return [self::STATUS_OPEN, self::STATUS_CLOSED];
    }

    protected function casts(): array
    {
        return [
            'brand_id' => 'integer',
            'contact_id' => 'integer',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'last_activity_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /**
     * A room taken away takes its files off the disk, not just out of the
     * tables.
     *
     * The foreign keys cascade `client_rooms → client_room_tasks →
     * submissions → submission files` entirely in SQL, and SQL knows nothing
     * about the asset container. Left to the cascade, deleting one room
     * strands every document and every client recording it ever held. Each
     * task is deleted through Eloquent here so that its own hook runs.
     *
     * The addon offers no way to delete a room — rooms are closed, and closing
     * keeps everything. This is for the host who calls `delete()` anyway.
     * Model events do not fire on a mass delete (`query()->delete()`).
     */
    protected static function booted(): void
    {
        static::deleting(function (self $room): void {
            foreach ($room->tasks()->get() as $task) {
                $task->delete();
            }

            $files = app(RoomFiles::class);

            foreach ($room->files()->get() as $file) {
                $files->remove($file);
            }
        });
    }

    /** @return HasMany<ClientRoomTask, $this> */
    public function tasks(): HasMany
    {
        return $this->hasMany(ClientRoomTask::class, 'room_id')->orderBy('position')->orderBy('id');
    }

    /** @return HasMany<ClientRoomFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(ClientRoomFile::class, 'room_id')->orderByDesc('id');
    }

    public function isOpen(): bool
    {
        return $this->status === self::STATUS_OPEN;
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_OPEN);
    }

    /** Something happened in this room just now. */
    public function touchActivity(): void
    {
        $this->forceFill(['last_activity_at' => now()])->saveQuietly();
    }

    /** The name to show, falling back to the address. */
    public function displayName(): string
    {
        return $this->name !== null && trim($this->name) !== '' ? $this->name : $this->email;
    }
}
