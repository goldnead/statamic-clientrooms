<?php

namespace Goldnead\ClientRooms;

use DateTimeInterface;
use Goldnead\ClientRooms\Events\ClientRoomClosed;
use Goldnead\ClientRooms\Events\ClientRoomOpened;
use Goldnead\ClientRooms\Events\ClientRoomTaskCompleted;
use Goldnead\ClientRooms\Events\ClientRoomTaskSubmitted;
use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\Models\ClientRoomFile;
use Goldnead\ClientRooms\Models\ClientRoomTask;
use Goldnead\ClientRooms\Models\ClientRoomTaskSubmission;
use Goldnead\ClientRooms\Models\ClientRoomTaskSubmissionFile;
use Goldnead\ClientRooms\Support\Brands;
use Goldnead\ClientRooms\Support\Contacts;
use Goldnead\ClientRooms\Support\Emails;
use Goldnead\ClientRooms\Support\Files\RoomFiles;
use Goldnead\ClientRooms\Support\Files\SubmissionFiles;
use Goldnead\ClientRooms\Support\Owners;
use Goldnead\ClientRooms\Support\Timeline\RoomTimeline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use RuntimeException;
use Statamic\Contracts\Auth\User as UserContract;
use Throwable;

/**
 * The public face of the addon. Everything the site, a listener or a console
 * command does with a room goes through here, so that the rules — one room
 * per address and brand, events on transitions only — live in one place.
 */
class ClientRoomsManager
{
    /** The ceiling of the `unsignedInteger` column behind `estimated_minutes`. */
    public const MAX_ESTIMATED_MINUTES = 4294967295;

    public function __construct(
        protected RoomFiles $files,
        protected RoomTimeline $timeline,
        protected SubmissionFiles $submissionFiles,
    ) {}

    /**
     * Open a room for a person, or hand back the one they already have.
     *
     * `$who` is an e-mail address, a LeadHub contact, or any object with an
     * `email` attribute. A closed room is reopened; an open room is returned
     * untouched and no event fires. `$attributes` may carry `name`,
     * `brand_id`, `contact_id`, `meta` and `notes` for the first opening.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function open(string|object $who, ?string $ownerUserId = null, array $attributes = []): ClientRoom
    {
        [$email, $fromObject] = $this->identify($who);
        $attributes = array_replace($fromObject, $attributes);

        $brandId = array_key_exists('brand_id', $attributes) && $attributes['brand_id'] !== null
            ? (int) $attributes['brand_id']
            : Brands::stampId();

        $room = $this->find($email, $brandId);

        $owner = Owners::resolveId($ownerUserId);

        if ($room === null) {
            try {
                $room = ClientRoom::create([
                    'brand_id' => $brandId,
                    'contact_id' => $attributes['contact_id'] ?? Contacts::idFor($email, $brandId),
                    'email' => $email,
                    'name' => $attributes['name'] ?? null,
                    'owner_user_id' => $owner,
                    'status' => ClientRoom::STATUS_OPEN,
                    'opened_at' => now(),
                    'last_activity_at' => now(),
                    'notes' => $attributes['notes'] ?? null,
                    'meta' => $attributes['meta'] ?? null,
                ]);

                ClientRoomOpened::dispatch($room, false);

                return $room;
            } catch (UniqueConstraintViolationException) {
                // Two payments of one buyer, two queue workers, one moment: the
                // other one won the insert. The unique key did its job; this
                // side picks up that row and carries on as a second `open()`.
                $room = $this->find($email, $brandId);

                if ($room === null) {
                    throw new RuntimeException('statamic-clientrooms: the unique key refused the room, yet no room can be found for '.$email);
                }
            }
        }

        if ($room->isOpen()) {
            return $room;
        }

        $room->forceFill([
            'status' => ClientRoom::STATUS_OPEN,
            'opened_at' => now(),
            'closed_at' => null,
            'last_activity_at' => now(),
            'owner_user_id' => $room->owner_user_id ?? $owner,
            'name' => $room->name ?? ($attributes['name'] ?? null),
        ])->save();

        ClientRoomOpened::dispatch($room, true);

        return $room;
    }

    public function close(ClientRoom|int $room): ClientRoom
    {
        $room = $this->room($room);

        if (! $room->isOpen()) {
            return $room;
        }

        $room->forceFill([
            'status' => ClientRoom::STATUS_CLOSED,
            'closed_at' => now(),
            'last_activity_at' => now(),
        ])->save();

        ClientRoomClosed::dispatch($room);

        return $room;
    }

    /** Reopen a closed room. The same as `open()` on its address. */
    public function reopen(ClientRoom|int $room): ClientRoom
    {
        $room = $this->room($room);

        return $this->open($room->email, null, ['brand_id' => $room->brand_id]);
    }

