<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Facades\ClientRooms;
use Goldnead\ClientRooms\Tests\TestCase;
use Goldnead\Leadhub\Models\Contact;
use Goldnead\Leadhub\Support\Timeline\ContactTimeline;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;

/**
 * The link to LeadHub's contact, against the stand-in: made when the room is
 * opened, made later when it was not, and then the timeline is LeadHub's.
 */
class LeadhubLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ContactTimeline::$asked = [];
    }

    protected function contact(array $overrides = []): int
    {
        return (int) DB::table('leadhub_contacts')->insertGetId(array_merge([
            'brand_id' => 0,
            'email' => 'Maria@Example.com',
            'email_normalized' => 'maria@example.com',
            'first_name' => 'Maria',
            'last_name' => 'Beispiel',
        ], $overrides));
    }

    #[Test]
    public function opening_by_address_links_the_contact_of_that_brand(): void
    {
        $this->contact(['brand_id' => 5]);
        $mine = $this->contact(['brand_id' => 0]);

        $room = ClientRooms::open('maria@example.com');

        $this->assertSame($mine, $room->contact_id);
    }

    #[Test]
    public function opening_with_the_contact_takes_id_name_and_brand_from_it(): void
    {
        $id = $this->contact(['brand_id' => 3]);

        $room = ClientRooms::open(Contact::query()->findOrFail($id));

        $this->assertSame($id, $room->contact_id);
        $this->assertSame('Maria Beispiel', $room->name);
        $this->assertSame(3, $room->brand_id);
        $this->assertSame('maria@example.com', $room->email);
    }

    #[Test]
    public function a_room_without_a_link_looks_the_contact_up_once_and_keeps_it(): void
    {
        $room = $this->room();
        $this->assertNull($room->contact_id);

        // No contact yet: the room's own list.
        $this->assertSame('fallback', ClientRooms::timeline($room)['mode']);
        $this->assertNull($room->refresh()->contact_id);

        $id = $this->contact();

        $timeline = ClientRooms::timeline($room);

        $this->assertSame('leadhub', $timeline['mode']);
        $this->assertSame([$id], ContactTimeline::$asked);
        $this->assertSame($id, $room->refresh()->contact_id);
        $this->assertTrue($timeline['sources']['leadhub']);
        $this->assertTrue($timeline['sources']['payments']);
        $this->assertSame('leadhub:fake-1', $timeline['entries'][0]['id']);
        $this->assertSame(1, $timeline['stats']['purchase_count']);
    }

    #[Test]
    public function the_detail_page_reports_the_leadhub_mode(): void
    {
        $this->contact();
        $room = ClientRooms::open('maria@example.com');

        $this->actingAs($this->superUser())->get('/cp/client-rooms/'.$room->id)
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('timelineMode', 'leadhub')
                ->where('timeline.0.id', 'leadhub:fake-1'));
    }
}
