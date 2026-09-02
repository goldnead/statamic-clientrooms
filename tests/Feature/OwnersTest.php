<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Support\Owners;
use Goldnead\ClientRooms\Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

/**
 * The owner picker lists staff, never the site's members.
 */
class OwnersTest extends TestCase
{
    #[Test]
    public function only_users_who_may_open_the_control_panel_are_offered(): void
    {
        $super = $this->superUser();
        $coach = $this->userWithPermission('view client rooms');
        $member = $this->frontendUser('klientin@example.com');

        $ids = array_column(Owners::options(), 'value');

        $this->assertContains((string) $super->id(), $ids);
        $this->assertContains((string) $coach->id(), $ids);
        $this->assertNotContains((string) $member->id(), $ids);
    }

    #[Test]
    public function a_member_can_still_be_resolved_by_address_where_the_caller_insists(): void
    {
        // `resolveId()` is the facade's door and takes whoever is named; the
        // picker is what keeps members out of view. Two different questions.
        $member = $this->frontendUser('klientin@example.com');

        $this->assertSame((string) $member->id(), Owners::resolveId('klientin@example.com'));
        $this->assertNull(Owners::resolveId('niemand@example.com'));
    }
}
