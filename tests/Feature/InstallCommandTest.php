<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Support\Files\RoomFiles;
use Goldnead\ClientRooms\Tests\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Statamic\Facades\AssetContainer;

class InstallCommandTest extends TestCase
{
    #[Test]
    public function it_creates_the_container_once(): void
    {
        $this->assertNull(AssetContainer::findByHandle('clientrooms'));

        $this->artisan('clientrooms:install')
            ->expectsOutputToContain('created')
            ->assertSuccessful();

        $container = AssetContainer::findByHandle('clientrooms');
        $this->assertNotNull($container);
        $this->assertSame('clientrooms_test', $container->diskHandle());

        $this->artisan('clientrooms:install')
            ->expectsOutputToContain('already exists')
            ->assertSuccessful();
    }

    #[Test]
    public function the_default_disk_is_its_own_private_root(): void
    {
        $default = require __DIR__.'/../../config/statamic-clientrooms.php';
        $this->assertSame('clientrooms', $default['disk']);

        $disk = config('filesystems.disks.clientrooms');
        $this->assertIsArray($disk);
        $this->assertSame(storage_path('app/clientrooms'), $disk['root']);
        $this->assertArrayNotHasKey('url', $disk);
        $this->assertFalse($disk['serve']);

        config(['statamic-clientrooms.disk' => 'clientrooms']);
        $this->assertFalse(app(RoomFiles::class)->diskIsPublic());
    }

    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function publicDisks(): array
    {
        return [
            'has a url' => [['url' => 'https://example.test/files']],
            'public visibility' => [['visibility' => 'public']],
            'root below public/' => [['root' => 'PUBLIC/docs']],
        ];
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    #[Test]
    #[DataProvider('publicDisks')]
    public function it_refuses_to_create_the_container_on_a_public_disk(array $extra): void
    {
        if (($extra['root'] ?? null) === 'PUBLIC/docs') {
            $extra['root'] = public_path('docs');
        }

        config([
            'filesystems.disks.open' => $extra + ['driver' => 'local', 'root' => storage_path('app/open')],
            'statamic-clientrooms.disk' => 'open',
        ]);

        $this->artisan('clientrooms:install')->assertFailed();

        $this->assertNull(AssetContainer::findByHandle('clientrooms'));
    }
}
