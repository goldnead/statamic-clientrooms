<?php

namespace Goldnead\ClientRooms\Http\Controllers\Cp\Concerns;

use Goldnead\ClientRooms\Models\ClientRoom;
use Illuminate\Support\Facades\Gate;

/**
 * The one way a route parameter becomes a room, and the flag the screens read.
 *
 * Authorization itself is written out in every controller action as
 * `$this->authorize('view client rooms')` / `'edit client rooms'` — visibly,
 * on the line, not behind a helper — because a guard that lives somewhere
 * else is the guard a later action forgets.
 *
 * `{room}` is an id anyone can type. It is looked up through the model's
 * default (brand-scoped) query, so on a multi-brand install another tenant's
 * room answers 404 rather than opening — the id gives nothing away. The
 * parameters are not implicitly bound: a `Route::bind('room')` would claim
 * the name application-wide and collide with any sibling that uses it.
 */
trait AuthorizesRooms
{
    protected function findRoom(int $room): ClientRoom
    {
        return ClientRoom::query()->findOrFail($room);
    }

    protected function canEdit(): bool
    {
        return Gate::allows('edit client rooms');
    }
}
