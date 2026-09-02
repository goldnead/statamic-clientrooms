<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Facades\ClientRooms;
use Goldnead\ClientRooms\Models\ClientRoomSession;
use Goldnead\ClientRooms\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The sessions panel of the room screen: what it shows, and what it refuses.
 *
 * The screen is deliberately narrow — no add form, no edit form — so most of
 * what is worth asserting here is an absence: a route that does not exist, a
 * field a reader cannot move, an id from another room that answers 404.
 */
class CpSessionScreenTest extends TestCase
{
    #[Test]
    public function the_detail_hands_the_screen_every_sitting_including_the_drafts(): void
    {
        $room = $this->room();

        ClientRooms::importSession($room, 'vf-0001', [
            'title' => 'Freigegeben',
            'published_status' => ClientRoomSession::PUBLISHED_PUBLISHED,
        ]);
        ClientRooms::importSession($room, 'vf-0002', ['title' => 'Entwurf']);

        // The client's room shows one; the coach's desk shows both, because
        // deciding about the draft is the reason to open this screen.
        $this->actingAs($this->superUser())->get('/cp/client-rooms/'.$room->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('statamic-clientrooms::Rooms/Show')
                ->has('sessions', 2));
    }

    #[Test]
    public function the_write_up_reaches_the_screen_as_text_and_not_as_markup(): void
    {
        $room = $this->room();

        ClientRooms::importSession($room, 'vf-0001', [
            'title' => 'Sitzung',
            'protocol' => '<h3>Was wir gemacht haben</h3><p>Passaggio ge&uuml;bt.</p><p>Zwei Minuten <strong>ng</strong>.</p>',
        ]);

        $this->actingAs($this->superUser())->get('/cp/client-rooms/'.$room->id)
            ->assertOk()
            ->assertInertia(function ($page) {
                $protocol = $page->toArray()['props']['sessions'][0]['protocol'];

                // No markup reaches a screen a superuser is signed in to …
                $this->assertStringNotContainsString('<', $protocol);
                $this->assertStringNotContainsString('&uuml;', $protocol);

                // … and the paragraphs the write-up was built from survive as
                // line breaks, so a sixty-minute protocol is not a wall.
                $this->assertStringContainsString('Was wir gemacht haben', $protocol);
                $this->assertStringContainsString('Passaggio geübt.', $protocol);
                $this->assertStringContainsString("\n", $protocol);
            });
    }

    #[Test]
    public function a_recording_whose_link_has_run_out_is_not_a_room_without_a_recording(): void
    {
        $room = $this->room();

        ClientRooms::importSession($room, 'vf-0001', [
            'title' => 'Sitzung',
            'recording_url' => 'https://cockpit.example.com/rec?sig=x',
            'recording_url_expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($this->superUser())->get('/cp/client-rooms/'.$room->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sessions.0.has_recording', true)
                ->where('sessions.0.recording_url', null)
                ->where('sessions.0.recording_expired', true));
    }

    #[Test]
    public function a_transcript_that_never_had_a_link_is_not_a_link_that_expired(): void
    {
        $room = $this->room();

        // The cockpit reports the fact on one route and mints the link on
        // another, so this is the ordinary state after an import — not an
        // error, and not a signature that ran out. A screen that called it
        // "expired" would tell the coach to stop asking for a link that was
        // never issued.
        ClientRooms::importSession($room, 'vf-0001', [
            'title' => 'Sitzung',
            'has_transcript' => true,
        ]);

        $this->actingAs($this->superUser())->get('/cp/client-rooms/'.$room->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sessions.0.has_transcript', true)
                ->where('sessions.0.transcript_url', null)
                ->where('sessions.0.transcript_expired', false));
    }

    #[Test]
    public function a_transcript_link_that_did_run_out_says_so(): void
    {
        $room = $this->room();

        ClientRooms::importSession($room, 'vf-0001', [
            'title' => 'Sitzung',
            'has_transcript' => true,
            'transcript_url' => 'https://cockpit.example.com/tr?sig=x',
            'transcript_url_expires_at' => now()->subMinute(),
        ]);

        $this->actingAs($this->superUser())->get('/cp/client-rooms/'.$room->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('sessions.0.has_transcript', true)
                ->where('sessions.0.transcript_url', null)
                ->where('sessions.0.transcript_expired', true));
    }

