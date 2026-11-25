<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\ClientRoomsManager;
use Goldnead\ClientRooms\Support\Contacts;
use Goldnead\ClientRooms\Support\Owners;
use Goldnead\ClientRooms\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use ReflectionClass;
use RuntimeException;
use Statamic\Facades\User;

class StilleFehlerTest extends TestCase
{
    public function test_an_unreadable_import_date_is_logged(): void
    {
        Log::spy();
        $manager = (new ReflectionClass(ClientRoomsManager::class))->newInstanceWithoutConstructor();
        $moment = new \ReflectionMethod(ClientRoomsManager::class, 'moment');
        $moment->setAccessible(true);

        $this->assertNull($moment->invoke($manager, 'kein Datum'));

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_failing_contact_lookup_is_logged(): void
    {
        // Every query fails, as a lost database connection would make it. (SQLite reads an unknown
        // double-quoted column as a string, so a missing column does not throw there.)
        Log::spy();
        $down = true;
        DB::beforeExecuting(function () use (&$down) {
            if ($down) {
                throw new RuntimeException('Datenbank nicht erreichbar');
            }
        });

        $this->assertNull(Contacts::idFor('kontakt@example.test', 1));
        $down = false; // the rollback after the test runs queries too

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_failing_owner_lookup_by_id_is_logged(): void
    {
        User::shouldReceive('find')->andThrow(new RuntimeException('Benutzerquelle nicht erreichbar'));
        Log::spy();

        $this->assertNull(Owners::find('5'));

        Log::shouldHaveReceived('warning')->once();
    }

    public function test_a_failing_owner_list_is_logged(): void
    {
        User::shouldReceive('all')->andThrow(new RuntimeException('Benutzerquelle nicht erreichbar'));
        Log::spy();

        $this->assertSame([], Owners::options());

        Log::shouldHaveReceived('warning')->once();
    }
}
