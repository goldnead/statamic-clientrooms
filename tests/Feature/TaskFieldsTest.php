<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Facades\ClientRooms;
use Goldnead\ClientRooms\Models\ClientRoomTask;
use Goldnead\ClientRooms\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Antlers;

/**
 * What a task carries beyond a line of text, and where the client's sight ends.
 *
 * `published_status` is the point of this file. It is the line between what
 * the coach is still writing and what somebody reads in their own room, so
 * every test about it asserts an absence, not a shape: a test that only
 * checked the field was stored would pass just as well with the line not
 * drawn at all.
 */
class TaskFieldsTest extends TestCase
{
    /** The third argument marks the template as trusted; without it Antlers runs no tags. */
    protected function parse(string $template): string
    {
        return (string) Antlers::parse($template, [], true);
    }

    protected string $tasks = '{{ client_room }}{{ tasks }}[{{ title }}]{{ /tasks }}{{ /client_room }}';

    /** Sign in as the client whose room this is. */
    protected function actAsClient(string $email = 'maria@example.com'): void
    {
        $user = $this->userWithPermission();
        $user->email($email)->save();

        $this->actingAs($user);
    }

    // ── The line ────────────────────────────────────────────────────────────

    #[Test]
    public function a_task_in_draft_is_in_the_control_panel_and_not_in_the_room(): void
    {
        $room = $this->room();
        $staff = $this->superUser();

        $this->actingAs($staff)->post('/cp/client-rooms/'.$room->id.'/tasks', [
            'title' => 'Fertig, sichtbar',
            'published_status' => 'published',
        ])->assertRedirect();

        $this->actingAs($staff)->post('/cp/client-rooms/'.$room->id.'/tasks', [
            'title' => 'Noch im Entwurf',
            'published_status' => 'draft',
        ])->assertRedirect();

        // The Control Panel has both, and says which is which.
        $this->actingAs($staff)->get('/cp/client-rooms/'.$room->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('tasks', 2)
                ->where('tasks.0.title', 'Fertig, sichtbar')
                ->where('tasks.0.draft', false)
                ->where('tasks.1.title', 'Noch im Entwurf')
                ->where('tasks.1.draft', true));

        // The client has one, and never learns the other exists.
        $this->actAsClient();

        $this->assertSame('[Fertig, sichtbar]', $this->parse($this->tasks));

        $html = view('statamic-clientrooms::room')->render();

        $this->assertStringContainsString('Fertig, sichtbar', $html);
        $this->assertStringNotContainsString('Noch im Entwurf', $html);
    }

    #[Test]
    public function an_archived_task_is_not_in_the_room_either(): void
    {
        $room = $this->room();
        ClientRooms::addTask($room, 'Abgelegt', null, null, ['published_status' => 'archived']);
        ClientRooms::addTask($room, 'Sichtbar');

        $this->actAsClient();

        $this->assertSame('[Sichtbar]', $this->parse($this->tasks));
    }