    /** The room for an address in the current brand, open or closed. */
    public function forEmail(string $email): ?ClientRoom
    {
        $normalized = Emails::normalize($email);

        if ($normalized === null) {
            return null;
        }

        return ClientRoom::query()->where('email', $normalized)->first();
    }

    /** The room of a signed-in Statamic user, by their address. */
    public function forUser(?UserContract $user): ?ClientRoom
    {
        if ($user === null) {
            return null;
        }

        $email = $user->email();

        return is_string($email) ? $this->forEmail($email) : null;
    }

    /**
     * Put something to do into a room.
     *
     * `$attributes` carries the rest of the task — `description`, `type`,
     * `status`, `published_status`, `priority`, `estimated_minutes`. Anything
     * else in it is ignored, so a caller may hand over a whole imported row.
     *
     * A task made here is `published` unless told otherwise: somebody named a
     * client, typed a title and meant it to arrive. The column's own default
     * is `draft` and guards the other direction — a row written straight into
     * the table stays invisible until somebody has an opinion about it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function addTask(ClientRoom|int $room, string $title, ?DateTimeInterface $dueAt = null, ?string $createdBy = null, array $attributes = []): ClientRoomTask
    {
        $room = $this->room($room);

        $title = trim($title);

        if ($title === '') {
            throw new InvalidArgumentException('statamic-clientrooms: a task needs a title.');
        }

        $position = ((int) $room->tasks()->max('position')) + 1;

        $task = $room->tasks()->create(array_merge([
            'title' => $title,
            'due_at' => $dueAt,
            'created_by' => Owners::resolveId($createdBy),
            'position' => $position,
            'status' => ClientRoomTask::STATUS_ASSIGNED,
            'published_status' => ClientRoomTask::PUBLISHED_PUBLISHED,
        ], $this->taskFields($attributes)));

        $room->touchActivity();

        return $task;
    }

    /**
     * Change what a task says. Only the fields handed over are touched, and
     * the tick is not one of them — that stays with `completeTask()` and
     * `reopenTask()`, so `done_at` is never set by a form field that happens
     * to read `completed`.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function updateTask(ClientRoomTask|int $task, array $attributes): ClientRoomTask
    {
        $task = $this->task($task);

        if (array_key_exists('title', $attributes)) {
            $title = trim((string) $attributes['title']);

            if ($title === '') {
                throw new InvalidArgumentException('statamic-clientrooms: a task needs a title.');
            }

            $task->title = $title;
        }

        if (array_key_exists('due_at', $attributes)) {
            $task->due_at = $attributes['due_at'];
        }

        $task->fill($this->taskFields($attributes));

        if ($task->isDirty()) {
            $task->save();
            $this->roomOf($task)->touchActivity();
        }

        return $task;
    }

    /**
     * Record what the client handed back.
     *
     * Never an update: a second attempt is a second submission, so the coach
     * can see there was a first and what changed. Text, files, or both — but
     * not neither, because an empty submission says nothing and would still
     * light up the screen as if work had arrived.
     *
     * The files are written after the row exists, because each is stored under
     * the submission's own id. A file that will not store takes the whole
     * submission down with it rather than leaving one that claims an
     * attachment it does not have.
     *
     * @param  list<UploadedFile>  $files
     * @param  array<string, mixed>  $attributes  `submitted_at`, `meta`
     */
    public function submitTask(ClientRoomTask|int $task, ?string $body = null, array $files = [], ?string $submittedBy = null, array $attributes = []): ClientRoomTaskSubmission
    {
        $task = $this->task($task);

        $body = $body !== null ? trim($body) : null;
        $body = ($body === '') ? null : $body;

        if ($body === null && $files === []) {
            throw new InvalidArgumentException('statamic-clientrooms: a submission needs text or a file.');
        }

        $room = $this->roomOf($task);

        $submission = $task->submissions()->create([
            'body' => $body,
            'submitted_by' => Owners::resolveId($submittedBy) ?? $submittedBy,
            'submitted_at' => $attributes['submitted_at'] ?? now(),
            'meta' => $attributes['meta'] ?? null,
        ]);

        // Deliberately not wrapped in a transaction. A transaction rolls back
        // rows and cannot roll back a disk: a second file refused after the
        // first was written would undo the bookkeeping and leave the first
        // recording in the container with nothing pointing at it. The undoing
        // is done by hand instead, and it takes the files with it.
        try {
            foreach ($files as $file) {
                $this->submissionFiles->attach($room, $submission, $file);
            }
        } catch (Throwable $e) {
            $this->submissionFiles->removeFor($submission->load('files'));

            throw $e;
        }

        $room->touchActivity();

        // Once everything is on disk and in the table, never before: a
        // listener that mails the coach must not announce a submission that
        // then fails to arrive.
        ClientRoomTaskSubmitted::dispatch($room, $task, $submission);

        return $submission->load('files');
    }

