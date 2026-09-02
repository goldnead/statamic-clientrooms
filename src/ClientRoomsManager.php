<?php

namespace Goldnead\ClientRooms;

use DateTimeInterface;
use Goldnead\ClientRooms\Events\ClientRoomClosed;
use Goldnead\ClientRooms\Events\ClientRoomOpened;
use Goldnead\ClientRooms\Events\ClientRoomTaskCompleted;
use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\Models\ClientRoomFile;
use Goldnead\ClientRooms\Models\ClientRoomTask;
use Goldnead\ClientRooms\Support\Brands;
use Goldnead\ClientRooms\Support\Contacts;
use Goldnead\ClientRooms\Support\Emails;
use Goldnead\ClientRooms\Support\Files\RoomFiles;
use Goldnead\ClientRooms\Support\Owners;
use Goldnead\ClientRooms\Support\Timeline\RoomTimeline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use RuntimeException;
use Statamic\Contracts\Auth\User as UserContract;

/**
 * The public face of the addon. Everything the site, a listener or a console
 * command does with a room goes through here, so that the rules — one room
 * per address and brand, events on transitions only — live in one place.
 */
class ClientRoomsManager
{
    public function __construct(
        protected RoomFiles $files,
        protected RoomTimeline $timeline,
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

    public function addTask(ClientRoom|int $room, string $title, ?DateTimeInterface $dueAt = null, ?string $createdBy = null): ClientRoomTask
    {
        $room = $this->room($room);

        $title = trim($title);

        if ($title === '') {
            throw new InvalidArgumentException('statamic-clientrooms: a task needs a title.');
        }

        $position = ((int) $room->tasks()->max('position')) + 1;

        $task = $room->tasks()->create([
            'title' => $title,
            'due_at' => $dueAt,
            'created_by' => Owners::resolveId($createdBy),
            'position' => $position,
        ]);

        $room->touchActivity();

        return $task;
    }

    /** Tick a task. A task already done stays done and fires nothing. */
    public function completeTask(ClientRoomTask|int $task, ?string $doneBy = null): ClientRoomTask
    {
        $task = $task instanceof ClientRoomTask ? $task : ClientRoomTask::query()->findOrFail($task);

        if ($task->isDone()) {
            return $task;
        }

        $task->forceFill([
            'done_at' => now(),
            'done_by' => Owners::resolveId($doneBy),
        ])->save();

        $room = $task->room()->withoutGlobalScopes()->firstOrFail();
        $room->touchActivity();

        ClientRoomTaskCompleted::dispatch($room, $task);

        return $task;
    }

    public function reopenTask(ClientRoomTask|int $task): ClientRoomTask
    {
        $task = $task instanceof ClientRoomTask ? $task : ClientRoomTask::query()->findOrFail($task);

        if (! $task->isDone()) {
            return $task;
        }

        $task->forceFill(['done_at' => null, 'done_by' => null])->save();

        return $task;
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