    #[Test]
    public function a_task_written_straight_into_the_table_stays_out_of_the_room(): void
    {
        // An import, a fixture, a hand-written row: no opinion about the
        // client was expressed, so the column's default keeps it on the desk.
        $room = $this->room();

        DB::table('client_room_tasks')->insert([
            'room_id' => $room->id,
            'title' => 'Ohne Meinung eingespielt',
            'position' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertSame('draft', $room->tasks()->firstOrFail()->published_status);

        $this->actAsClient();

        $this->assertSame('', $this->parse($this->tasks));
    }

    #[Test]
    public function taking_a_task_back_to_draft_takes_it_out_of_the_room(): void
    {
        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Erst sichtbar');

        $this->actAsClient();
        $this->assertSame('[Erst sichtbar]', $this->parse($this->tasks));

        ClientRooms::publishTask($task, false);

        $this->assertSame('', $this->parse($this->tasks));

        ClientRooms::publishTask($task);

        $this->assertSame('[Erst sichtbar]', $this->parse($this->tasks));
    }

    // ── The way back ────────────────────────────────────────────────────────

    #[Test]
    public function the_migration_goes_back_and_forth_and_keeps_what_was_visible(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_09_03_000000_add_task_fields_to_client_room_tasks.php';

        $room = $this->room();

        $migration->down();

        $this->assertFalse(Schema::hasColumn('client_room_tasks', 'published_status'));
        $this->assertTrue(Schema::hasColumn('client_room_tasks', 'title'), 'down() took more than its own six columns.');

        // A room that ran 0.1.0: every task there was visible, because nothing
        // yet existed that could hide one.
        DB::table('client_room_tasks')->insert([
            'room_id' => $room->id,
            'title' => 'Aus der alten Fassung',
            'position' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $migration->up();

        $this->assertSame('published', DB::table('client_room_tasks')->value('published_status'));

        $this->actAsClient();

        $this->assertSame('[Aus der alten Fassung]', $this->parse($this->tasks));
    }

    // ── The fields themselves ───────────────────────────────────────────────

    #[Test]
    public function the_facade_writes_every_field_and_publishes_by_default(): void
    {
        $room = $this->room();

        $task = ClientRooms::addTask($room, 'Atemübung', now()->addWeek(), null, [
            'description' => "Zwei Minuten.\nJeden Morgen.",
            'type' => 'exercise',
            'priority' => 'high',
            'estimated_minutes' => '15',
            'ignoriert' => 'dieser Schlüssel gehört nicht hierher',
        ]);

        $this->assertSame("Zwei Minuten.\nJeden Morgen.", $task->description);
        $this->assertSame('exercise', $task->type);
        $this->assertSame('high', $task->priority);
        $this->assertSame(15, $task->estimated_minutes);
        $this->assertSame('assigned', $task->status);
        $this->assertSame('published', $task->published_status);
        $this->assertTrue($task->isPublished());
    }

    #[Test]
    public function an_update_touches_only_what_it_was_handed(): void
    {
        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Alt', null, null, [
            'description' => 'bleibt',
            'type' => 'exercise',
            'priority' => 'low',
        ]);

        ClientRooms::updateTask($task, ['title' => '  Neu  ', 'priority' => 'urgent']);

        $task->refresh();

        $this->assertSame('Neu', $task->title);
        $this->assertSame('urgent', $task->priority);
        $this->assertSame('bleibt', $task->description);
        $this->assertSame('exercise', $task->type);

        // An empty string is a cleared field, not the string ''.
        ClientRooms::updateTask($task, ['description' => '', 'estimated_minutes' => '']);

        $task->refresh();

        $this->assertNull($task->description);
        $this->assertNull($task->estimated_minutes);
    }

    #[Test]
    public function published_status_cannot_be_emptied_into_nothing(): void
    {
        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Aufgabe');

        ClientRooms::updateTask($task, ['published_status' => null]);

        // Not null, and not published either: the safe side.
        $this->assertSame('draft', $task->refresh()->published_status);
    }

    #[Test]
    public function an_update_without_a_title_is_refused(): void
    {
        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Aufgabe');

        $this->expectException(\InvalidArgumentException::class);

        ClientRooms::updateTask($task, ['title' => '   ']);
    }

    // ── The tick and the state, which are not the same thing ────────────────

    #[Test]
    public function ticking_a_task_moves_its_state_and_unticking_moves_it_back(): void
    {
        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Aufnahme schicken');

        ClientRooms::completeTask($task);

        $this->assertSame('completed', $task->refresh()->status);
        $this->assertSame('completed', $task->workflowStatus());

        ClientRooms::reopenTask($task);

        $this->assertSame('assigned', $task->refresh()->status);
        $this->assertNull($task->done_at);
    }

    #[Test]
    public function a_task_that_was_called_off_is_not_called_overdue(): void
    {
        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Fällt aus', now()->subWeek(), null, ['status' => 'cancelled']);

        // Overdue by the calendar, but nobody owes work that was called off.
        $this->assertTrue($task->due_at->isPast());
        $this->assertSame('cancelled', $task->workflowStatus());
    }

    #[Test]
    public function unticking_says_open_again_rather_than_guessing_the_old_word(): void
    {
        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Fällt aus', null, null, ['status' => 'cancelled']);

        ClientRooms::completeTask($task);

        $this->assertSame('completed', $task->refresh()->status);

        ClientRooms::reopenTask($task);

        // Not 'cancelled': the tick overwrote that, and restoring a word from
        // before the tick would be a guess. The coach calls it off again.
        $this->assertSame('assigned', $task->refresh()->status);
    }

    #[Test]
    public function a_task_past_its_date_reads_overdue_without_anybody_writing_it(): void
    {
        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Längst fällig', now()->subDay());

        $this->assertSame('assigned', $task->status);
        $this->assertSame('overdue', $task->workflowStatus());
    }

    // ── The Control Panel ───────────────────────────────────────────────────

    #[Test]
    public function the_control_panel_form_writes_the_new_fields(): void
    {
        $room = $this->room();
        $user = $this->superUser();

        $this->actingAs($user)->post('/cp/client-rooms/'.$room->id.'/tasks', [
            'title' => 'Atemübung',
            'due_at' => now()->addDays(2)->toDateString(),
            'description' => 'Zwei Minuten.',
            'type' => 'exercise',
            'priority' => 'high',
            'estimated_minutes' => 15,
            'published_status' => 'draft',
        ])->assertRedirect();

        $task = $room->tasks()->firstOrFail();

        $this->assertSame('Zwei Minuten.', $task->description);
        $this->assertSame('exercise', $task->type);
        $this->assertSame('high', $task->priority);
        $this->assertSame(15, $task->estimated_minutes);
        $this->assertSame('draft', $task->published_status);
    }

    #[Test]
    public function the_control_panel_edits_a_task_without_touching_the_tick(): void
    {
        $room = $this->room();
        $user = $this->superUser();
        $task = ClientRooms::addTask($room, 'Alt');

        ClientRooms::completeTask($task);

        $this->actingAs($user)->patch('/cp/client-rooms/'.$room->id.'/tasks/'.$task->id, [
            'title' => 'Neu',
            'description' => 'Genauer beschrieben.',
            'status' => 'in-progress',
            'published_status' => 'published',
        ])->assertRedirect();

        $task->refresh();

        $this->assertSame('Neu', $task->title);
        $this->assertSame('Genauer beschrieben.', $task->description);
        $this->assertSame('in-progress', $task->status);
        // The tick is not a form field. It was set and it stays set.
        $this->assertTrue($task->isDone());
    }

    #[Test]
    public function the_visibility_switch_alone_is_enough(): void
    {
        $room = $this->room();
        $user = $this->superUser();
        $task = ClientRooms::addTask($room, 'Aufgabe');

        $this->actingAs($user)->patch('/cp/client-rooms/'.$room->id.'/tasks/'.$task->id, [
            'published_status' => 'draft',
        ])->assertRedirect();

        $this->assertSame('draft', $task->refresh()->published_status);
        $this->assertSame('Aufgabe', $task->title);
    }

    #[Test]
    public function a_word_the_addon_does_not_know_is_refused(): void
    {
        $room = $this->room();
        $user = $this->superUser();

        foreach ([
            ['title' => 'x', 'status' => 'in_progress'],   // underscore, not the wire value
            ['title' => 'x', 'published_status' => 'live'],
            ['title' => 'x', 'priority' => 'sofort'],
            ['title' => 'x', 'type' => 'was-auch-immer'],
            ['title' => 'x', 'estimated_minutes' => -5],
        ] as $payload) {
            $this->actingAs($user)
                ->postJson('/cp/client-rooms/'.$room->id.'/tasks', $payload)
                ->assertStatus(422);
        }

        $this->assertSame(0, $room->tasks()->count());
    }

    #[Test]
    public function the_room_screen_carries_the_fields_and_the_vocabularies(): void
    {
        $room = $this->room();
        ClientRooms::addTask($room, 'Atemübung', null, null, [
            'description' => 'Zwei Minuten.',
            'type' => 'exercise',
            'priority' => 'urgent',
            'estimated_minutes' => 15,
            'published_status' => 'draft',
        ]);

        $this->actingAs($this->superUser())->get('/cp/client-rooms/'.$room->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tasks.0.description', 'Zwei Minuten.')
                ->where('tasks.0.type', 'exercise')
                ->where('tasks.0.priority', 'urgent')
                ->where('tasks.0.estimated_minutes', 15)
                ->where('tasks.0.published_status', 'draft')
                ->where('tasks.0.draft', true)
                ->where('tasks.0.published', false)
                ->where('tasks.0.workflow_status', 'assigned')
                ->has('taskOptions.types', 5)
                // `completed` and `overdue` are never offered: one is the tick,
                // the other is the calendar.
                ->has('taskOptions.statuses', 3)
                ->has('taskOptions.priorities', 4));
    }

    #[Test]
    public function a_type_with_no_translation_shows_as_it_is_written(): void
    {
        config()->set('statamic-clientrooms.task_types', ['exercise', 'stimmsitz']);

        $room = $this->room();
        ClientRooms::addTask($room, 'Aufgabe', null, null, ['type' => 'stimmsitz']);

        $this->actingAs($this->superUser())->get('/cp/client-rooms/'.$room->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('tasks.0.type_label', 'stimmsitz')
                ->has('taskOptions.types', 2));
    }

    // ── What the room hands the template ────────────────────────────────────

    #[Test]
    public function the_room_hands_over_the_new_fields_escaped(): void
    {
        $room = $this->room();
        ClientRooms::addTask($room, 'Aufgabe', now()->subDay(), null, [
            'description' => '<script>alert(1)</script>',
            'type' => 'exercise',
            'priority' => 'urgent',
            'estimated_minutes' => 15,
        ]);

        $this->actAsClient();

        $out = $this->parse(
            '{{ client_room }}{{ tasks }}{{ description }}|{{ type }}|{{ priority }}|{{ status }}|{{ estimated_minutes }}|{{ if overdue }}JA{{ else }}NEIN{{ /if }}{{ /tasks }}{{ /client_room }}'
        );

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $out);
        $this->assertStringContainsString('|exercise|urgent|overdue|15|JA', $out);
    }

    #[Test]
    public function the_model_knows_its_own_vocabularies(): void
    {
        // A guard against a rename that would quietly break an import: the
        // hyphen in `in-progress` is the wire value, not a typo.
        $this->assertContains('in-progress', ClientRoomTask::STATUSES);
        $this->assertSame(['draft', 'published', 'archived'], ClientRoomTask::PUBLISHED_STATUSES);
        $this->assertSame(['low', 'medium', 'high', 'urgent'], ClientRoomTask::PRIORITIES);
    }
}
