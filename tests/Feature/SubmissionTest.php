<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Events\ClientRoomTaskSubmitted;
use Goldnead\ClientRooms\Facades\ClientRooms;
use Goldnead\ClientRooms\Models\ClientRoomTaskSubmission;
use Goldnead\ClientRooms\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Antlers;

/**
 * What the client hands back, and who may read it afterwards.
 *
 * The submission is the first thing in this addon that travels from the client
 * towards the coach rather than the other way, so the questions are inverted:
 * not "may they see it" but "is it still theirs, and does it stay out of the
 * lists that are not about them".
 */
class SubmissionTest extends TestCase
{
    protected function parse(string $template): string
    {
        return (string) Antlers::parse($template, [], true);
    }

    protected function actAsClient(string $email = 'maria@example.com'): void
    {
        $user = $this->userWithPermission();
        $user->email($email)->save();

        $this->actingAs($user);
    }

    // ── Handing in ──────────────────────────────────────────────────────────

    #[Test]
    public function a_submission_carries_text_and_files(): void
    {
        $this->makeContainer();

        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Aufnahme schicken');

        $submission = ClientRooms::submitTask($task, '  Hier ist meine Aufnahme.  ', [
            UploadedFile::fake()->create('aufnahme.mp3', 12),
            UploadedFile::fake()->create('notizen.pdf', 4),
        ]);

        $this->assertSame('Hier ist meine Aufnahme.', $submission->body);
        $this->assertCount(2, $submission->files);
        $this->assertSame('aufnahme.mp3', $submission->files[0]->filename());
        $this->assertGreaterThan(0, $submission->files[0]->size);
        $this->assertNotNull($submission->submitted_at);

        // Its own folder inside the room's, so the coach can tell what they
        // shared from what came back.
        $this->assertStringContainsString('room-'.$room->id.'/submissions/'.$submission->id, $submission->files[0]->path);
    }

    #[Test]
    public function text_alone_and_a_file_alone_are_both_enough(): void
    {
        $this->makeContainer();

        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Aufgabe');

        $this->assertNotNull(ClientRooms::submitTask($task, 'Nur Text')->id);
        $this->assertNotNull(ClientRooms::submitTask($task, null, [UploadedFile::fake()->create('nur-datei.pdf', 2)])->id);

        $this->assertSame(2, $task->submissions()->count());
    }

    #[Test]
    public function an_empty_submission_is_refused(): void
    {
        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Aufgabe');

        $this->expectException(\InvalidArgumentException::class);

        // Neither text nor a file: nothing arrived, and a row here would light
        // up the coach's screen as though something had.
        ClientRooms::submitTask($task, '   ');
    }

    #[Test]
    public function a_second_attempt_is_a_second_submission(): void
    {
        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Aufgabe');

        ClientRooms::submitTask($task, 'Erster Versuch');
        ClientRooms::submitTask($task, 'Zweiter Versuch');

        // Not overwritten: the coach can see there was a first one.
        $this->assertSame(2, $task->submissions()->count());
        $this->assertSame('Erster Versuch', $task->submissions()->first()->body);
    }

    #[Test]
    public function handing_in_says_so_and_is_not_the_same_as_being_done(): void
    {
        Event::fake([ClientRoomTaskSubmitted::class]);

        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Aufgabe');

        ClientRooms::submitTask($task, 'Fertig von meiner Seite');

        Event::assertDispatched(ClientRoomTaskSubmitted::class);

        // Handing in is the client's claim; the tick is the coach's. One does
        // not make the other.
        $this->assertFalse($task->refresh()->isDone());
        $this->assertTrue($task->isSubmitted());
    }

    #[Test]
    public function handing_in_moves_the_room_up_the_listing(): void
    {
        $room = $this->room(['last_activity_at' => now()->subMonth()]);
        $task = ClientRooms::addTask($room, 'Aufgabe');

        $before = $room->refresh()->last_activity_at;

        $this->travel(2)->minutes();
        ClientRooms::submitTask($task, 'Da ist es');

        $this->assertTrue($room->refresh()->last_activity_at->greaterThan($before));
    }

