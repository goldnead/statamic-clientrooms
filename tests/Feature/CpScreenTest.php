<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Facades\ClientRooms;
use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

/**
 * Who may look, who may change, and what the screens get.
 */
class CpScreenTest extends TestCase
{
    #[Test]
    public function the_listing_is_closed_to_anyone_not_signed_in(): void
    {
        $this->room();

        $this->get('/cp/client-rooms')->assertRedirect();
    }

    #[Test]
    public function a_user_without_the_permission_is_refused(): void
    {
        $room = $this->room();
        $user = $this->userWithoutPermission();

        $this->actingAs($user)->getJson('/cp/client-rooms')->assertForbidden();
        $this->actingAs($user)->getJson('/cp/client-rooms/'.$room->id)->assertForbidden();
        // A plain GET: Statamic sends an unauthorised CP user back to the
        // dashboard rather than showing a 403. Either way, no file.
        $this->actingAs($user)->get('/cp/client-rooms/'.$room->id.'/files/1/download')->assertRedirect();
        $this->actingAs($user)->getJson('/cp/client-rooms/'.$room->id.'/files/1/download')->assertForbidden();
    }

    #[Test]
    public function a_reader_may_not_write(): void
    {
        $room = $this->room();
        $reader = $this->userWithPermission('view client rooms');

        $this->actingAs($reader)->getJson('/cp/client-rooms')->assertOk();
        $this->actingAs($reader)->get('/cp/client-rooms/'.$room->id)->assertOk();

        $this->actingAs($reader)->postJson('/cp/client-rooms', ['email' => 'neu@example.com'])->assertForbidden();
        $this->actingAs($reader)->patchJson('/cp/client-rooms/'.$room->id, ['notes' => 'x'])->assertForbidden();
        $this->actingAs($reader)->postJson('/cp/client-rooms/'.$room->id.'/close')->assertForbidden();
        $this->actingAs($reader)->postJson('/cp/client-rooms/'.$room->id.'/reopen')->assertForbidden();
        $this->actingAs($reader)->postJson('/cp/client-rooms/'.$room->id.'/tasks', ['title' => 'x'])->assertForbidden();
        $this->actingAs($reader)->patchJson('/cp/client-rooms/'.$room->id.'/tasks/1', ['done' => true])->assertForbidden();
        $this->actingAs($reader)->deleteJson('/cp/client-rooms/'.$room->id.'/tasks/1')->assertForbidden();
        $this->actingAs($reader)->postJson('/cp/client-rooms/'.$room->id.'/files', [])->assertForbidden();
        $this->actingAs($reader)->patchJson('/cp/client-rooms/'.$room->id.'/files/1', [])->assertForbidden();
        $this->actingAs($reader)->deleteJson('/cp/client-rooms/'.$room->id.'/files/1')->assertForbidden();

        $this->assertSame(1, ClientRoom::query()->count());
        $this->assertNull($room->refresh()->notes);
    }

    #[Test]
    public function the_listing_answers_rows_and_columns(): void
    {
        $this->room(['email' => 'a@example.com', 'name' => 'Anna']);
        $b = $this->room(['email' => 'b@example.com', 'name' => 'Bernd']);
        ClientRooms::addTask($b, 'Nachfassen');

        $response = $this->actingAs($this->superUser())->getJson('/cp/client-rooms?sort=name&order=asc');

        $response->assertOk()
            ->assertJsonPath('data.0.name', 'Anna')
            ->assertJsonPath('data.1.name', 'Bernd')
            ->assertJsonPath('data.1.open_tasks', 1)
            ->assertJsonPath('data.0.status', 'open');

        $columns = collect($response->json('meta.columns'))->pluck('field')->all();
        $this->assertContains('name', $columns);
        $this->assertContains('email', $columns);
        $this->assertContains('status', $columns);
        $this->assertContains('open_tasks', $columns);
        $this->assertContains('last_activity_at', $columns);
    }

    #[Test]
    public function the_listing_searches_name_and_address(): void
    {
        $this->room(['email' => 'a@example.com', 'name' => 'Anna Alt']);
        $this->room(['email' => 'bernd@example.com', 'name' => 'Bernd']);

        $this->actingAs($this->superUser())->getJson('/cp/client-rooms?search=bernd')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.email', 'bernd@example.com');
    }

    #[Test]
    public function the_index_and_the_detail_render_their_pages(): void
    {
        $room = $this->room();
        $user = $this->superUser();

        $this->actingAs($user)->get('/cp/client-rooms')
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('statamic-clientrooms::Rooms/Index')
                ->where('hasAny', true)
                ->where('canEdit', true));

