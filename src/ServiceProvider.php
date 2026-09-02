<?php

namespace Goldnead\ClientRooms;

use Goldnead\ClientRooms\Support\Files\RoomFiles;
use Goldnead\ClientRooms\Support\Timeline\RoomTimeline;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $viewNamespace = 'statamic-clientrooms';

    /**
     * Absolute paths, because the parent resolves the addon directory through
     * the manifest and comes up empty in package test suites.
     *
     * @var array<string, string>
     */
    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
        'web' => __DIR__.'/../routes/web.php',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $vite = [
        'hotFile' => __DIR__.'/../dist/hot',
        'publicDirectory' => 'dist',
        'input' => ['resources/js/cp.js', 'resources/css/cp.css'],
    ];

    /**
     * Same reason as `$routes`: config is merged explicitly in register().
     */
    protected $config = false;

    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/statamic-clientrooms.php', 'statamic-clientrooms');

        $this->app->singleton(RoomFiles::class);
        $this->app->singleton(RoomTimeline::class);
        $this->app->singleton(ClientRoomsManager::class);
    }

    public function bootAddon()
    {
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'statamic-clientrooms');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'statamic-clientrooms');

        // The tables are the addon. Nothing here works without them, so the
        // migrations load unconditionally and `php artisan migrate` is part
        // of installing it.
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->bootPermissions()
            ->bootNav()
            ->bootPublishing();
    }

    /**
     * Two authorities: reading a room and changing it. Reading already shows
     * a client's address and notes, so it is not free either.
     */
    protected function bootPermissions(): self
    {
        Permission::extend(function (): void {
            Permission::group('client-rooms', __('statamic-clientrooms::messages.title'), function (): void {
                Permission::register('view client rooms')
                    ->label(__('statamic-clientrooms::messages.permission_view'))
                    ->children([
                        Permission::make('edit client rooms')
                            ->label(__('statamic-clientrooms::messages.permission_edit')),
                    ]);
            });
        });

        return $this;
    }

    /**
     * One entry under Tools, next to the family's other screens. Inside
     * `Nav::extend`, so `__()` runs after the user's language is set.
     */
    protected function bootNav(): self
    {
        Nav::extend(function ($nav): void {
            $nav->create(__('statamic-clientrooms::messages.nav'))
                ->section('Tools')
                ->icon('users')
                ->route('client-rooms.index')
                ->can('view client rooms');
        });

        return $this;
    }

    protected function bootPublishing(): self
    {
        $this->publishes([
            __DIR__.'/../config/statamic-clientrooms.php' => config_path('statamic-clientrooms.php'),
        ], 'statamic-clientrooms-config');

        $this->publishes([
            __DIR__.'/../database/migrations' => database_path('migrations'),
        ], 'statamic-clientrooms-migrations');

        $this->publishes([
            __DIR__.'/../resources/views' => resource_path('views/vendor/statamic-clientrooms'),
        ], 'statamic-clientrooms-views');

        return $this;
    }
}
