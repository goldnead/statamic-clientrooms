<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Facades\ClientRooms;
use Goldnead\ClientRooms\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

/**
 * The members area as JSON: your room, and no way to ask for another.
 *
 * Every test here asks from the outside — over HTTP, as a signed-in person —
 * because that is how the front end will ask. The boundary is not "does the
 * response look right" but "can this person reach something that is not
 * theirs", so most of these assert a 404.
 */
class MemberApiTest extends TestCase
{
    protected string $base = '/!/statamic-clientrooms/me';

    /** A site member: signed in, no Control Panel, no permissions. */
    protected function client(string $email = 'maria@example.com')
    {
        return $this->frontendUser($email);
    }

    // ── Who gets in ─────────────────────────────────────────────────────────

    #[Test]
    public function nobody_signed_in_gets_no_room(): void
    {
        ClientRooms::open('maria@example.com');

        // `auth` answers before the controller does. Either way, no data.
        $this->getJson($this->base)->assertUnauthorized();
    }

    #[Test]
    public function a_signed_out_caller_gets_json_even_without_asking_for_it(): void
    {
        ClientRooms::open('maria@example.com');

        // `getJson()` would set the Accept header itself and prove nothing:
        // `auth` reads that header as it throws, and decides between a JSON
        // 401 and a redirect to a login page. A `fetch()` with a FormData
        // sends no such header, so this asks the way that front end does.
        $response = $this->call('GET', $this->base);

        $response->assertUnauthorized();
        $this->assertStringContainsString('application/json', (string) $response->headers->get('Content-Type'));
    }

    #[Test]
    public function a_user_without_a_room_gets_a_404_and_not_an_empty_room(): void
    {
        ClientRooms::open('maria@example.com');

        $this->actingAs($this->client('jonas@example.com'))
            ->getJson($this->base)
            ->assertNotFound();
    }

    #[Test]
    public function a_closed_room_is_gone_from_the_members_area(): void
    {
        $room = ClientRooms::open('maria@example.com');
        ClientRooms::close($room);

        $this->actingAs($this->client())->getJson($this->base)->assertNotFound();
    }

    #[Test]
    public function the_room_comes_back_with_what_the_client_may_see(): void
    {
        $this->makeContainer();

        $room = ClientRooms::open('maria@example.com', null, ['name' => 'Maria Beispiel']);
        $room->forceFill(['notes' => 'GEHEIM intern', 'client_notes' => 'Übe täglich.'])->save();

        ClientRooms::addTask($room, 'Aufnahme schicken', null, null, [
            'description' => 'Zwei Minuten.',
            'type' => 'exercise',
            'priority' => 'high',
            'estimated_minutes' => 15,
        ]);

        ClientRooms::attach($room, UploadedFile::fake()->create('plan.pdf', 4), 'Plan');
        ClientRooms::attach($room, UploadedFile::fake()->create('intern.pdf', 4), 'Intern', false);

        $response = $this->actingAs($this->client())->getJson($this->base)->assertOk();

        $response
            ->assertJsonPath('room.name', 'Maria Beispiel')
            ->assertJsonPath('room.notes_for_client', 'Übe täglich.')
            ->assertJsonPath('tasks.0.title', 'Aufnahme schicken')
            ->assertJsonPath('tasks.0.description', 'Zwei Minuten.')
            ->assertJsonPath('tasks.0.type', 'exercise')
            ->assertJsonPath('tasks.0.status', 'assigned')
            ->assertJsonPath('tasks.0.estimated_minutes', 15)
            ->assertJsonCount(1, 'files')
            ->assertJsonPath('files.0.filename', 'plan.pdf');

        $body = $response->getContent();

        // The coach's own notes, the hidden document and the storage path are
        // not in the payload at all.
        $this->assertStringNotContainsString('GEHEIM', $body);
        $this->assertStringNotContainsString('intern.pdf', $body);
        $this->assertStringNotContainsString('room-'.$room->id, $body);
    }

