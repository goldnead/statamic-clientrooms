<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Facades\ClientRooms;
use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\Support\Files\RoomFiles;
use Goldnead\ClientRooms\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\Antlers;
use Statamic\Facades\AssetContainer;

/**
 * Tenancy, against the brand-context stand-in: what one brand's staff and one
 * brand's site see is that brand's rooms and nothing else.
 */
class BrandTest extends TestCase
{
    #[Test]
    public function the_listing_shows_the_current_brands_rooms_only(): void
    {
        $manager = $this->fakeBrandContext(current: 1);

        ClientRooms::open('maria@example.com', null, ['brand_id' => 1, 'name' => 'Maria bei Nordlicht']);
        ClientRooms::open('maria@example.com', null, ['brand_id' => 2, 'name' => 'Maria bei Chorwerkstatt']);
        ClientRooms::open('jonas@example.com', null, ['brand_id' => 2]);

        $user = $this->superUser();

        $this->actingAs($user)->getJson('/cp/client-rooms')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Maria bei Nordlicht')
            ->assertJsonPath('data.0.brand', 'Nordlicht');

        $manager->setCurrent(2);

        $this->actingAs($user)->getJson('/cp/client-rooms')
            ->assertOk()
            ->assertJsonCount(2, 'data');

        // The brand column is offered on a multi-brand install.
        $columns = collect($this->actingAs($user)->getJson('/cp/client-rooms')->json('meta.columns'))->pluck('field')->all();
        $this->assertContains('brand', $columns);
    }

    #[Test]
    public function another_brands_room_is_not_reachable_by_id(): void
    {
        $manager = $this->fakeBrandContext(current: 1);

        $other = ClientRooms::open('maria@example.com', null, ['brand_id' => 2]);

        $this->actingAs($this->superUser())->getJson('/cp/client-rooms/'.$other->id)->assertNotFound();

        $manager->setCurrent(2);

        $this->actingAs($this->superUser())->get('/cp/client-rooms/'.$other->id)->assertOk();
    }

    #[Test]
    public function a_room_opened_where_a_brand_is_current_carries_it(): void
    {
        $this->fakeBrandContext(current: 2);

        $room = ClientRooms::open('neu@example.com');

        $this->assertSame(2, $room->brand_id);
        $this->assertSame($room->id, ClientRooms::forEmail('neu@example.com')?->id);
    }

    #[Test]
    public function with_no_brand_current_nothing_is_read(): void
    {
        $this->fakeBrandContext(current: null);

        ClientRooms::open('maria@example.com', null, ['brand_id' => 1]);

        $this->assertNull(ClientRooms::forEmail('maria@example.com'));
        $this->assertSame(0, ClientRoom::query()->count());
        // The explicit lookup still works: it names its brand.
        $this->assertNotNull(ClientRooms::find('maria@example.com', 1));
    }

    #[Test]
    public function the_tag_shows_the_room_of_the_brand_the_site_runs_as(): void
    {
        $manager = $this->fakeBrandContext(current: 1);

        ClientRooms::open('maria@example.com', null, ['brand_id' => 1, 'name' => 'Maria bei Nordlicht']);
        ClientRooms::open('maria@example.com', null, ['brand_id' => 2, 'name' => 'Maria bei Chorwerkstatt']);

        $user = $this->userWithPermission();
        $user->email('maria@example.com')->save();
        $this->actingAs($user);

        $template = '{{ client_room }}{{ name }}{{ /client_room }}';

        $this->assertSame('Maria bei Nordlicht', (string) Antlers::parse($template, [], true));

        $manager->setCurrent(2);
        $this->assertSame('Maria bei Chorwerkstatt', (string) Antlers::parse($template, [], true));

        $manager->setCurrent(null);
        $this->assertSame('', (string) Antlers::parse($template, [], true));
    }

    #[Test]
    public function each_brand_gets_its_own_container(): void
    {
        $this->fakeBrandContext(current: 2);
        $files = app(RoomFiles::class);

        $this->assertSame('clientrooms', $files->containerHandle(0));
        $this->assertSame('clientrooms-1', $files->containerHandle(1));
        $this->assertSame('clientrooms-2', $files->containerHandle(2));

        $this->artisan('clientrooms:install')->assertSuccessful();

        foreach (['clientrooms', 'clientrooms-1', 'clientrooms-2'] as $handle) {
            $this->assertNotNull(AssetContainer::findByHandle($handle), $handle.' was not created');
        }

        $this->assertStringContainsString('Chorwerkstatt', (string) AssetContainer::findByHandle('clientrooms-2')->title());
    }

    #[Test]
    public function the_first_upload_creates_a_missing_brand_container(): void
    {
        $this->fakeBrandContext(current: 2);

        $room = ClientRooms::open('maria@example.com');
        $this->assertNull(AssetContainer::findByHandle('clientrooms-2'));

        $file = ClientRooms::attach($room, UploadedFile::fake()->create('plan.pdf', 4), 'Plan');

        $this->assertSame('clientrooms-2', $file->container);
        $this->assertNotNull(AssetContainer::findByHandle('clientrooms-2'));
        $this->assertNotNull($file->asset());
    }

    #[Test]
    public function without_brand_context_the_handle_never_gets_a_suffix(): void
    {
        $files = app(RoomFiles::class);

        $this->assertSame('clientrooms', $files->containerHandle(0));
        $this->assertSame('clientrooms', $files->containerHandle(7));
        $this->assertSame([0], $files->brandIds());
    }
}