    // ── The two file tables stay apart ──────────────────────────────────────

    #[Test]
    public function a_submission_file_is_not_a_room_document(): void
    {
        $this->makeContainer();

        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Aufgabe');

        ClientRooms::attach($room, UploadedFile::fake()->create('vom-coach.pdf', 4), 'Übungsplan');
        ClientRooms::submitTask($task, null, [UploadedFile::fake()->create('vom-klienten.pdf', 4)]);

        // The coach's document list is about what the coach shared. A client's
        // upload appearing there would be a different thing wearing the same
        // label — which is why they are two tables and not one with a flag.
        $this->assertSame(1, $room->files()->count());
        $this->assertSame('vom-coach.pdf', basename($room->files()->firstOrFail()->path));

        $this->actAsClient();

        $out = $this->parse('{{ client_room }}{{ files }}[{{ filename }}]{{ /files }}{{ /client_room }}');

        $this->assertSame('[vom-coach.pdf]', $out);
    }

    // ── Reading it back ─────────────────────────────────────────────────────

    #[Test]
    public function the_client_sees_their_own_submission_in_their_room(): void
    {
        $this->makeContainer();

        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Aufnahme schicken');
        ClientRooms::submitTask($task, 'Hier ist sie.', [UploadedFile::fake()->create('aufnahme.mp3', 6)]);

        $this->actAsClient();

        $out = $this->parse(
            '{{ client_room }}{{ tasks }}{{ if submitted }}JA:{{ submission_count }}{{ submissions }}'
            .'({{ body }}{{ files }}|{{ filename }}={{ url }}{{ /files }}){{ /submissions }}'
            .'{{ else }}NEIN{{ /if }}{{ /tasks }}{{ /client_room }}'
        );

        $this->assertStringStartsWith('JA:1(Hier ist sie.|aufnahme.mp3=', $out);
        $this->assertStringContainsString('/!/statamic-clientrooms/submissions/', $out);
        $this->assertStringContainsString('signature=', $out);

        // Never the storage path.
        $this->assertStringNotContainsString('room-'.$room->id, $out);
    }

    #[Test]
    public function a_submission_on_a_draft_task_does_not_reach_the_room_either(): void
    {
        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Noch im Entwurf', null, null, ['published_status' => 'draft']);
        ClientRooms::submitTask($task, 'Geheime Abgabe');

        $this->actAsClient();

        // The task is not in the list, so nothing hanging off it is either.
        $this->assertStringNotContainsString('Geheime Abgabe', $this->parse(
            '{{ client_room }}{{ tasks }}{{ submissions }}{{ body }}{{ /submissions }}{{ /tasks }}{{ /client_room }}'
        ));
    }

