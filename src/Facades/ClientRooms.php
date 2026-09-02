<?php

namespace Goldnead\ClientRooms\Facades;

use Goldnead\ClientRooms\ClientRoomsManager;
use Illuminate\Support\Facades\Facade;

/**
 * @method static \Goldnead\ClientRooms\Models\ClientRoom open(string|object $who, ?string $ownerUserId = null, array $attributes = [])
 * @method static \Goldnead\ClientRooms\Models\ClientRoom close(\Goldnead\ClientRooms\Models\ClientRoom|int $room)
 * @method static \Goldnead\ClientRooms\Models\ClientRoom reopen(\Goldnead\ClientRooms\Models\ClientRoom|int $room)
 * @method static \Goldnead\ClientRooms\Models\ClientRoom|null forEmail(string $email)
 * @method static \Goldnead\ClientRooms\Models\ClientRoom|null forUser(?\Statamic\Contracts\Auth\User $user)
 * @method static \Goldnead\ClientRooms\Models\ClientRoom|null find(string $email, int $brandId)
 * @method static \Goldnead\ClientRooms\Models\ClientRoomTask addTask(\Goldnead\ClientRooms\Models\ClientRoom|int $room, string $title, ?\DateTimeInterface $dueAt = null, ?string $createdBy = null, array $attributes = [])
 * @method static \Goldnead\ClientRooms\Models\ClientRoomTask updateTask(\Goldnead\ClientRooms\Models\ClientRoomTask|int $task, array $attributes)
 * @method static \Goldnead\ClientRooms\Models\ClientRoomTask publishTask(\Goldnead\ClientRooms\Models\ClientRoomTask|int $task, bool $published = true)
 * @method static \Goldnead\ClientRooms\Models\ClientRoomTask completeTask(\Goldnead\ClientRooms\Models\ClientRoomTask|int $task, ?string $doneBy = null)
 * @method static \Goldnead\ClientRooms\Models\ClientRoomTask reopenTask(\Goldnead\ClientRooms\Models\ClientRoomTask|int $task)
 * @method static \Goldnead\ClientRooms\Models\ClientRoomFile attach(\Goldnead\ClientRooms\Models\ClientRoom|int $room, \Illuminate\Http\UploadedFile $file, ?string $title = null, bool $visibleToClient = true, ?string $uploadedBy = null)
 * @method static string downloadUrl(\Goldnead\ClientRooms\Models\ClientRoomFile $file)
 * @method static array timeline(\Goldnead\ClientRooms\Models\ClientRoom|int $room, ?int $limit = null)
 * @method static \Goldnead\ClientRooms\Support\Files\RoomFiles files()
 *
 * @see ClientRoomsManager
 */
class ClientRooms extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return ClientRoomsManager::class;
    }
}