    #[Test]
    public function json_is_data_and_arrives_unescaped(): void
    {
        // The tag escapes because Antlers prints into a document. Here the
        // front end renders, and `&amp;` would reach the client literally.
        $room = ClientRooms::open('maria@example.com', null, ['name' => 'Müller & Söhne']);
        ClientRooms::addTask($room, 'Übung <b>fett</b>');

        $this->actingAs($this->client())->getJson($this->base)
            ->assertOk()
            ->assertJsonPath('room.name', 'Müller & Söhne')
            ->assertJsonPath('tasks.0.title', 'Übung <b>fett</b>');
    }

    // ── The line the coach drew still holds ─────────────────────────────────

    #[Test]
    public function a_draft_task_is_neither_listed_nor_addressable(): void
    {
        $room = ClientRooms::open('maria@example.com');
        $draft = ClientRooms::addTask($room, 'Noch im Entwurf', null, null, ['published_status' => 'draft']);
        ClientRooms::addTask($room, 'Sichtbar');

        $client = $this->client();

        $this->actingAs($client)->getJson($this->base)
            ->assertOk()
            ->assertJsonCount(1, 'tasks')
            ->assertJsonPath('tasks.0.title', 'Sichtbar');

        // Guessing the id gets the same answer as a task that never existed.
        $this->actingAs($client)->patchJson($this->base.'/tasks/'.$draft->id, ['done' => true])->assertNotFound();
        $this->actingAs($client)->postJson($this->base.'/tasks/'.$draft->id.'/submissions', ['body' => 'x'])->assertNotFound();

        $this->assertFalse($draft->refresh()->isDone());
        $this->assertSame(0, $draft->submissions()->count());
    }

    #[Test]
    public function another_clients_task_cannot_be_touched(): void
    {
        $mine = ClientRooms::open('maria@example.com');
        $theirs = ClientRooms::open('jonas@example.com');

        ClientRooms::addTask($mine, 'Meine');
        $other = ClientRooms::addTask($theirs, 'Fremde');

        $client = $this->client();

        $this->actingAs($client)->getJson($this->base)
            ->assertOk()
            ->assertJsonCount(1, 'tasks')
            ->assertJsonPath('tasks.0.title', 'Meine');

        $this->actingAs($client)->patchJson($this->base.'/tasks/'.$other->id, ['done' => true])->assertNotFound();
        $this->actingAs($client)->postJson($this->base.'/tasks/'.$other->id.'/submissions', ['body' => 'x'])->assertNotFound();

        $this->assertFalse($other->refresh()->isDone());
        $this->assertSame(0, $other->submissions()->count());
    }

    #[Test]
    public function one_address_with_a_room_in_two_brands_gets_the_current_brands_room(): void
    {
        $manager = $this->fakeBrandContext(current: 1);

        $first = ClientRooms::open('maria@example.com', null, ['brand_id' => 1]);
        $second = ClientRooms::open('maria@example.com', null, ['brand_id' => 2]);

        ClientRooms::addTask($first, 'Nordlicht');
        ClientRooms::addTask($second, 'Chorwerkstatt');

        $this->assertNotSame($first->id, $second->id, 'The unique key is per brand; two rooms were expected.');

        $client = $this->client();

        $this->actingAs($client)->getJson($this->base)
            ->assertOk()
            ->assertJsonCount(1, 'tasks')
            ->assertJsonPath('tasks.0.title', 'Nordlicht');

        $manager->setCurrent(2);

        $this->actingAs($client)->getJson($this->base)
            ->assertOk()
            ->assertJsonCount(1, 'tasks')
            ->assertJsonPath('tasks.0.title', 'Chorwerkstatt');
    }

    // ── Ticking ─────────────────────────────────────────────────────────────

