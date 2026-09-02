<?php

namespace Goldnead\ClientRooms\Commands;

use Goldnead\ClientRooms\Support\Files\RoomFiles;
use Illuminate\Console\Command;
use Statamic\Console\RunsInPlease;

/**
 * `php please clientrooms:install`
 *
 * Creates the asset container the rooms' documents go into. The migration
 * runs with `php artisan migrate` like every other; the container is Statamic
 * content, not schema, and so it is made here.
 */
class InstallCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'clientrooms:install';

    protected $description = 'Create the asset container for client room documents.';

    public function handle(RoomFiles $files): int
    {
        $handle = $files->containerHandle();

        if ($files->ensureContainer()) {
            $this->info(sprintf('Asset container [%s] created on disk [%s].', $handle, (string) config('statamic-clientrooms.disk', 'local')));
        } else {
            $this->line(sprintf('Asset container [%s] already exists.', $handle));
        }

        return self::SUCCESS;
    }
}
