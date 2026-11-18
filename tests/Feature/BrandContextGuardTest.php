<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Support\Setup;
use Goldnead\ClientRooms\Tests\TestCase;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/** A guard class whose alias nobody registered: brand-context in vendor, its provider not booted. */
class AliasLessGuard
{
    public const ALIAS = 'brand.never-registered-guard';

    public static function using(string $setup, string $title, string ...$tables): string
    {
        return self::ALIAS.':'.$setup;
    }
}

/**
 * The detail page's setup guard comes from `goldnead/statamic-brand-context`
 * (>= 1.27), which is a suggest. Both installs have to hold:
 *
 * - with the package, a room page on an unmigrated database shows the same
 *   setup screen the listing does, not an HTTP 500;
 * - without it, the route carries no such middleware and nothing else changes.
 *
 * The CI runs the suite twice, once with the package in vendor and once with it
 * removed (`composer remove --dev`). A test that needs the other install skips
 * itself, so each leg proves its own half.
 */
class BrandContextGuardTest extends TestCase
{
    private function installed(): bool
    {
        return class_exists(self::BRAND_GUARD);
    }

    /** Children first: the sittings and submissions point back at rooms and tasks. */
    private function dropAddonTables(): void
    {
        Schema::dropIfExists('client_room_task_submission_files');
        Schema::dropIfExists('client_room_task_submissions');
        Schema::dropIfExists('client_room_sessions');
        Schema::dropIfExists('client_room_files');
        Schema::dropIfExists('client_room_tasks');
        Schema::dropIfExists('client_rooms');
    }

    private function showMiddleware(): array
    {
        return Route::getRoutes()->getByName('statamic.cp.client-rooms.show')->gatherMiddleware();
    }

    #[Test]
    public function a_missing_middleware_class_gives_no_middleware(): void
    {
        $this->assertSame([], Setup::detailGuardFrom('Goldnead\NotInstalled\GuardSetup', 'Title', 'client_rooms'));
    }

    #[Test]
    public function the_installed_middleware_is_asked_for_this_addons_setup_class(): void
    {
        if (! $this->installed()) {
            $this->markTestSkipped('goldnead/statamic-brand-context is not installed.');
        }

        $middleware = Setup::detailGuard('statamic-clientrooms::messages.nav', 'client_rooms');

        $this->assertCount(1, $middleware);
        $this->assertStringStartsWith('brand.setup-guard:'.Setup::class.',', $middleware[0]);
        $this->assertStringContainsString(',client_rooms', $middleware[0]);
    }

    #[Test]
    public function with_brand_context_the_room_page_shows_the_setup_screen_when_tables_are_missing(): void
    {
        if (! $this->installed()) {
            $this->markTestSkipped('goldnead/statamic-brand-context is not installed.');
        }

        $this->dropAddonTables();

        $response = $this->actingAs($this->superUser())
            ->get('/cp/client-rooms/1')
            ->assertOk();

        $page = $response->viewData('page');

        $this->assertSame('statamic-clientrooms::SetupRequired', $page['component']);
        $this->assertContains('client_rooms', $page['props']['tables']);
    }

    #[Test]
    public function with_brand_context_a_user_without_the_permission_does_not_see_the_setup_screen(): void
    {
        if (! $this->installed()) {
            $this->markTestSkipped('goldnead/statamic-brand-context is not installed.');
        }

        $this->dropAddonTables();

        $user = $this->userWithoutPermission();

        // Like the rest of the CP: a refusal, never the screen with the table names.
        $this->actingAs($user)->get('/cp/client-rooms/1')->assertRedirect();
        $this->actingAs($user)->getJson('/cp/client-rooms/1')->assertForbidden();
    }

    #[Test]
    public function a_guard_class_without_its_alias_in_the_router_gives_no_middleware(): void
    {
        $this->assertSame([], Setup::detailGuardFrom(AliasLessGuard::class, 'Title', 'client_rooms'));
    }

    #[Test]
    public function with_brand_context_a_migrated_install_still_shows_the_room(): void
    {
        if (! $this->installed()) {
            $this->markTestSkipped('goldnead/statamic-brand-context is not installed.');
        }

        $room = $this->room();

        $this->actingAs($this->superUser())
            ->get('/cp/client-rooms/'.$room->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('statamic-clientrooms::Rooms/Show'));
    }

    #[Test]
    public function without_brand_context_the_room_route_carries_no_guard_and_still_works(): void
    {
        if ($this->installed()) {
            $this->markTestSkipped('goldnead/statamic-brand-context is installed.');
        }

        $this->assertNotContains(
            true,
            array_map(fn ($name) => str_starts_with((string) $name, 'brand.setup-guard'), $this->showMiddleware())
        );

        $room = $this->room();

        $this->actingAs($this->superUser())
            ->get('/cp/client-rooms/'.$room->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('statamic-clientrooms::Rooms/Show'));
    }
}