    #[Test]
    public function the_client_ticks_a_task_and_takes_it_back(): void
    {
        $room = ClientRooms::open('maria@example.com');
        $task = ClientRooms::addTask($room, 'Aufnahme schicken');
        $client = $this->client();

        $this->actingAs($client)->patchJson($this->base.'/tasks/'.$task->id, ['done' => true])
            ->assertOk()
            ->assertJsonPath('task.done', true)
            ->assertJsonPath('task.status', 'completed');

        $task->refresh();
        $this->assertTrue($task->isDone());
        // Recorded as theirs, not as the coach's.
        $this->assertSame((string) $client->getAuthIdentifier(), $task->done_by);

        $this->actingAs($client)->patchJson($this->base.'/tasks/'.$task->id, ['done' => false])
            ->assertOk()
            ->assertJsonPath('task.done', false);

        $this->assertFalse($task->refresh()->isDone());
    }

    #[Test]
    public function the_tick_needs_to_say_which_way(): void
    {
        $room = ClientRooms::open('maria@example.com');
        $task = ClientRooms::addTask($room, 'Aufgabe');

        $this->actingAs($this->client())
            ->patchJson($this->base.'/tasks/'.$task->id, [])
            ->assertStatus(422);
    }

    // ── Handing back ────────────────────────────────────────────────────────

    #[Test]
    public function the_client_hands_a_task_back_with_a_file(): void
    {
        $this->makeContainer();

        $room = ClientRooms::open('maria@example.com');
        $task = ClientRooms::addTask($room, 'Aufnahme schicken');
        $client = $this->client();

        $response = $this->actingAs($client)->post($this->base.'/tasks/'.$task->id.'/submissions', [
            'body' => 'Hier ist sie.',
            'files' => [UploadedFile::fake()->create('aufnahme.mp3', 12)],
        ])->assertCreated();

        $response
            ->assertJsonPath('submission.body', 'Hier ist sie.')
            ->assertJsonPath('submission.files.0.filename', 'aufnahme.mp3');

        $submission = $task->submissions()->firstOrFail();
        $this->assertSame((string) $client->getAuthIdentifier(), $submission->submitted_by);
        $this->assertCount(1, $submission->files);

        // A link that expires, never the path.
        $url = $response->json('submission.files.0.url');
        $this->assertStringContainsString('/!/statamic-clientrooms/submissions/', $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString('room-'.$room->id, $response->getContent());

        // And it works, for them.
        $this->get($url)->assertOk();
    }

    #[Test]
    public function a_submission_with_neither_text_nor_file_is_refused_on_the_field(): void
    {
        $room = ClientRooms::open('maria@example.com');
        $task = ClientRooms::addTask($room, 'Aufgabe');

        // A form error, not a 500: the front end has somewhere to show it.
        $this->actingAs($this->client())
            ->postJson($this->base.'/tasks/'.$task->id.'/submissions', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors('body');

        $this->assertSame(0, $task->submissions()->count());
    }

    #[Test]
    public function an_extension_the_room_does_not_accept_is_refused(): void
    {
        config()->set('statamic-clientrooms.allowed_extensions', ['mp3']);

        $room = ClientRooms::open('maria@example.com');
        $task = ClientRooms::addTask($room, 'Aufgabe');

        $this->actingAs($this->client())->post($this->base.'/tasks/'.$task->id.'/submissions', [
            'files' => [UploadedFile::fake()->create('schadhaft.php', 2)],
        ])->assertStatus(422);

        $this->assertSame(0, $task->submissions()->count());
    }

    #[Test]
    public function a_file_over_the_cap_is_refused(): void
    {
        config()->set('statamic-clientrooms.member_upload_max_kb', 10);

        $room = ClientRooms::open('maria@example.com');
        $task = ClientRooms::addTask($room, 'Aufgabe');

        $this->actingAs($this->client())->post($this->base.'/tasks/'.$task->id.'/submissions', [
            'files' => [UploadedFile::fake()->create('zu-gross.mp3', 64)],
        ])->assertStatus(422);

        $this->assertSame(0, $task->submissions()->count());
    }
}