    #[Test]
    public function free_text_in_a_submission_arrives_escaped(): void
    {
        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Aufgabe');
        ClientRooms::submitTask($task, '<script>alert(1)</script>');

        $this->actAsClient();

        $out = $this->parse('{{ client_room }}{{ tasks }}{{ submissions }}{{ body }}{{ /submissions }}{{ /tasks }}{{ /client_room }}');

        $this->assertStringNotContainsString('<script>', $out);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $out);
    }

    // ── The download ────────────────────────────────────────────────────────

    #[Test]
    public function the_signed_link_works_and_stops_working(): void
    {
        $this->makeContainer();

        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Aufgabe');
        $submission = ClientRooms::submitTask($task, null, [UploadedFile::fake()->create('aufnahme.mp3', 6)]);

        $url = ClientRooms::submissionDownloadUrl($submission->files[0]);

        $this->get($url)->assertOk();

        // The window is the configured one; a link found later is a dead link.
        $this->travel(31)->minutes();

        $this->get($url)->assertForbidden();
    }

    #[Test]
    public function an_unsigned_or_edited_link_is_refused(): void
    {
        $this->makeContainer();

        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Aufgabe');
        $submission = ClientRooms::submitTask($task, null, [UploadedFile::fake()->create('aufnahme.mp3', 6)]);
        $file = $submission->files[0];

        $url = ClientRooms::submissionDownloadUrl($file);

        $this->get('/!/statamic-clientrooms/submissions/'.$file->id)->assertForbidden();
        $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature=deadbeef', $url))->assertForbidden();

        // A valid signature is bound to its own id and cannot be walked to the
        // next one.
        $this->get(str_replace('/submissions/'.$file->id, '/submissions/'.($file->id + 1), $url))->assertForbidden();
    }

    #[Test]
    public function a_closed_room_hands_out_nothing(): void
    {
        $this->makeContainer();

        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Aufgabe');
        $submission = ClientRooms::submitTask($task, null, [UploadedFile::fake()->create('aufnahme.mp3', 6)]);

        $url = ClientRooms::submissionDownloadUrl($submission->files[0]);

        ClientRooms::close($room);

        // 404, not 403, like the document route: a 403 would confirm the file
        // is there, and a dead link should not double as an existence oracle.
        $this->get($url)->assertNotFound();
    }

    #[Test]
    public function a_link_to_a_file_that_left_the_container_is_not_a_server_error(): void
    {
        $this->makeContainer();

        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Aufgabe');
        $submission = ClientRooms::submitTask($task, null, [UploadedFile::fake()->create('aufnahme.mp3', 6)]);
        $file = $submission->files[0];

        $url = ClientRooms::submissionDownloadUrl($file);

        $file->asset()?->delete();

        $this->get($url)->assertNotFound();
    }

    // ── Taking it back out ──────────────────────────────────────────────────

    #[Test]
    public function removing_a_submission_takes_its_files_off_the_disk(): void
    {
        $this->makeContainer();

        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Aufgabe');
        $submission = ClientRooms::submitTask($task, 'Text', [UploadedFile::fake()->create('aufnahme.mp3', 6)]);
        $file = $submission->files[0];

        $this->assertNotNull($file->asset());

        ClientRooms::removeSubmission($submission);

        $this->assertSame(0, $task->submissions()->count());
        $this->assertSame(0, $file->newQuery()->count());
        // Not just the row: the asset would otherwise sit in the container
        // forever with nothing pointing at it.
        $this->assertNull($file->fresh()?->asset());
    }

    #[Test]
    public function deleting_a_task_takes_its_submissions_with_it(): void
    {
        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Aufgabe');
        ClientRooms::submitTask($task, 'Abgabe');

        $task->delete();

        $this->assertSame(0, ClientRoomTaskSubmission::query()->count());
    }

    // ── The way back ────────────────────────────────────────────────────────

    #[Test]
    public function the_migration_goes_back_and_forth(): void
    {
        $migration = require __DIR__.'/../../database/migrations/2026_09_04_000001_create_client_room_task_submission_tables.php';

        $migration->down();

        $this->assertFalse(Schema::hasTable('client_room_task_submissions'));
        $this->assertFalse(Schema::hasTable('client_room_task_submission_files'));
        // Its own two tables and nothing else.
        $this->assertTrue(Schema::hasTable('client_room_tasks'));
        $this->assertTrue(Schema::hasTable('client_room_files'));

        $migration->up();

        $this->assertTrue(Schema::hasTable('client_room_task_submissions'));
        $this->assertTrue(Schema::hasTable('client_room_task_submission_files'));
    }

    #[Test]
    public function a_task_can_remember_where_it_was_imported_from(): void
    {
        // The column Schritt 5 needs to be idempotent. Nothing in the addon
        // reasons about what is in it.
        $room = $this->room();
        $task = ClientRooms::addTask($room, 'Aufgabe', null, null, [
            'meta' => ['vf_task_id' => '11111111-2222-3333-4444-555555555555'],
        ]);

        $this->assertSame('11111111-2222-3333-4444-555555555555', $task->refresh()->meta['vf_task_id']);
    }
}
