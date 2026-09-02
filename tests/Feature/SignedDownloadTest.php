<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Facades\ClientRooms;
use Goldnead\ClientRooms\Models\ClientRoomFile;
use Goldnead\ClientRooms\Tests\TestCase;
use Illuminate\Http\UploadedFile;
use PHPUnit\Framework\Attributes\Test;

/**
 * The client's link: signed, short-lived, and never the storage path.
 */
class SignedDownloadTest extends TestCase
{
    protected function attached(): ClientRoomFile
    {
        $this->makeContainer();
        $room = ClientRooms::open('maria@example.com');

        return ClientRooms::attach($room, UploadedFile::fake()->create('plan.pdf', 12, 'application/pdf'), 'Übungsplan');
    }

    #[Test]
    public function a_fresh_link_downloads_the_file(): void
    {
        $file = $this->attached();
        $url = ClientRooms::downloadUrl($file);

        $this->assertStringContainsString('/!/statamic-clientrooms/files/'.$file->id, $url);
        $this->assertStringContainsString('signature=', $url);
        $this->assertStringNotContainsString('room-', $url);

        $this->get($url)
            ->assertOk()
            ->assertHeader('content-disposition')
            ->assertHeader('cache-control', 'no-store, private');
    }

    #[Test]
    public function the_link_expires(): void
    {
        $file = $this->attached();
        $url = ClientRooms::downloadUrl($file);

        $this->travel(31)->minutes();

        $this->get($url)->assertForbidden();
    }

    #[Test]
    public function the_window_follows_the_config(): void
    {
        config()->set('statamic-clientrooms.download_ttl_minutes', 5);

        $file = $this->attached();
        $url = ClientRooms::downloadUrl($file);

        $this->travel(6)->minutes();

        $this->get($url)->assertForbidden();
    }

    #[Test]
    public function a_tampered_link_is_refused(): void
    {
        $file = $this->attached();
        $url = ClientRooms::downloadUrl($file);

        $this->get(str_replace('/files/'.$file->id, '/files/'.($file->id + 1), $url))->assertForbidden();
        $this->get(preg_replace('/signature=[0-9a-f]+/', 'signature=deadbeef', $url))->assertForbidden();
    }

    #[Test]
    public function an_unsigned_request_is_refused(): void
    {
        $file = $this->attached();

        $this->get('/!/statamic-clientrooms/files/'.$file->id)->assertForbidden();
    }

    #[Test]
    public function a_file_hidden_again_is_gone_even_with_a_valid_link(): void
    {
        $file = $this->attached();
        $url = ClientRooms::downloadUrl($file);

        $file->forceFill(['visible_to_client' => false])->save();

        $this->get($url)->assertNotFound();
    }

    #[Test]
    public function a_closed_room_hands_out_nothing(): void
    {
        $file = $this->attached();
        $url = ClientRooms::downloadUrl($file);

        ClientRooms::close($file->room_id);

        $this->get($url)->assertNotFound();
    }
}
