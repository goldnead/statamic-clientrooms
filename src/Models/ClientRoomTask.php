<?php

namespace Goldnead\ClientRooms\Models;

use Goldnead\ClientRooms\Support\Files\SubmissionFiles;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One thing to do in a room.
 *
 * Two fields look like they say the same thing and do not. `done_at` is the
 * truth about whether the work is done — one timestamp, set once, ticked in
 * the Control Panel. `status` is the workflow around it, the vocabulary a
 * coach uses out loud: not started, being worked on, called off. The manager
 * keeps the two from drifting apart: ticking sets `completed`, unticking takes
 * it back to `assigned`. A screen should read `workflowStatus()`, because
 * `overdue` is a fact about the clock and never sits in a column.
 *
 * `published_status` is the line between the coach's desk and the client's
 * room. Only `published` leaves the Control Panel.
 *
 * @property int $id
 * @property int $room_id
 * @property string $title
 * @property string|null $description
 * @property string|null $type
 * @property string|null $status
 * @property string $published_status
 * @property string|null $priority
 * @property int|null $estimated_minutes
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $due_at
 * @property Carbon|null $done_at
 * @property string|null $done_by
 * @property string|null $created_by
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ClientRoomTask extends Model
{
    public const STATUS_ASSIGNED = 'assigned';

    public const STATUS_IN_PROGRESS = 'in-progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_OVERDUE = 'overdue';

    public const STATUS_CANCELLED = 'cancelled';

    public const PUBLISHED_DRAFT = 'draft';

    public const PUBLISHED_PUBLISHED = 'published';

    public const PUBLISHED_ARCHIVED = 'archived';

    /**
     * `in-progress` carries a hyphen. It is the wire value of the system these
     * tasks are imported from, and a task that travels there and back has to
     * come home spelled the same way.
     *
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_ASSIGNED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_COMPLETED,
        self::STATUS_OVERDUE,
        self::STATUS_CANCELLED,
    ];

    /** @var list<string> */
    public const PUBLISHED_STATUSES = [
        self::PUBLISHED_DRAFT,
        self::PUBLISHED_PUBLISHED,
        self::PUBLISHED_ARCHIVED,
    ];

    /** @var list<string> */
    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    /**
     * Named rather than `$guarded = []`, so that the whitelist in
     * `ClientRoomsManager::taskFields()` is not the only thing standing between
     * a request and a column. `room_id` is set by the relation, never by an
     * array; `done_at` and `done_by` are written with `forceFill()` where the
     * tick happens, and belong to nobody else.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'description',
        'type',
        'status',
        'published_status',
        'priority',
        'estimated_minutes',
        'meta',
        'due_at',
        'created_by',
        'position',
    ];

    /**
     * A task taken away takes its submissions with it — and, crucially, the
     * files on disk. The database cascade removes the rows and would leave the
     * assets sitting in the container with nothing pointing at them, which is
     * a client's own recording left behind after the reason for keeping it is
     * gone.
     *
     * Model events do not fire on a mass delete (`query()->delete()`); a caller
     * doing that is responsible for the assets itself.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $task): void {
            $files = app(SubmissionFiles::class);

            foreach ($task->submissions()->with('files')->get() as $submission) {
                $files->removeFor($submission);
            }
        });
    }

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'done_at' => 'datetime',
            'position' => 'integer',
            'estimated_minutes' => 'integer',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<ClientRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ClientRoom::class, 'room_id');
    }

    /**
     * What the client handed back, oldest first, so the last one read is the
     * current attempt.
     *
     * @return HasMany<ClientRoomTaskSubmission, $this>
     */
    public function submissions(): HasMany
    {
        return $this->hasMany(ClientRoomTaskSubmission::class, 'task_id')->orderBy('id');
    }

    public function isSubmitted(): bool
    {
        return $this->submissions()->exists();
    }

    public function isDone(): bool
    {
        return $this->done_at !== null;
    }

    public function isOverdue(): bool
    {
        return ! $this->isDone() && $this->due_at !== null && $this->due_at->isPast();
    }

    public function isPublished(): bool
    {
        return $this->published_status === self::PUBLISHED_PUBLISHED;
    }

    public function isDraft(): bool
    {
        return $this->published_status === self::PUBLISHED_DRAFT;
    }

    /**
     * What the task is doing right now, derived rather than stored.
     *
     * Done wins over everything, because the tick is the fact. A task that was
     * called off stays cancelled and is never called overdue — nobody owes
     * work that was called off.
     */
    public function workflowStatus(): string
    {
        if ($this->isDone()) {
            return self::STATUS_COMPLETED;
        }

        if ($this->status === self::STATUS_CANCELLED) {
            return self::STATUS_CANCELLED;
        }

        if ($this->isOverdue()) {
            return self::STATUS_OVERDUE;
        }

        return $this->status ?? self::STATUS_ASSIGNED;
    }

    /**
     * What the client is allowed to see. Everything else — drafts the coach is
     * still writing, archived tasks — stays in the Control Panel.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published_status', self::PUBLISHED_PUBLISHED);
    }
}