    #[Test]
    public function the_line_to_the_client_moves_from_the_screen(): void
    {
        $room = $this->room();
        $session = ClientRooms::importSession($room, 'vf-0001', ['title' => 'Sitzung']);

        $this->assertTrue($session->isDraft());

        $this->actingAs($this->superUser())
            ->patch('/cp/client-rooms/'.$room->id.'/sessions/'.$session->id, [
                'published_status' => ClientRoomSession::PUBLISHED_PUBLISHED,
            ])
            ->assertRedirect();

        $this->assertTrue($session->refresh()->isPublished());
    }

    #[Test]
    public function the_publish_switch_does_not_clear_a_note(): void
    {
        $room = $this->room();
        $session = ClientRooms::importSession($room, 'vf-0001', [
            'title' => 'Sitzung',
            'notes' => 'Sie drückt beim hohen A.',
        ]);

        // Both keys are `sometimes`, which is the point: a screen that sends
        // one field must not null the other.
        $this->actingAs($this->superUser())
            ->patch('/cp/client-rooms/'.$room->id.'/sessions/'.$session->id, [
                'published_status' => ClientRoomSession::PUBLISHED_PUBLISHED,
            ])
            ->assertRedirect();

        $this->assertSame('Sie drückt beim hohen A.', $session->refresh()->notes);
    }

    #[Test]
    public function a_note_does_not_quietly_republish_a_draft(): void
    {
        $room = $this->room();
        $session = ClientRooms::importSession($room, 'vf-0001', ['title' => 'Sitzung']);

        $this->actingAs($this->superUser())
            ->patch('/cp/client-rooms/'.$room->id.'/sessions/'.$session->id, ['notes' => 'Notiz'])
            ->assertRedirect();

        $session->refresh();

        $this->assertSame('Notiz', $session->notes);
        $this->assertTrue($session->isDraft());
    }

    #[Test]
    public function the_screen_cannot_rewrite_what_the_cockpit_wrote(): void
    {
        $room = $this->room();
        $session = ClientRooms::importSession($room, 'vf-0001', [
            'title' => 'Sitzung',
            'protocol' => 'Das Original.',
        ]);

        // There is no route to post a new sitting to …
        $this->actingAs($this->superUser())
            ->post('/cp/client-rooms/'.$room->id.'/sessions', ['title' => 'Von Hand'])
            ->assertNotFound();

        // … and the update takes two keys, so anything else is ignored rather
        // than written. Two versions of one hour is what this prevents.
        $this->actingAs($this->superUser())
            ->patch('/cp/client-rooms/'.$room->id.'/sessions/'.$session->id, [
                'title' => 'Umbenannt',
                'protocol' => 'Überschrieben.',
                'notes' => 'Notiz',
            ])
            ->assertRedirect();

        $session->refresh();

        $this->assertSame('Sitzung', $session->title);
        $this->assertSame('Das Original.', $session->protocol);
        $this->assertSame('Notiz', $session->notes);
    }

    #[Test]
    public function a_sitting_of_another_room_is_not_reachable_through_this_one(): void
    {
        $mine = $this->room();
        $theirs = $this->room(['email' => 'jonas@example.com']);

        $session = ClientRooms::importSession($theirs, 'vf-0001', ['title' => 'Fremd']);

        $this->actingAs($this->superUser())
            ->patch('/cp/client-rooms/'.$mine->id.'/sessions/'.$session->id, ['notes' => 'x'])
            ->assertNotFound();

        $this->actingAs($this->superUser())
            ->delete('/cp/client-rooms/'.$mine->id.'/sessions/'.$session->id)
            ->assertNotFound();

        $this->assertNull($session->refresh()->notes);
    }

    #[Test]
    public function a_reader_may_not_move_the_line_or_delete_a_sitting(): void
    {
        $room = $this->room();
        $session = ClientRooms::importSession($room, 'vf-0001', ['title' => 'Sitzung']);
        $reader = $this->userWithPermission('view client rooms');

        $this->actingAs($reader)->get('/cp/client-rooms/'.$room->id)->assertOk();

        $this->actingAs($reader)
            ->patchJson('/cp/client-rooms/'.$room->id.'/sessions/'.$session->id, [
                'published_status' => ClientRoomSession::PUBLISHED_PUBLISHED,
            ])
            ->assertForbidden();

        $this->actingAs($reader)
            ->deleteJson('/cp/client-rooms/'.$room->id.'/sessions/'.$session->id)
            ->assertForbidden();

        $this->assertTrue($session->refresh()->isDraft());
        $this->assertSame(1, ClientRoomSession::query()->count());
    }

