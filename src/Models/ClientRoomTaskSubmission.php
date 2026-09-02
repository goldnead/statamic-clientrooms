<?php

namespace Goldnead\ClientRooms\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One handing-back of a task: what the client wrote, what they uploaded.
 *
 * A task may have several. They are never edited and never overwritten — a
 * second attempt is a second submission, so the coach can see that there was
 * a first one and what changed between them.
 *
 * @property int $id
 * @property int $task_id
 * @property string|null $body
 * @property string|null $submitted_by
 * @property Carbon|null $submitted_at
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ClientRoomTaskSubmission extends Model
{
    protected $table = 'client_room_task_submissions';

    /**
     * `task_id` is set by the relation, never by an array.
     *
     * @var list<string>
     */
    protected $fillable = ['body', 'submitted_by', 'submitted_at', 'meta'];

    protected function casts(): array
    {
        return [
            'submitted_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<ClientRoomTask, $this> */
    public function task(): BelongsTo
    {
        return $this->belongsTo(ClientRoomTask::class, 'task_id');
    }

    /** @return HasMany<ClientRoomTaskSubmissionFile, $this> */
    public function files(): HasMany
    {
        return $this->hasMany(ClientRoomTaskSubmissionFile::class, 'submission_id')->orderBy('id');
    }

    /** When the work was handed in, falling back to when the row was written. */
    public function handedInAt(): ?Carbon
    {
        return $this->submitted_at ?? $this->created_at;
    }
}
