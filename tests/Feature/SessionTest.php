<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Facades\ClientRooms;
use Goldnead\ClientRooms\Models\ClientRoomSession;
use Goldnead\ClientRooms\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Antlers;

/**
 * What happened, as a row in the room — and the three ways it can go wrong.
 *
 * The file is organised around those three rather than around methods:
 *
 * 1. **A delivery arrives twice.** The cockpit that pushes a published sitting
 *    across retries eight times with backoff, so "written once" is not a nice
 *    property here, it is the contract. Every test about it counts rows.
 * 2. **A draft reaches the client.** Same line as on tasks, and asserted the
 *    same way — by absence, not by shape. A test that only checked the column
 *    was stored would pass just as well with the line not drawn at all.
 * 3. **A dead link is handed out.** The recording URL is minted per request
 *    and dies in hours. A stored one that outlived its signature must read as
 *    "no link", never as a link.
 */
class SessionTest extends TestCase
{
    /** The third argument marks the template as trusted; without it Antlers runs no tags. */
    protected function parse(string $template): string
    {
        return (string) Antlers::parse($template, [], true);
    }

    protected string $titles = '{{ client_room }}{{ sessions }}[{{ title }}]{{ /sessions }}{{ /client_room }}';

    /** Sign in as the client whose room this is. */
    protected function actAsClient(string $email = 'maria@example.com'): void
    {
        $user = $this->userWithPermission();
        $user->email($email)->save();

        $this->actingAs($user);
    }

    // ── 1 · A delivery arrives twice ────────────────────────────────────────

    #[Test]
    public function the_same_external_id_lands_once(): void
    {
        $room = $this->room();

        ClientRooms::importSession($room, 'vf-0001', ['title' => 'Erste Sitzung']);
        ClientRooms::importSession($room, 'vf-0001', ['title' => 'Erste Sitzung']);

        $this->assertSame(1, ClientRoomSession::query()->count());
    }

