<?php

namespace Goldnead\ClientRooms\Http\Controllers;

use Goldnead\ClientRooms\Models\ClientRoomFile;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * The client's download, behind a signed and expiring URL.
 *
 * The signature is the credential: it is checked by the `signed` middleware
 * on the route and a tampered or expired link answers 403 before this code
 * runs. Two more checks here, because a valid link is not the whole story —
 * the coach may have hidden the file again, or the room may be closed, after
 * the link was rendered. Never the storage path, never the asset URL.
 */
class DownloadController extends Controller
{
    public function __invoke(Request $request, int $file)
    {
        $file = ClientRoomFile::query()
            ->with(['room' => fn ($q) => $q->withoutGlobalScopes()])
            ->findOrFail($file);

        abort_unless($file->visible_to_client, 404);
        abort_unless($file->room !== null && $file->room->isOpen(), 404);

        $asset = $file->asset();

        abort_if($asset === null, 404);

        return $asset->download(basename($file->path), [
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
