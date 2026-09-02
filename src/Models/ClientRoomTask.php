<?php

namespace Goldnead\ClientRooms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    protected $guarded = [];

    protected function casts(): array
    {
        return [
            'due_at' => 'datetime',
            'done_at' => 'datetime',
            'position' => 'integer',
            'estimated_minutes' => 'integer',
        ];
    }

    /** @return BelongsTo<ClientRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ClientRoom::class, 'room_id');
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
