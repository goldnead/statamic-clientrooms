<?php

namespace Goldnead\ClientRooms\Tests;

use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\ServiceProvider;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Role;
use Statamic\Facades\User;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('statamic.system.multisite', false);

        // The suite makes more than one user, which the free edition refuses.
        $app['config']->set('statamic.editions.pro', true);

        // A private disk for the documents, like the default config asks for.
        $app['config']->set('filesystems.disks.clientrooms_test', [
            'driver' => 'local',
            'root' => __DIR__.'/__fixtures__/documents',
        ]);
        $app['config']->set('statamic-clientrooms.disk', 'clientrooms_test');
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        Storage::fake('clientrooms_test');
    }

    protected function tearDown(): void
    {
        Storage::fake('clientrooms_test');

        parent::tearDown();
    }

    protected function room(array $overrides = []): ClientRoom
    {
        return ClientRoom::create(array_merge([
            'email' => 'maria@example.com',
            'name' => 'Maria Beispiel',
            'status' => ClientRoom::STATUS_OPEN,
            'opened_at' => now()->subDays(3),
            'last_activity_at' => now()->subDay(),
        ], $overrides));
    }

    /** A superuser: may do everything. */
    protected function superUser()
    {
        return tap(User::make()->email(uniqid().'@example.com')->makeSuper())->save();
    }

    /**
     * Signed in, may open the Control Panel, may not touch rooms.
     *
     * (`makeSuper()` takes no argument. A role is the only way to make a
     * user who is *not* a superuser and can still reach the CP.)
     */
    protected function userWithoutPermission()
    {
        $role = tap(Role::make('nur-cp-'.uniqid())->addPermission('access cp'))->save();

        return tap(User::make()->email(uniqid().'@example.com')->assignRole($role))->save();
    }

    protected function userWithPermission(string ...$permissions)
    {
        $role = Role::make('rooms-'.uniqid())->addPermission('access cp');

        foreach ($permissions as $permission) {
            $role->addPermission($permission);
        }

        $role->save();

        return tap(User::make()->email(uniqid().'@example.com')->assignRole($role))->save();
    }

    /** The container the documents go into, created the way the install command does it. */
    protected function makeContainer(): void
    {
        if (AssetContainer::findByHandle('clientrooms') === null) {
            AssetContainer::make('clientrooms')->title('Client rooms')->disk('clientrooms_test')->save();
        }
    }
}
