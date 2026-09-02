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
    public function free_text_arrives_escaped_and_is_not_escaped_twice(): void
    {
        $room = ClientRooms::open('maria@example.com', null, ['name' => 'Müller & Söhne <b>Chor</b>']);
        $room->forceFill(['client_notes' => "<script>alert(1)</script>\nZeile 2"])->save();
        ClientRooms::addTask($room, '<img src=x onerror=alert(2)>');

        $user = $this->userWithPermission();
        $user->email('maria@example.com')->save();
        $this->actingAs($user);

        // The raw tag: escaped by the addon, whatever the template does.
        $raw = $this->parse('{{ client_room }}{{ name }}|{{ notes_for_client }}|{{ tasks }}{{ title }}{{ /tasks }}{{ /client_room }}');

        $this->assertStringNotContainsString('<script>', $raw);
        $this->assertStringNotContainsString('<img', $raw);
        $this->assertStringNotContainsString('<b>', $raw);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $raw);
        $this->assertStringContainsString('Müller &amp; Söhne &lt;b&gt;Chor&lt;/b&gt;', $raw);

        // The shipped view on top: `sanitize:false` leaves the entities alone.
        $html = view('statamic-clientrooms::room')->render();

        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $html);
        $this->assertStringNotContainsString('&amp;lt;', $html);
        $this->assertStringNotContainsString('&amp;amp;', $html);
        $this->assertStringContainsString('Müller &amp; Söhne', $html);
        $this->assertMatchesRegularExpression('/<br\s*\/?>\s*Zeile 2/', $html);
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