    /**
     * Take a submission back out, with the files it brought.
     *
     * Says whether everything really went. The caller is a Control Panel
     * screen that would otherwise report success over a recording still
     * sitting in the container.
     */
    public function removeSubmission(ClientRoomTaskSubmission|int $submission): bool
    {
        $submission = $submission instanceof ClientRoomTaskSubmission
            ? $submission
            : ClientRoomTaskSubmission::query()->findOrFail($submission);

        return $this->submissionFiles->removeFor($submission);
    }

    /** A link the holder may follow for a while. */
    public function submissionDownloadUrl(ClientRoomTaskSubmissionFile $file): string
    {
        return $this->submissionFiles->signedUrl($file);
    }

    /** Show a task to the client, or take it back to the desk. */
    public function publishTask(ClientRoomTask|int $task, bool $published = true): ClientRoomTask
    {
        return $this->updateTask($task, [
            'published_status' => $published
                ? ClientRoomTask::PUBLISHED_PUBLISHED
                : ClientRoomTask::PUBLISHED_DRAFT,
        ]);
    }

    /** Tick a task. A task already done stays done and fires nothing. */
    public function completeTask(ClientRoomTask|int $task, ?string $doneBy = null): ClientRoomTask
    {
        $task = $this->task($task);

        if ($task->isDone()) {
            return $task;
        }

        $task->forceFill([
            'done_at' => now(),
            // `?? $doneBy` like `submitTask()`: `resolveId()` swallows any
            // throwable and answers null, and a lookup that stumbles must not
            // quietly turn "she ticked it" into "nobody ticked it".
            'done_by' => Owners::resolveId($doneBy) ?? $doneBy,
            'status' => ClientRoomTask::STATUS_COMPLETED,
        ])->save();

        $room = $this->roomOf($task);
        $room->touchActivity();

        ClientRoomTaskCompleted::dispatch($room, $task);

        return $task;
    }

    public function reopenTask(ClientRoomTask|int $task): ClientRoomTask
    {
        $task = $this->task($task);

        if (! $task->isDone()) {
            return $task;
        }

        // Back to `assigned`, whatever it said before. Ticking overwrote the
        // old word with `completed`; there is nothing left to restore, and
        // guessing one would be worse than the plain answer "open again". A
        // task that was called off and is now open again gets called off again.
        $task->forceFill([
            'done_at' => null,
            'done_by' => null,
            'status' => ClientRoomTask::STATUS_ASSIGNED,
        ])->save();

        return $task;
    }

