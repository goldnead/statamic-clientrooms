<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Facades\ClientRooms;
use Goldnead\ClientRooms\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Antlers;

/**
 * `{{ client_room }}` shows a signed-in client their own room and nothing else.
 */
class TagTest extends TestCase
{
    /** The third argument marks the template as trusted; without it Antlers runs no tags. */
    protected function parse(string $template): string
    {
        return (string) Antlers::parse($template, [], true);
    }

    protected string $template = '{{ client_room }}{{ if no_results }}NONE{{ else }}[{{ name }}|{{ notes_for_client }}|{{ tasks }}{{ title }};{{ /tasks }}|{{ files }}{{ title }}={{ url }};{{ /files }}]{{ /if }}{{ /client_room }}';

    #[Test]
    public function without_a_signed_in_user_there_is_no_room(): void
    {
        ClientRooms::open('maria@example.com');

        $this->assertSame('NONE', $this->parse($this->template));
        $this->assertSame('no', $this->parse('{{ if {client_room:exists} }}yes{{ else }}no{{ /if }}'));
    }

    #[Test]
    public function a_signed_in_client_sees_their_room(): void
    {
        $this->makeContainer();

        $room = ClientRooms::open('maria@example.com', null, ['name' => 'Maria Beispiel']);
        $room->forceFill(['notes' => 'GEHEIM intern', 'client_notes' => 'Übe täglich.'])->save();
        ClientRooms::addTask($room, 'Aufnahme schicken');
        ClientRooms::attach($room, UploadedFile::fake()->create('plan.pdf', 4), 'Plan');
        ClientRooms::attach($room, UploadedFile::fake()->create('intern.pdf', 4), 'Intern', false);

        $user = $this->userWithPermission();
        $user->email('MARIA@example.com')->save();

        $this->actingAs($user);

        $out = $this->parse($this->template);

        $this->assertStringStartsWith('[Maria Beispiel|Übe täglich.|Aufnahme schicken;|Plan=', $out);
        $this->assertStringContainsString('/!/statamic-clientrooms/files/', $out);
        $this->assertStringContainsString('signature=', $out);
        // The hidden file and the internal notes never reach the template.
        $this->assertStringNotContainsString('Intern', $out);
        $this->assertStringNotContainsString('GEHEIM', $out);
        $this->assertStringNotContainsString('room-', $out);

        $this->assertSame('yes', $this->parse('{{ if {client_room:exists} }}yes{{ else }}no{{ /if }}'));
    }

    #[Test]
    public function a_closed_room_is_not_shown(): void
    {
        $room = ClientRooms::open('maria@example.com');
        ClientRooms::close($room);

        $user = $this->userWithPermission();
        $user->email('maria@example.com')->save();
        $this->actingAs($user);

        $this->assertSame('NONE', $this->parse($this->template));
    }

    #[Test]
    public function another_users_room_is_not_shown(): void
    {
        ClientRooms::open('maria@example.com');

        $user = $this->userWithPermission();
        $user->email('jonas@example.com')->save();
        $this->actingAs($user);

        $this->assertSame('NONE', $this->parse($this->template));
    }

    #[Test]
    public function the_shipped_view_renders(): void
    {
        $room = ClientRooms::open('maria@example.com', null, ['name' => 'Maria Beispiel']);
        ClientRooms::addTask($room, 'Aufnahme schicken');

        $user = $this->userWithPermission();
        $user->email('maria@example.com')->save();
        $this->actingAs($user);

        $html = view('statamic-clientrooms::room')->render();

        $this->assertStringContainsString('Maria Beispiel', $html);
        $this->assertStringContainsString('Aufnahme schicken', $html);
    }
}
