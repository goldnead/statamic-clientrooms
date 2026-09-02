<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Tests\TestCase;
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
}
