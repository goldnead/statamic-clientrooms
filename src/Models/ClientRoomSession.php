<?php

namespace Goldnead\ClientRooms\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One sitting that happened in a room.
 *
 * The room's third noun, next to tasks and documents: not what is owed and not
 * what was handed over, but what took place — the hour itself, the write-up,
 * and the way back to the recording.
 *
 * **Two fields look alike and are not.** `status` is where the sitting stands
 * in the coach's own workflow — scheduled, being processed, done, called off.
 * `published_status` is the line between the coach's desk and the client's
 * room, exactly as on a task: only `published` leaves the Control Panel. A
 * sitting can be `completed` for weeks and still be a draft, because the
 * write-up is not finished.
 *
 * **The link fields carry an expiry, and reading them without it is a bug.**
 * A cockpit that hands out recordings mints a signed URL per request and lets
 * it die in a few hours. Stored bare, such a URL turns into a link that used
 * to work — the worst kind, because it looks like a link. `recordingUrl()` and
 * `transcriptUrl()` return null once the moment has passed, so a reader can
 * tell "there is nothing" from "this needs minting again", and `hasRecording()`
 * answers the second question on its own.
 *
 * @property int $id
 * @property int $room_id
 * @property string|null $external_id
 * @property string $title
 * @property Carbon|null $held_at
 * @property int|null $duration_minutes
 * @property string|null $status
 * @property string $published_status
 * @property string|null $agenda
 * @property string|null $summary
 * @property string|null $protocol
 * @property string|null $notes
 * @property string|null $recording_url
 * @property Carbon|null $recording_url_expires_at
 * @property bool $has_transcript
 * @property string|null $transcript_url
 * @property Carbon|null $transcript_url_expires_at
 * @property string|null $coach_name
 * @property array<string, mixed>|null $meta
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class ClientRoomSession extends Model
{
    public const STATUS_SCHEDULED = 'scheduled';

    public const STATUS_IN_PROGRESS = 'in-progress';

    public const STATUS_PROCESSING = 'processing';

    public const STATUS_REVIEW_READY = 'review-ready';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const STATUS_NO_SHOW = 'no-show';

    public const PUBLISHED_DRAFT = 'draft';

    public const PUBLISHED_PUBLISHED = 'published';

    public const PUBLISHED_ARCHIVED = 'archived';

    /**
     * The hyphens are not a style choice. These are the wire values of the
     * cockpit these sittings come from, and one that travels there and back
     * has to come home spelled the same way.
     *
     * The list is what the Control Panel offers, not what the column accepts:
     * an import brings years of vocabulary with it and must not lose a sitting
     * to a list somebody wrote later.
     *
     * @var list<string>
     */
    public const STATUSES = [
        self::STATUS_SCHEDULED,
        self::STATUS_IN_PROGRESS,
        self::STATUS_PROCESSING,
        self::STATUS_REVIEW_READY,
        self::STATUS_COMPLETED,
        self::STATUS_CANCELLED,
        self::STATUS_NO_SHOW,
    ];

    /** @var list<string> */
    public const PUBLISHED_STATUSES = [
        self::PUBLISHED_DRAFT,
        self::PUBLISHED_PUBLISHED,
        self::PUBLISHED_ARCHIVED,
    ];

    /** The ceiling of the `unsignedInteger` column behind `duration_minutes`. */
    public const MAX_DURATION_MINUTES = 4294967295;

    /**
     * Named rather than `$guarded = []`, like the task model, so the whitelist
     * in `ClientRoomsManager::sessionFields()` is not the only thing between a
     * request and a column.
     *
     * `room_id` is set by the relation and never by an array. `external_id` is
     * missing on purpose: it is the identity of the row, written once where
     * the sitting is first recorded, and a later `fill()` that moved it would
     * silently make one sitting into another.
     *
     * @var list<string>
     */
    protected $fillable = [
        'title',
        'held_at',
        'duration_minutes',
        'status',
        'published_status',
        'agenda',
        'summary',
        'protocol',
        'notes',
        'recording_url',
        'recording_url_expires_at',
        'has_transcript',
        'transcript_url',
        'transcript_url_expires_at',
        'coach_name',
        'meta',
    ];

    protected function casts(): array
    {
        return [
            'held_at' => 'datetime',
            'duration_minutes' => 'integer',
            'has_transcript' => 'boolean',
            'recording_url_expires_at' => 'datetime',
            'transcript_url_expires_at' => 'datetime',
            'meta' => 'array',
        ];
    }

    /** @return BelongsTo<ClientRoom, $this> */
    public function room(): BelongsTo
    {
        return $this->belongsTo(ClientRoom::class, 'room_id');
    }

    public function isPublished(): bool
    {
        return $this->published_status === self::PUBLISHED_PUBLISHED;
    }

    public function isDraft(): bool
    {
        return $this->published_status === self::PUBLISHED_DRAFT;
    }

    /** Written up: there is something to read, not merely something that happened. */
    public function hasProtocol(): bool
    {
        return $this->protocol !== null && trim($this->protocol) !== '';
    }

    /**
     * Whether a recording exists at all — which is a different question from
     * whether the stored link still opens it.
     *
     * A sitting whose link has expired still *has* a recording; what it lacks
     * is a valid way there. A screen that asks this one shows the row; a
     * screen that asks `recordingUrl()` decides whether to link it or to mint
     * a fresh URL first.
     */
    public function hasRecording(): bool
    {
        return $this->recording_url !== null && trim($this->recording_url) !== '';
    }

    /**
     * The recording link, or null once it is no longer worth handing out.
     *
     * A link with no expiry is taken at face value — a host that stores a
     * permanent URL means it. A link with one is over the moment the clock
     * passes it, and null is the honest answer: better a row without a button
     * than a button that 403s in the client's face.
     */
    public function recordingUrl(): ?string
    {
        return $this->liveUrl($this->recording_url, $this->recording_url_expires_at);
    }

    /** Whether a transcript exists, as reported, link or no link. */
    public function hasTranscript(): bool
    {
        return $this->has_transcript || ($this->transcript_url !== null && trim($this->transcript_url) !== '');
    }

    /** The transcript link, under the same rule as the recording's. */
    public function transcriptUrl(): ?string
    {
        return $this->liveUrl($this->transcript_url, $this->transcript_url_expires_at);
    }

    /**
     * What the client is allowed to see. Drafts the coach is still writing and
     * archived sittings stay in the Control Panel.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopePublished(Builder $query): Builder
    {
        return $query->where('published_status', self::PUBLISHED_PUBLISHED);
    }

    private function liveUrl(?string $url, ?Carbon $expiresAt): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        if ($expiresAt !== null && $expiresAt->isPast()) {
            return null;
        }

        return $url;
    }
}
