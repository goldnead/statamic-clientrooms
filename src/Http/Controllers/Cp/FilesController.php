<?php

namespace Goldnead\ClientRooms\Http\Controllers\Cp;

use Goldnead\ClientRooms\ClientRoomsManager;
use Goldnead\ClientRooms\Http\Controllers\Cp\Concerns\AuthorizesRooms;
use Goldnead\ClientRooms\Models\ClientRoomFile;
use Goldnead\ClientRooms\Support\Owners;
use Illuminate\Http\Request;
use RuntimeException;
use Statamic\Http\Controllers\CP\CpController;

class FilesController extends CpController
{
    use AuthorizesRooms;

    public function __construct(Request $request, protected ClientRoomsManager $rooms)
    {
        parent::__construct($request);
    }

    public function store(Request $request, int $room)
    {
        $this->authorize('edit client rooms');

        $room = $this->findRoom($room);

        $data = $request->validate([
            'file' => ['required', 'file', 'max:51200'],
            'title' => ['nullable', 'string', 'max:255'],
            'visible_to_client' => ['nullable', 'boolean'],
        ]);

        try {
            $this->rooms->attach(
                $room,
                $request->file('file'),
                $data['title'] ?? null,
                $request->has('visible_to_client') ? $request->boolean('visible_to_client') : true,
                Owners::currentId(),
            );
        } catch (RuntimeException $e) {
            // The container is missing: a configuration fault, said out loud
            // on the field rather than as a 500 nobody can read.
            return back()->withErrors(['file' => $e->getMessage()]);
        }

        return back()->with('success', __('statamic-clientrooms::messages.file_uploaded'));
    }

    /** The visibility switch. */
    public function update(Request $request, int $room, int $file)
    {
        $this->authorize('edit client rooms');

        $room = $this->findRoom($room);
        $file = $this->file($room->id, $file);

        $data = $request->validate([
            'visible_to_client' => ['sometimes', 'boolean'],
            'title' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        if ($request->has('visible_to_client')) {
            $data['visible_to_client'] = $request->boolean('visible_to_client');
        }

        $file->fill($data)->save();
        $room->touchActivity();

        return back()->with('success', __('statamic-clientrooms::messages.saved'));
    }

    public function destroy(int $room, int $file)
    {
        $this->authorize('edit client rooms');

        $room = $this->findRoom($room);
        $this->rooms->files()->remove($this->file($room->id, $file));
        $room->touchActivity();

        return back()->with('success', __('statamic-clientrooms::messages.file_deleted'));
    }

    /** The coach's own download, guarded by the permission rather than a signature. */
    public function download(int $room, int $file)
    {
        $this->authorize('view client rooms');

        $room = $this->findRoom($room);
        $file = $this->file($room->id, $file);
        $asset = $file->asset();

        abort_if($asset === null, 404);

        return $asset->download($file->displayTitle() !== basename($file->path)
            ? $file->displayTitle().'.'.$asset->extension()
            : basename($file->path));
    }

    protected function file(int $roomId, int $fileId): ClientRoomFile
    {
        return ClientRoomFile::query()->where('room_id', $roomId)->findOrFail($fileId);
    }
}