    #[Test]
    public function a_second_delivery_updates_rather_than_duplicates(): void
    {
        $room = $this->room();

        $first = ClientRooms::importSession($room, 'vf-0001', [
            'title' => 'Erste Sitzung',
            'protocol' => 'Entwurf.',
        ]);

        $second = ClientRooms::importSession($room, 'vf-0001', [
            'title' => 'Erste Sitzung',
            'protocol' => 'Die fertige Fassung.',
        ]);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ClientRoomSession::query()->count());
        $this->assertSame('Die fertige Fassung.', $second->fresh()?->protocol);
    }

    #[Test]
    public function two_different_ids_are_two_sittings(): void
    {
        $room = $this->room();

        ClientRooms::importSession($room, 'vf-0001', ['title' => 'Erste']);
        ClientRooms::importSession($room, 'vf-0002', ['title' => 'Zweite']);

        $this->assertSame(2, ClientRoomSession::query()->count());
    }

    #[Test]
    public function a_sitting_that_changes_hands_moves_instead_of_being_copied(): void
    {
        $maria = $this->room();
        $jonas = $this->room(['email' => 'jonas@example.com', 'name' => 'Jonas Beispiel']);

        ClientRooms::importSession($maria, 'vf-0001', ['title' => 'Sitzung']);
        $moved = ClientRooms::importSession($jonas, 'vf-0001', ['title' => 'Sitzung']);

        $this->assertSame(1, ClientRoomSession::query()->count());
        $this->assertSame($jonas->id, $moved->fresh()?->room_id);
        $this->assertSame(0, $maria->sessions()->count());
    }

    #[Test]
    public function an_empty_external_id_is_refused_rather_than_written(): void
    {
        $this->expectException(InvalidArgumentException::class);

        ClientRooms::importSession($this->room(), '   ', ['title' => 'Sitzung']);
    }

    #[Test]
    public function the_external_id_cannot_be_moved_by_an_update(): void
    {
        $session = ClientRooms::importSession($this->room(), 'vf-0001', ['title' => 'Sitzung']);

        // `external_id` is the row's identity, not a field. An update that
        // could move it would let one sitting quietly become another.
        ClientRooms::updateSession($session, ['external_id' => 'vf-9999', 'title' => 'Umbenannt']);

        $this->assertSame('vf-0001', $session->fresh()?->external_id);
        $this->assertSame('Umbenannt', $session->fresh()?->title);
    }

    #[Test]
    public function an_import_without_a_title_still_keeps_the_sitting(): void
    {
        // A rule that loses a sitting is worse than a sitting with a plain
        // stand-in title.
        $session = ClientRooms::importSession($this->room(), 'vf-0001', [
            'title' => '',
            'held_at' => '2026-08-14T10:00:00+00:00',
        ]);

        $this->assertSame(1, ClientRoomSession::query()->count());
        $this->assertNotSame('', trim($session->title));
        $this->assertStringContainsString('2026', $session->title);
    }

    // ── 2 · A draft reaches the client ──────────────────────────────────────

    #[Test]
    public function an_imported_sitting_is_a_draft_unless_the_sender_says_otherwise(): void
    {
        $session = ClientRooms::importSession($this->room(), 'vf-0001', ['title' => 'Sitzung']);

        $this->assertTrue($session->isDraft());
    }

    #[Test]
    public function a_sitting_typed_by_hand_is_meant_to_arrive(): void
    {
        $session = ClientRooms::recordSession($this->room(), 'Sitzung vom Montag');

        $this->assertTrue($session->isPublished());
    }

    #[Test]
    public function a_draft_is_absent_from_the_tag_not_merely_hidden(): void
    {
        $room = $this->room();

        ClientRooms::importSession($room, 'vf-0001', [
            'title' => 'Noch im Entwurf',
            'published_status' => ClientRoomSession::PUBLISHED_DRAFT,
        ]);
        ClientRooms::importSession($room, 'vf-0002', [
            'title' => 'Freigegeben',
            'published_status' => ClientRoomSession::PUBLISHED_PUBLISHED,
        ]);

        $this->actAsClient();

        $output = $this->parse($this->titles);

        $this->assertStringContainsString('[Freigegeben]', $output);
        $this->assertStringNotContainsString('Noch im Entwurf', $output);
    }

    #[Test]
    public function an_archived_sitting_is_gone_from_the_room_too(): void
    {
        $room = $this->room();

        ClientRooms::importSession($room, 'vf-0001', [
            'title' => 'Weggelegt',
            'published_status' => ClientRoomSession::PUBLISHED_ARCHIVED,
        ]);

        $this->actAsClient();

        $this->assertStringNotContainsString('Weggelegt', $this->parse($this->titles));
    }

    #[Test]
    public function the_member_api_hands_out_published_sittings_only(): void
    {
        $room = $this->room();

        ClientRooms::importSession($room, 'vf-0001', [
            'title' => 'Noch im Entwurf',
            'published_status' => ClientRoomSession::PUBLISHED_DRAFT,
        ]);
        ClientRooms::importSession($room, 'vf-0002', [
            'title' => 'Freigegeben',
            'published_status' => ClientRoomSession::PUBLISHED_PUBLISHED,
        ]);

        $response = $this->actingAs($this->frontendUser('maria@example.com'))
            ->getJson('/!/statamic-clientrooms/me');

        $response->assertOk();

        $titles = array_column($response->json('sessions'), 'title');

        $this->assertSame(['Freigegeben'], $titles);
    }

    #[Test]
    public function the_coachs_own_notes_never_leave_the_control_panel(): void
    {
        $room = $this->room();

        ClientRooms::importSession($room, 'vf-0001', [
            'title' => 'Sitzung',
            'published_status' => ClientRoomSession::PUBLISHED_PUBLISHED,
            'notes' => 'Sie drückt beim hohen A, nächstes Mal Twang.',
        ]);

        // The column is written — the point is that no reader outside the
        // Control Panel returns it. Asserting only "the API has no notes key"
        // would pass on a room with no sitting in it at all.
        $this->assertSame(
            'Sie drückt beim hohen A, nächstes Mal Twang.',
            ClientRoomSession::query()->first()?->notes,
        );

        $response = $this->actingAs($this->frontendUser('maria@example.com'))
            ->getJson('/!/statamic-clientrooms/me');

        $response->assertOk();
        $this->assertArrayNotHasKey('notes', $response->json('sessions.0'));
        $this->assertStringNotContainsString('Twang', $response->getContent() ?: '');

        $this->actAsClient();

        $everything = $this->parse(
            '{{ client_room }}{{ sessions }}[{{ title }}|{{ notes }}]{{ /sessions }}{{ /client_room }}'
        );

        $this->assertStringContainsString('[Sitzung|]', $everything);
        $this->assertStringNotContainsString('Twang', $everything);
    }

    #[Test]
    public function publishing_and_unpublishing_moves_the_line_both_ways(): void
    {
        $session = ClientRooms::importSession($this->room(), 'vf-0001', ['title' => 'Sitzung']);

        $this->assertTrue(ClientRooms::publishSession($session)->isPublished());
        $this->assertTrue(ClientRooms::publishSession($session, false)->isDraft());
    }

    #[Test]
    public function a_published_status_nobody_can_read_falls_to_the_safe_side(): void
    {
        $session = ClientRooms::importSession($this->room(), 'vf-0001', [
            'title' => 'Sitzung',
            'published_status' => ['nonsense'],
        ]);

        $this->assertTrue($session->isDraft());
    }

    // ── 3 · A dead link is handed out ───────────────────────────────────────

    #[Test]
    public function an_expired_recording_link_reads_as_no_link(): void
    {
        $session = ClientRooms::importSession($this->room(), 'vf-0001', [
            'title' => 'Sitzung',
            'recording_url' => 'https://cockpit.example.com/rec/abc?sig=xyz',
            'recording_url_expires_at' => now()->subMinute(),
        ]);

        $this->assertNull($session->recordingUrl());

        // …and the recording has not stopped existing. That difference is the
        // whole reason both are on the model: a front end can say "ask your
        // coach for a fresh link" rather than "there was no recording".
        $this->assertTrue($session->hasRecording());
    }

    #[Test]
    public function a_live_recording_link_is_handed_out(): void
    {
        $session = ClientRooms::importSession($this->room(), 'vf-0001', [
            'title' => 'Sitzung',
            'recording_url' => 'https://cockpit.example.com/rec/abc?sig=xyz',
            'recording_url_expires_at' => now()->addHours(6),
        ]);

        $this->assertSame('https://cockpit.example.com/rec/abc?sig=xyz', $session->recordingUrl());
    }

    #[Test]
    public function a_link_without_an_expiry_is_taken_at_face_value(): void
    {
        // A host that stores a permanent URL means it.
        $session = ClientRooms::importSession($this->room(), 'vf-0001', [
            'title' => 'Sitzung',
            'recording_url' => 'https://cdn.example.com/rec/abc.mp4',
        ]);

        $this->assertSame('https://cdn.example.com/rec/abc.mp4', $session->recordingUrl());
    }

    #[Test]
    public function the_member_api_sends_null_for_an_expired_link_and_still_says_it_exists(): void
    {
        ClientRooms::importSession($this->room(), 'vf-0001', [
            'title' => 'Sitzung',
            'published_status' => ClientRoomSession::PUBLISHED_PUBLISHED,
            'recording_url' => 'https://cockpit.example.com/rec/abc?sig=xyz',
            'recording_url_expires_at' => now()->subMinute(),
            'has_transcript' => true,
        ]);

        $response = $this->actingAs($this->frontendUser('maria@example.com'))
            ->getJson('/!/statamic-clientrooms/me');

        $response->assertOk();

        $session = $response->json('sessions.0');

        $this->assertNull($session['recording_url']);
        $this->assertTrue($session['has_recording']);
        $this->assertTrue($session['has_transcript']);
        $this->assertNull($session['transcript_url']);
    }

    #[Test]
    public function a_transcript_can_exist_while_no_link_to_it_does(): void
    {
        $session = ClientRooms::importSession($this->room(), 'vf-0001', [
            'title' => 'Sitzung',
            'has_transcript' => true,
        ]);

        $this->assertTrue($session->hasTranscript());
        $this->assertNull($session->transcriptUrl());
    }

    // ── What the fields do with what an import brings ───────────────────────

    #[Test]
    public function a_field_the_addon_cannot_read_is_a_field_it_does_not_have(): void
    {
        $session = ClientRooms::importSession($this->room(), 'vf-0001', [
            'title' => 'Sitzung',
            // A raw payload carries arrays and objects.
            'summary' => ['not', 'a', 'string'],
            'coach_name' => (object) ['name' => 'Adrian'],
        ]);

        $this->assertNull($session->summary);
        $this->assertNull($session->coach_name);
    }

    #[Test]
    public function an_unreadable_date_costs_the_date_and_not_the_sitting(): void
    {
        $session = ClientRooms::importSession($this->room(), 'vf-0001', [
            'title' => 'Sitzung',
            'held_at' => 'irgendwann letzten Sommer',
        ]);

        $this->assertNull($session->held_at);
        $this->assertSame(1, ClientRoomSession::query()->count());
    }

    #[Test]
    public function an_iso_moment_from_the_wire_survives_the_trip(): void
    {
        $session = ClientRooms::importSession($this->room(), 'vf-0001', [
            'title' => 'Sitzung',
            'held_at' => '2026-08-14T10:30:00+00:00',
        ]);

        $this->assertSame('2026-08-14 10:30:00', $session->held_at?->utc()->format('Y-m-d H:i:s'));
    }

    #[Test]
    public function a_duration_above_the_columns_ceiling_is_clamped_not_thrown(): void
    {
        $session = ClientRooms::importSession($this->room(), 'vf-0001', [
            'title' => 'Sitzung',
            'duration_minutes' => '99999999999999',
        ]);

        $this->assertSame(ClientRoomSession::MAX_DURATION_MINUTES, $session->duration_minutes);
    }

    #[Test]
    public function a_negative_duration_becomes_none(): void
    {
        $session = ClientRooms::importSession($this->room(), 'vf-0001', [
            'title' => 'Sitzung',
            'duration_minutes' => -30,
        ]);

        $this->assertSame(0, $session->duration_minutes);
    }

    #[Test]
    public function the_transcript_flag_reads_what_a_wire_calls_true(): void
    {
        $room = $this->room();

        foreach ([true, 'true', 1, '1'] as $i => $value) {
            $session = ClientRooms::importSession($room, 'vf-yes-'.$i, [
                'title' => 'Sitzung',
                'has_transcript' => $value,
            ]);

            $this->assertTrue($session->has_transcript, 'für '.var_export($value, true));
        }

        foreach ([false, 'false', 0, '0', 'nonsense'] as $i => $value) {
            $session = ClientRooms::importSession($room, 'vf-no-'.$i, [
                'title' => 'Sitzung',
                'has_transcript' => $value,
            ]);

            $this->assertFalse($session->has_transcript, 'für '.var_export($value, true));
        }
    }

    #[Test]
    public function meta_takes_provenance_and_refuses_anything_else(): void
    {
        $session = ClientRooms::importSession($this->room(), 'vf-0001', [
            'title' => 'Sitzung',
            'meta' => ['external_booking_id' => 'cal-778', 'session_type_id' => 3],
        ]);

        $this->assertSame('cal-778', $session->fresh()?->meta['external_booking_id']);

        ClientRooms::updateSession($session, ['meta' => 'not an array']);

        $this->assertNull($session->fresh()?->meta);
    }

    #[Test]
    public function an_update_cannot_empty_the_title(): void
    {
        $session = ClientRooms::recordSession($this->room(), 'Sitzung vom Montag');

        $this->expectException(InvalidArgumentException::class);

        ClientRooms::updateSession($session, ['title' => '   ']);
    }

    // ── The room around it ──────────────────────────────────────────────────

    #[Test]
    public function a_sitting_arriving_stirs_the_room(): void
    {
        $room = $this->room();

        DB::table('client_rooms')->where('id', $room->id)->update([
            'last_activity_at' => now()->subYear(),
        ]);

        ClientRooms::importSession($room, 'vf-0001', ['title' => 'Sitzung']);

        $this->assertTrue($room->fresh()?->last_activity_at?->isToday());
    }

    #[Test]
    public function a_second_identical_delivery_does_not_pretend_something_happened(): void
    {
        $room = $this->room();

        ClientRooms::importSession($room, 'vf-0001', ['title' => 'Sitzung']);

        DB::table('client_rooms')->where('id', $room->id)->update([
            'last_activity_at' => now()->subYear(),
        ]);

        // Nothing changed, so nothing happened. A retry that stirred the room
        // would make eight delivery attempts look like eight sittings.
        ClientRooms::importSession($room, 'vf-0001', ['title' => 'Sitzung']);

        $this->assertFalse($room->fresh()?->last_activity_at?->isToday());
    }

    #[Test]
    public function sittings_come_back_newest_first(): void
    {
        $room = $this->room();

        ClientRooms::importSession($room, 'vf-alt', [
            'title' => 'Alt',
            'held_at' => '2026-01-05T10:00:00+00:00',
            'published_status' => ClientRoomSession::PUBLISHED_PUBLISHED,
        ]);
        ClientRooms::importSession($room, 'vf-neu', [
            'title' => 'Neu',
            'held_at' => '2026-08-05T10:00:00+00:00',
            'published_status' => ClientRoomSession::PUBLISHED_PUBLISHED,
        ]);

        $this->actAsClient();

        $this->assertSame('[Neu][Alt]', $this->parse($this->titles));
    }

    #[Test]
    public function a_room_taken_away_takes_its_sittings_with_it(): void
    {
        $room = $this->room();

        ClientRooms::importSession($room, 'vf-0001', ['title' => 'Sitzung']);

        $room->delete();

        $this->assertSame(0, ClientRoomSession::query()->count());
    }

    #[Test]
    public function removing_one_sitting_leaves_the_others(): void
    {
        $room = $this->room();

        $gone = ClientRooms::importSession($room, 'vf-0001', ['title' => 'Weg']);
        ClientRooms::importSession($room, 'vf-0002', ['title' => 'Bleibt']);

        $this->assertTrue(ClientRooms::removeSession($gone));
        $this->assertSame(1, ClientRoomSession::query()->count());
        $this->assertSame('Bleibt', ClientRoomSession::query()->first()?->title);
    }
}