        $this->actingAs($user)->get('/cp/client-rooms/'.$room->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('statamic-clientrooms::Rooms/Show')
                ->where('room.email', 'maria@example.com')
                ->where('room.is_open', true)
                ->where('timelineMode', 'fallback')
                ->has('tasks', 0)
                ->has('files', 0));
    }

    #[Test]
    public function a_room_is_opened_by_hand(): void
    {
        $user = $this->superUser();

        $response = $this->actingAs($user)->post('/cp/client-rooms', [
            'email' => 'Neu@Example.com',
            'name' => 'Neue Klientin',
        ]);

        $room = ClientRoom::query()->firstOrFail();

        $response->assertRedirect('/cp/client-rooms/'.$room->id);
        $this->assertSame('neu@example.com', $room->email);
        $this->assertSame((string) $user->id(), $room->owner_user_id);
    }

    #[Test]
    public function notes_and_owner_are_saved_and_a_bad_owner_is_refused(): void
    {
        $room = $this->room();
        $user = $this->superUser();
        $coach = $this->superUser();

        $this->actingAs($user)->patch('/cp/client-rooms/'.$room->id, [
            'notes' => 'Intern',
            'client_notes' => 'Für dich',
            'owner_user_id' => (string) $coach->id(),
        ])->assertRedirect();

        $room->refresh();
        $this->assertSame('Intern', $room->notes);
        $this->assertSame('Für dich', $room->client_notes);
        $this->assertSame((string) $coach->id(), $room->owner_user_id);

        $this->actingAs($user)->from('/cp/client-rooms/'.$room->id)->patch('/cp/client-rooms/'.$room->id, [
            'owner_user_id' => 'niemand',
        ])->assertSessionHasErrors('owner_user_id');

        $this->assertSame((string) $coach->id(), $room->refresh()->owner_user_id);
    }

    #[Test]
    public function close_and_reopen(): void
    {
        $room = $this->room();
        $user = $this->superUser();

        $this->actingAs($user)->post('/cp/client-rooms/'.$room->id.'/close')->assertRedirect();
        $this->assertFalse($room->refresh()->isOpen());

        $this->actingAs($user)->post('/cp/client-rooms/'.$room->id.'/reopen')->assertRedirect();
        $this->assertTrue($room->refresh()->isOpen());
    }

    #[Test]
    public function tasks_are_added_ticked_and_removed(): void
    {
        $room = $this->room();
        $user = $this->superUser();

        $this->actingAs($user)->post('/cp/client-rooms/'.$room->id.'/tasks', [
            'title' => 'Aufnahme schicken',
            'due_at' => now()->addDays(2)->toDateString(),
        ])->assertRedirect();

        $task = $room->tasks()->firstOrFail();
        $this->assertNotNull($task->due_at);

        $this->actingAs($user)->patch('/cp/client-rooms/'.$room->id.'/tasks/'.$task->id, ['done' => true])->assertRedirect();
        $this->assertTrue($task->refresh()->isDone());
        $this->assertSame((string) $user->id(), $task->done_by);

        $this->actingAs($user)->patch('/cp/client-rooms/'.$room->id.'/tasks/'.$task->id, ['done' => false])->assertRedirect();
        $this->assertFalse($task->refresh()->isDone());

        $this->actingAs($user)->delete('/cp/client-rooms/'.$room->id.'/tasks/'.$task->id)->assertRedirect();
        $this->assertSame(0, $room->tasks()->count());
    }

    #[Test]
    public function a_task_of_another_room_is_not_reachable_through_this_one(): void
    {
        $room = $this->room();
        $other = $this->room(['email' => 'other@example.com']);
        $task = ClientRooms::addTask($other, 'Fremd');

        $this->actingAs($this->superUser())
            ->patchJson('/cp/client-rooms/'.$room->id.'/tasks/'.$task->id, ['done' => true])
            ->assertNotFound();

        $this->assertFalse($task->refresh()->isDone());
    }

    #[Test]
    public function files_are_uploaded_toggled_and_removed(): void
    {
        $this->makeContainer();
        $room = $this->room();
        $user = $this->superUser();

        $this->actingAs($user)->post('/cp/client-rooms/'.$room->id.'/files', [
            'file' => UploadedFile::fake()->create('plan.pdf', 12, 'application/pdf'),
            'title' => 'Übungsplan',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $file = $room->files()->firstOrFail();
        $this->assertSame('Übungsplan', $file->title);
        $this->assertTrue($file->visible_to_client);
        $this->assertStringStartsWith('room-'.$room->id.'/', $file->path);
        $this->assertNotNull($file->asset());

        $this->actingAs($user)->get('/cp/client-rooms/'.$room->id.'/files/'.$file->id.'/download')->assertOk();

        $this->actingAs($user)->patch('/cp/client-rooms/'.$room->id.'/files/'.$file->id, ['visible_to_client' => false])->assertRedirect();
        $this->assertFalse($file->refresh()->visible_to_client);

        $this->actingAs($user)->delete('/cp/client-rooms/'.$room->id.'/files/'.$file->id)->assertRedirect();
        $this->assertSame(0, $room->files()->count());
        $this->assertNull($file->asset());
    }

    #[Test]
    public function an_upload_without_the_container_says_so_instead_of_failing_silently(): void
    {
        $room = $this->room();

        $this->actingAs($this->superUser())->from('/cp/client-rooms/'.$room->id)->post('/cp/client-rooms/'.$room->id.'/files', [
            'file' => UploadedFile::fake()->create('plan.pdf', 12, 'application/pdf'),
        ])->assertSessionHasErrors('file');

        $this->assertSame(0, $room->files()->count());
    }
}
