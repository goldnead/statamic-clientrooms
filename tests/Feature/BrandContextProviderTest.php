<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * The guard as an install gets it: brand-context's own provider boots (package
 * discovery) and registers the `brand.setup-guard` alias, and this addon's
 * routes, which are built while the application boots, must still find it. The
 * rest of the suite registers only the alias (see TestCase).
 */
class BrandContextProviderTest extends TestCase
{
    protected bool $registerGuardAlias = false;

    protected function getPackageProviders($app)
    {
        $providers = parent::getPackageProviders($app);

        if (class_exists('Goldnead\BrandContext\ServiceProvider')) {
            $providers[] = 'Goldnead\BrandContext\ServiceProvider';
        }

        return $providers;
    }

    #[Test]
    public function the_room_route_finds_the_alias_the_packages_provider_registers(): void
    {
        if (! class_exists(self::BRAND_GUARD)) {
            $this->markTestSkipped('goldnead/statamic-brand-context is not installed.');
        }

        $guards = array_filter(
            Route::getRoutes()->getByName('statamic.cp.client-rooms.show')->gatherMiddleware(),
            fn ($middleware) => is_string($middleware) && str_starts_with($middleware, 'brand.setup-guard:')
        );

        $this->assertCount(1, $guards);
    }

    #[Test]
    public function with_the_provider_booted_the_room_page_shows_the_setup_screen_when_tables_are_missing(): void
    {
        if (! class_exists(self::BRAND_GUARD)) {
            $this->markTestSkipped('goldnead/statamic-brand-context is not installed.');
        }

        Schema::dropIfExists('client_room_task_submission_files');
        Schema::dropIfExists('client_room_task_submissions');
        Schema::dropIfExists('client_room_sessions');
        Schema::dropIfExists('client_room_files');
        Schema::dropIfExists('client_room_tasks');
        Schema::dropIfExists('client_rooms');

        $page = $this->actingAs($this->superUser())
            ->get('/cp/client-rooms/1')
            ->assertOk()
            ->viewData('page');

        $this->assertSame('statamic-clientrooms::SetupRequired', $page['component']);
    }
}
