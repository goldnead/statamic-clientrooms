<?php

namespace Goldnead\ClientRooms\Tests;

use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\ServiceProvider;
use Goldnead\ClientRooms\Tests\Fakes\FakeBrandManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
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

        // Whether the brand scope is added is decided when a model boots, and
        // Eloquent boots a model once per process. Every test starts unbooted,
        // so a test that binds the brand fake and one that does not cannot
        // leak their decision into each other.
        Model::clearBootedModels();

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');

        Storage::fake('clientrooms_test');
    }

    /**
     * Pretend brand-context is installed: multi-brand, with two brands and
     * the given one current. Returns the manager so a test can switch.
     */
    protected function fakeBrandContext(?int $current = 1, bool $multi = true): FakeBrandManager
    {
        $manager = new FakeBrandManager($multi, $current);
        $this->app->instance('brand-context', $manager);

        Model::clearBootedModels();

        if (DB::table('brands')->count() === 0) {
            DB::table('brands')->insert([
                ['id' => 1, 'handle' => 'nordlicht', 'name' => 'Nordlicht'],
                ['id' => 2, 'handle' => 'chorwerkstatt', 'name' => 'Chorwerkstatt'],
            ]);
        }

        return $manager;
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

    /** A user with no role at all: a site member, never staff. */
    protected function frontendUser(string $email)
    {
        return tap(User::make()->email($email))->save();
    }

    /** The container the documents go into, created the way the install command does it. */
    protected function makeContainer(string $handle = 'clientrooms'): void
    {
        if (AssetContainer::findByHandle($handle) === null) {
            AssetContainer::make($handle)->title('Client rooms')->disk('clientrooms_test')->save();
        }
    }
}
