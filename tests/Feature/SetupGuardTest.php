<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Tests\TestCase;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * The demo answered HTTP 500 on /cp/client-rooms on 03.09.2026 because the
 * addon was installed and its migrations were not. These tests reproduce that
 * database — everything present except the addon's own tables — and hold the
 * listing to an empty state plus a line in the log.
 */
class SetupGuardTest extends TestCase
{
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

    #[Test]
    public function the_index_answers_200_when_its_tables_are_missing(): void
    {
        $this->dropAddonTables();

        $this->actingAs($this->superUser())
            ->get('/cp/client-rooms')
            ->assertOk();
    }

    #[Test]
    public function the_index_renders_the_setup_screen_and_names_the_missing_tables(): void
    {
        $this->dropAddonTables();

        $response = $this->actingAs($this->superUser())
            ->get('/cp/client-rooms')
            ->assertOk();

        $page = $response->viewData('page');

        $this->assertSame('statamic-clientrooms::SetupRequired', $page['component']);
        $this->assertContains('client_rooms', $page['props']['tables']);
        $this->assertContains('client_room_tasks', $page['props']['tables']);
        $this->assertNotEmpty($page['props']['heading']);
        $this->assertNotEmpty($page['props']['description']);
    }

    /**
     * The point of the guard is a readable page, not a quiet one. If this test
     * ever goes red the addon has traded a visible 500 for a silent nothing.
     */
    #[Test]
    public function the_reason_reaches_the_log(): void
    {
        $this->dropAddonTables();

        Log::spy();

        $this->actingAs($this->superUser())
            ->get('/cp/client-rooms')
            ->assertOk();

        Log::shouldHaveReceived('error')
            ->withArgs(fn (string $message) => str_contains($message, 'statamic-clientrooms')
                && str_contains($message, 'php artisan migrate'))
            ->once();
    }

    /**
     * The screen's own listing request, which is the one that actually queried
     * `client_rooms` and counted `client_room_tasks` when the demo fell over.
     */
    #[Test]
    public function the_listing_request_behind_the_screen_is_guarded_too(): void
    {
        $this->dropAddonTables();

        $this->actingAs($this->superUser())
            ->getJson('/cp/client-rooms')
            ->assertOk();
    }

    /** A single missing table is enough, and only that one is named. */
    #[Test]
    public function a_half_migrated_install_names_only_what_is_missing(): void
    {
        Schema::dropIfExists('client_room_task_submission_files');
        Schema::dropIfExists('client_room_task_submissions');
        Schema::dropIfExists('client_room_tasks');

        $response = $this->actingAs($this->superUser())
            ->get('/cp/client-rooms')
            ->assertOk();

        $page = $response->viewData('page');

        $this->assertSame('statamic-clientrooms::SetupRequired', $page['component']);
        $this->assertSame(['client_room_tasks'], $page['props']['tables']);
    }

    #[Test]
    public function a_migrated_install_still_renders_the_listing(): void
    {
        $this->room();

        $this->actingAs($this->superUser())
            ->get('/cp/client-rooms')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('statamic-clientrooms::Rooms/Index')
                ->where('hasAny', true));
    }
}