    #[Test]
    public function a_word_the_form_does_not_offer_is_refused(): void
    {
        $room = $this->room();
        $session = ClientRooms::importSession($room, 'vf-0001', ['title' => 'Sitzung']);

        $this->actingAs($this->superUser())
            ->patchJson('/cp/client-rooms/'.$room->id.'/sessions/'.$session->id, [
                'published_status' => 'ausgedacht',
            ])
            ->assertStatus(422);

        $this->assertTrue($session->refresh()->isDraft());
    }

    #[Test]
    public function deleting_takes_the_row_and_leaves_the_room(): void
    {
        $room = $this->room();
        $session = ClientRooms::importSession($room, 'vf-0001', ['title' => 'Sitzung']);

        $this->actingAs($this->superUser())
            ->delete('/cp/client-rooms/'.$room->id.'/sessions/'.$session->id)
            ->assertRedirect();

        $this->assertSame(0, ClientRoomSession::query()->count());
        $this->assertNotNull($room->refresh());
    }

    #[Test]
    public function every_label_the_sessions_panel_needs_arrives_translated(): void
    {
        $room = $this->room();

        // A key that never made it into `strings()` reaches the screen as the
        // raw path, and the panel then shows
        // "statamic-clientrooms::messages.panel_sessions" as its heading.
        $this->actingAs($this->superUser())->get('/cp/client-rooms/'.$room->id)
            ->assertOk()
            ->assertInertia(function ($page) {
                $t = $page->toArray()['props']['t'];

                foreach (['panel_sessions', 'sessions_empty', 'sessions_count', 'sessions_draft_count', 'sessions_footnote', 'session_visible', 'session_draft', 'session_protocol', 'session_protocol_none', 'session_recording', 'session_transcript', 'session_link_expired', 'session_link_none', 'session_notes', 'session_delete_title'] as $key) {
                    $this->assertArrayHasKey($key, $t, $key.' fehlt in strings()');
                    $this->assertStringNotContainsString('statamic-clientrooms::', $t[$key], $key.' ist nicht übersetzt');
                }
            });
    }

    #[Test]
    public function one_session_is_not_called_sessions(): void
    {
        $room = $this->room();
        $user = $this->superUser();

        // Ohne Raum-Inhalt keine Unterzeile: ein leeres Panel sagt schon
        // "noch keine Sitzungen erfasst".
        $this->actingAs($user)->get('/cp/client-rooms/'.$room->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('sessionsSubheading', null));

        ClientRooms::importSession($room, 'vf-0001', [
            'title' => 'Sitzung',
            'published_status' => ClientRoomSession::PUBLISHED_PUBLISHED,
        ]);

        $this->actingAs($user)->get('/cp/client-rooms/'.$room->id)
            ->assertOk()
            ->assertInertia(function ($page) {
                $sub = $page->toArray()['props']['sessionsSubheading'];

                // ":count sessions" ergäbe "1 sessions", ":count Sitzungen"
                // ergäbe "1 Sitzungen". Die Zahl muss gebeugt werden, und das
                // kann nur der Server. Die Suite läuft auf Englisch.
                $this->assertStringContainsString('1 session', $sub);
                $this->assertStringNotContainsString('sessions', $sub);
                $this->assertStringNotContainsString(':count', $sub);
            });

        // Zwei Sitzungen, davon eine im Entwurf: Gesamtzahl zuerst, Ausnahme
        // dahinter, wie im Aufgaben-Panel nebenan.
        ClientRooms::importSession($room, 'vf-0002', ['title' => 'Entwurf']);

        $this->actingAs($user)->get('/cp/client-rooms/'.$room->id)
            ->assertOk()
            ->assertInertia(function ($page) {
                $sub = $page->toArray()['props']['sessionsSubheading'];

                $this->assertStringContainsString('2 sessions', $sub);
                $this->assertStringContainsString('1 not visible', $sub);
            });
    }

    #[Test]
    public function the_status_vocabulary_of_the_cockpit_has_a_word_here(): void
    {
        $room = $this->room();

        foreach (ClientRoomSession::STATUSES as $i => $status) {
            ClientRooms::importSession($room, 'vf-'.$i, ['title' => 'Sitzung', 'status' => $status]);
        }

        $this->actingAs($this->superUser())->get('/cp/client-rooms/'.$room->id)
            ->assertOk()
            ->assertInertia(function ($page) {
                foreach ($page->toArray()['props']['sessions'] as $session) {
                    // The hyphenated ones are the trap: `review-ready` cannot be
                    // a translation key, so a label that falls back to the
                    // handle is how a missing translation shows up.
                    $this->assertNotSame(
                        $session['status'],
                        $session['status_label'],
                        'für '.$session['status'].' fehlt die Übersetzung',
                    );
                }
            });
    }
}
