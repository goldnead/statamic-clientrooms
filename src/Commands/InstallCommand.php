<?php

namespace Goldnead\ClientRooms\Commands;

use Goldnead\ClientRooms\Support\Files\RoomFiles;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

/**
 * `php please clientrooms:install`
 *
 * Creates the asset containers the rooms' documents go into: one on a
 * single-brand install, one per brand on a multi-brand one. The migration
 * runs with `php artisan migrate` like every other; a container is Statamic
 * content, not schema, and so it is made here. Safe to run again after a
 * brand was added.
 */
class InstallCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'clientrooms:install';

    protected $description = 'Create the asset container(s) for client room documents.';

    public function handle(RoomFiles $files): int
    {
        $disk = (string) config('statamic-clientrooms.disk', 'local');

        foreach ($files->brandIds() as $brandId) {
            $handle = $files->containerHandle($brandId);

            if ($files->ensureContainer($brandId)) {
                $this->info(sprintf('Asset container [%s] created on disk [%s].', $handle, $disk));
            } else {
                $this->line(sprintf('Asset container [%s] already exists.', $handle));
            }
        }

        return self::SUCCESS;
    }
}