    /**
     * The writable task fields, picked out of an array that may hold anything.
     * A key that is absent is left alone; a key that is there is written, an
     * empty string and null landing as null.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function taskFields(array $attributes): array
    {
        $fields = [];

        foreach (['description', 'type', 'status', 'published_status', 'priority'] as $key) {
            if (! array_key_exists($key, $attributes)) {
                continue;
            }

            $value = $attributes[$key];

            // An import may hand over a whole raw row, and a raw row carries
            // arrays and objects. Those become null rather than a fatal cast:
            // a field the addon cannot read is a field it does not have, not a
            // reason to lose the task.
            if (! is_scalar($value)) {
                $fields[$key] = null;

                continue;
            }

            $value = is_string($value) ? trim($value) : $value;
            $value = (string) $value;

            // Cast first, then judge: `false` casts to the empty string and is
            // an absent field, not the two characters "false".
            $fields[$key] = ($value === '') ? null : $value;
        }

        // Not nullable in the database, so an explicit null here means "back to
        // the safe side" rather than a write that blows up at the driver.
        if (array_key_exists('published_status', $fields) && $fields['published_status'] === null) {
            $fields['published_status'] = ClientRoomTask::PUBLISHED_DRAFT;
        }

        // Provenance, not content: an array goes in as it comes, anything else
        // is not something this column can hold.
        if (array_key_exists('meta', $attributes)) {
            $meta = $attributes['meta'];

            $fields['meta'] = is_array($meta) ? $meta : null;
        }

        if (array_key_exists('estimated_minutes', $attributes)) {
            $minutes = $attributes['estimated_minutes'];

            // Clamped at both ends. The column is an unsigned int; a value
            // above its ceiling would otherwise travel all the way to the
            // driver and come back as a SQL error instead of a number nobody
            // meant.
            $fields['estimated_minutes'] = (! is_numeric($minutes))
                ? null
                : min(self::MAX_ESTIMATED_MINUTES, max(0, (int) $minutes));
        }

        return $fields;
    }

    protected function task(ClientRoomTask|int $task): ClientRoomTask
    {
        return $task instanceof ClientRoomTask ? $task : ClientRoomTask::query()->findOrFail($task);
    }

    /** The room a task hangs in, brand scope aside — a task is never orphaned. */
    protected function roomOf(ClientRoomTask $task): ClientRoom
    {
        return $task->room()->withoutGlobalScopes()->firstOrFail();
    }

    public function attach(ClientRoom|int $room, UploadedFile $file, ?string $title = null, bool $visibleToClient = true, ?string $uploadedBy = null): ClientRoomFile
    {
        return $this->files->attach($this->room($room), $file, $title, $visibleToClient, Owners::resolveId($uploadedBy));
    }

    public function downloadUrl(ClientRoomFile $file): string
    {
        return $this->files->signedUrl($file);
    }

    /**
     * @return array{mode: string, entries: list<array<string, mixed>>, sources: array<string, bool>, failed: list<string>, stats: array<string, mixed>, total: int}
     */
    public function timeline(ClientRoom|int $room, ?int $limit = null): array
    {
        return $this->timeline->build($this->room($room), $limit);
    }

    public function files(): RoomFiles
    {
        return $this->files;
    }

    /**
     * Look a room up by address and brand, across the brand scope.
     *
     * Explicit about the brand on purpose: the listener runs where no brand is
     * current, and a scoped query would answer "none" there and create a
     * duplicate the unique key then refuses.
     */
    public function find(string $email, int $brandId): ?ClientRoom
    {
        $normalized = Emails::normalize($email);

        if ($normalized === null) {
            return null;
        }

        return ClientRoom::query()
            ->acrossBrands()
            ->where('brand_id', $brandId)
            ->where('email', $normalized)
            ->first();
    }

    protected function room(ClientRoom|int $room): ClientRoom
    {
        return $room instanceof ClientRoom ? $room : ClientRoom::query()->findOrFail($room);
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    protected function identify(string|object $who): array
    {
        if (is_string($who)) {
            $email = Emails::normalize($who);

            if ($email === null) {
                throw new InvalidArgumentException('statamic-clientrooms: a room needs an e-mail address.');
            }

            return [$email, []];
        }

        $raw = $who instanceof Model ? $who->getAttribute('email') : ($who->email ?? null);
        $email = Emails::normalize(is_string($raw) ? $raw : null);

        if ($email === null) {
            throw new InvalidArgumentException('statamic-clientrooms: the given object carries no e-mail address.');
        }

        $attributes = [];

        // By name, so the analyser does not narrow `$who` to a class that is
        // not installed here.
        if ($who instanceof Model && get_class($who) === Contacts::MODEL) {
            $attributes['contact_id'] = (int) $who->getKey();
            $attributes['name'] = trim(implode(' ', array_filter([
                $who->getAttribute('first_name'),
                $who->getAttribute('last_name'),
            ]))) ?: null;
            $brand = $who->getAttribute('brand_id');
            $attributes['brand_id'] = $brand !== null ? (int) $brand : null;
        } elseif ($who instanceof Model) {
            $name = $who->getAttribute('name');
            $attributes['name'] = is_string($name) && trim($name) !== '' ? $name : null;
            $brand = $who->getAttribute('brand_id');
            $attributes['brand_id'] = $brand !== null && (int) $brand > 0 ? (int) $brand : null;
        }

        return [$email, $attributes];
    }
}
