<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Events\ClientRoomClosed;
use Goldnead\ClientRooms\Events\ClientRoomOpened;
use Goldnead\ClientRooms\Events\ClientRoomTaskCompleted;
use Goldnead\ClientRooms\Facades\ClientRooms;
use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\Test;

/**
 * The promises of the facade, which are the promises of the README.
 */
class FacadeTest extends TestCase
{
    #[Test]
    public function open_creates_one_room_per_address_and_normalises_it(): void
    {
        Event::fake([ClientRoomOpened::class]);

        $first = ClientRooms::open('  Maria@Example.com ', null, ['name' => 'Maria Beispiel']);
        $second = ClientRooms::open('maria@example.com');

        $this->assertSame($first->id, $second->id);
        $this->assertSame('maria@example.com', $first->email);
        $this->assertSame('Maria Beispiel', $first->name);
        $this->assertTrue($first->isOpen());
        $this->assertNotNull($first->opened_at);
        $this->assertSame(1, ClientRoom::query()->count());

        // Opened once, and an `open()` on an open room fires nothing.
        Event::assertDispatchedTimes(ClientRoomOpened::class, 1);
        Event::assertDispatched(ClientRoomOpened::class, fn (ClientRoomOpened $e) => $e->reopened === false);
    }

    #[Test]
    public function open_accepts_an_object_with_an_email(): void
    {
        $room = ClientRooms::open((object) ['email' => 'Jonas@Example.com']);

        $this->assertSame('jonas@example.com', $room->email);
    }

    #[Test]
    public function open_refuses_an_empty_address(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ClientRooms::open('   ');
    }

    #[Test]
    public function close_then_open_reopens_the_same_room(): void
    {
        Event::fake([ClientRoomOpened::class, ClientRoomClosed::class]);

        $room = ClientRooms::open('maria@example.com');
        ClientRooms::close($room);

        $room->refresh();
        $this->assertFalse($room->isOpen());
        $this->assertNotNull($room->closed_at);
        Event::assertDispatchedTimes(ClientRoomClosed::class, 1);

        // Closing a closed room is a no-op.
        ClientRooms::close($room);
        Event::assertDispatchedTimes(ClientRoomClosed::class, 1);

        $again = ClientRooms::open('maria@example.com');

        $this->assertSame($room->id, $again->id);
        $this->assertTrue($again->isOpen());
        $this->assertNull($again->closed_at);
        Event::assertDispatchedTimes(ClientRoomOpened::class, 2);
        Event::assertDispatched(ClientRoomOpened::class, fn (ClientRoomOpened $e) => $e->reopened === true);
    }

    #[Test]
    public function for_email_and_for_user_find_the_room(): void
    {
        $room = ClientRooms::open('maria@example.com');

        $this->assertSame($room->id, ClientRooms::forEmail('MARIA@example.com')?->id);
        $this->assertNull(ClientRooms::forEmail('nobody@example.com'));

        $user = $this->userWithPermission();
        $user->email('maria@example.com')->save();

        $this->assertSame($room->id, ClientRooms::forUser($user)?->id);
        $this->assertNull(ClientRooms::forUser(null));
    }

    #[Test]
    public function tasks_are_added_and_completed_once(): void
    {
        Event::fake([ClientRoomTaskCompleted::class]);

        $room = ClientRooms::open('maria@example.com');

        $task = ClientRooms::addTask($room, '  Atemübung schicken ', now()->addWeek());
        $second = ClientRooms::addTask($room, 'Aufnahme anhören');

        $this->assertSame('Atemübung schicken', $task->title);
        $this->assertSame(1, $task->position);
        $this->assertSame(2, $second->position);
        $this->assertFalse($task->isDone());

        ClientRooms::completeTask($task);
        ClientRooms::completeTask($task->id);

        $this->assertTrue($task->refresh()->isDone());
        Event::assertDispatchedTimes(ClientRoomTaskCompleted::class, 1);

        ClientRooms::reopenTask($task);
        $this->assertFalse($task->refresh()->isDone());
    }

    #[Test]
    public function a_task_needs_a_title(): void
    {
        $room = ClientRooms::open('maria@example.com');

        $this->expectException(\InvalidArgumentException::class);

        ClientRooms::addTask($room, '   ');
    }

    #[Test]
    public function the_same_address_may_have_a_room_per_brand(): void
    {
        $one = ClientRooms::open('maria@example.com', null, ['brand_id' => 1]);
        $two = ClientRooms::open('maria@example.com', null, ['brand_id' => 2]);
        $oneAgain = ClientRooms::open('maria@example.com', null, ['brand_id' => 1]);

        $this->assertNotSame($one->id, $two->id);
        $this->assertSame($one->id, $oneAgain->id);
        $this->assertSame(2, ClientRoom::query()->count());
        $this->assertSame($two->id, ClientRooms::find('maria@example.com', 2)?->id);
    }

    #[Test]
    public function without_brand_context_every_room_carries_brand_zero(): void
    {
        $room = ClientRooms::open('maria@example.com');

        $this->assertSame(0, $room->brand_id);
    }

    #[Test]
    public function a_lost_insert_race_picks_up_the_winners_row(): void
    {
        Event::fake([ClientRoomOpened::class]);

        // The other worker wins the insert between this side's lookup and its
        // own insert. Staged from inside `creating`, which runs exactly there.
        $raced = false;
        ClientRoom::creating(function () use (&$raced): void {
            if ($raced) {
                return;
            }

            $raced = true;

            DB::table('client_rooms')->insert([
                'brand_id' => 0,
                'email' => 'maria@example.com',
                'name' => 'Vom anderen Worker',
                'status' => ClientRoom::STATUS_CLOSED,
                'opened_at' => now()->subDay(),
                'closed_at' => now()->subHour(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $room = ClientRooms::open('maria@example.com', null, ['name' => 'Von hier']);

        $this->assertSame(1, ClientRoom::query()->count());
        $this->assertSame('Vom anderen Worker', $room->name);
        // The winner's row was closed, so this side reopened it — the same
        // thing a second `open()` would have done.
        $this->assertTrue($room->isOpen());
        Event::assertDispatchedTimes(ClientRoomOpened::class, 1);
        Event::assertDispatched(ClientRoomOpened::class, fn (ClientRoomOpened $e) => $e->reopened === true);
    }

    #[Test]
    public function activity_is_stamped_by_tasks(): void
    {
        $room = ClientRooms::open('maria@example.com');
        $room->forceFill(['last_activity_at' => now()->subMonth()])->save();

        ClientRooms::addTask($room, 'Nachfassen');

        $this->assertTrue($room->refresh()->last_activity_at->greaterThan(now()->subMinute()));
    }
}
