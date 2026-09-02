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

    /**
     * Written up: there is something to **read**, not merely something in the
     * column.
     *
     * Asked of the text rather than of the markup, because `<p></p>` is a
     * column that holds something and a screen that holds nothing. A template
     * branching on this would otherwise render an empty block under a heading.
     */
    public function hasProtocol(): bool
    {
        return $this->protocolText() !== null;
    }

    /**
     * The write-up as text, for everywhere that must not print markup.
     *
     * `protocol` holds **HTML**. It is generated by the system that ran the
     * sitting, not typed here, and it arrives with headings, paragraphs and
     * emphasis because that is what a written-up hour looks like. Two readers
     * cannot take it as it is: Antlers prints into a document and does not
     * escape on output, and the Control Panel is a screen a superuser is
     * signed in to. Neither should inject markup this addon did not author.
     *
     * Escaping it instead would be safe and unreadable — the client would see
     * `<h3>` spelled out. So the block tags become the line breaks they stood
     * for and everything else goes. What is left is text, and text is what
     * those two readers print. A template that trusts the sending system and
     * wants the formatting can still have `protocol` itself.
     */
    public function protocolText(): ?string
    {
        if ($this->protocol === null) {
            return null;
        }

        return self::htmlToText($this->protocol);
    }

    /**
     * The write-up as headings and paragraphs, still without a tag in sight.
     *
     * `protocolText()` is safe and, for a written-up hour, slightly unreadable:
     * a generated protocol has subheadings, and flattened to text they sit in
     * the same size and weight as the sentences under them, so the longest
     * block on the screen is the one that runs together. This keeps the one
     * distinction that carries the structure — heading or not — and throws the
     * rest away. A screen renders each block into its own element, so what
     * arrives is still text and the markup is the screen's own.
     *
     * @return list<array{type: 'heading'|'text', text: string}>
     */
    public function protocolBlocks(): array
    {
        if (! $this->hasProtocol()) {
            return [];
        }

        $parts = preg_split(
            '#(<h[1-6]\b[^>]*>.*?</h[1-6]\s*>)#is',
            (string) $this->protocol,
            -1,
            PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY,
        );

        if ($parts === false) {
            // A protocol the splitter cannot read is still a protocol. One
            // block of text beats no protocol at all.
            $text = $this->protocolText();

            return $text === null ? [] : [['type' => 'text', 'text' => $text]];
        }

        $blocks = [];

        foreach ($parts as $part) {
            $text = self::htmlToText($part);

            if ($text === null) {
                continue;
            }

            $blocks[] = [
                'type' => preg_match('#^\s*<h[1-6]\b#i', $part) === 1 ? 'heading' : 'text',
                'text' => $text,
            ];
        }

        return $blocks;
    }

    /**
     * Markup out, line breaks where the blocks were.
     *
     * Not a sanitiser and not trying to be one: nothing survives this that a
     * browser would read as a tag, because everything between `<` and `>` is
     * gone before the entities are decoded. The order matters — decoding first
     * would turn `&lt;script&gt;` into a tag that the stripping already ran
     * past.
     */
    private static function htmlToText(string $html): ?string
    {
        // A bare `<` first, before anything reads the string as markup.
        //
        // `strip_tags` treats `3<5 Minuten` as the start of a tag and eats
        // everything to the next `>` — or to the end, if there is none. A
        // protocol reading "Wir haben 3<5 Minuten geübt und danach …" came out
        // as "Wir haben 3", silently, in the Control Panel and on the client's
        // page. The write-up is generated by another system and nothing
        // obliges it to encode its own text.
        //
        // Asking "is a letter next?" is not enough: in "A<B und <strong>fett"
        // the offending `<` *is* followed by a letter, and that one cost the
        // whole sentence. So the question is whether a **complete, plausible
        // tag** follows — a name, optional attributes that contain no further
        // `<`, and a closing `>`. Everything else becomes an entity and lives.
        // `<!` stays as it is so comments still strip rather than surfacing as
        // text.
        $html = preg_replace(
            '/<(?!(\/?[a-zA-Z][a-zA-Z0-9-]*(\s[^<>]*)?\/?>|!))/',
            '&lt;',
            $html,
        ) ?? $html;

        $text = preg_replace('#<(br|/p|/div|/li|/h[1-6]|/tr|/td|/th)\b[^>]*>#i', "\n", $html) ?? $html;
        $text = strip_tags($text);
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Three blank lines in the source should not become three on screen.
        $text = trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);

        return $text === '' ? null : $text;
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
