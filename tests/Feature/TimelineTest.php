<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Facades\ClientRooms;
use Goldnead\ClientRooms\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\Test;

/**
 * Without LeadHub, the room reads what it can from the neighbours' tables.
 */
class TimelineTest extends TestCase
{
    #[Test]
    public function without_any_neighbour_the_list_is_empty_and_says_which_sources_are_absent(): void
    {
        $room = ClientRooms::open('maria@example.com');

        $timeline = ClientRooms::timeline($room);

        $this->assertSame('fallback', $timeline['mode']);
        $this->assertSame([], $timeline['entries']);
        $this->assertSame(['payments' => false, 'booking' => false], $timeline['sources']);
        $this->assertSame([], $timeline['failed']);
        $this->assertSame(0, $timeline['total']);
    }

    #[Test]
    public function paid_payments_for_the_address_appear_newest_first(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->string('product');
            $table->integer('amount_cent');
            $table->string('currency', 3)->default('EUR');
            $table->string('status');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        DB::table('payments')->insert([
            ['email' => 'MARIA@example.com ', 'product' => 'coaching-5', 'amount_cent' => 45000, 'currency' => 'EUR', 'status' => 'paid', 'paid_at' => '2026-07-01 10:00:00'],
            ['email' => 'maria@example.com', 'product' => 'kurs', 'amount_cent' => 24900, 'currency' => 'EUR', 'status' => 'paid', 'paid_at' => '2026-08-01 10:00:00'],
            ['email' => 'maria@example.com', 'product' => 'offen', 'amount_cent' => 100, 'currency' => 'EUR', 'status' => 'open', 'paid_at' => null],
            ['email' => 'other@example.com', 'product' => 'coaching-5', 'amount_cent' => 45000, 'currency' => 'EUR', 'status' => 'paid', 'paid_at' => '2026-08-15 10:00:00'],
        ]);

        $timeline = ClientRooms::timeline(ClientRooms::open('maria@example.com'));

        $this->assertTrue($timeline['sources']['payments']);
        $this->assertCount(2, $timeline['entries']);
        $this->assertSame('payments:2', $timeline['entries'][0]['id']);
        $this->assertSame('payment.paid', $timeline['entries'][0]['kind']);
        $this->assertSame(24900, $timeline['entries'][0]['amount']['cent']);
        $this->assertSame('payments:1', $timeline['entries'][1]['id']);
        $this->assertSame(2, $timeline['stats']['purchase_count']);
        $this->assertSame(2, $timeline['total']);
    }

    #[Test]
    public function the_limit_cuts_the_list_but_not_the_count(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->string('email')->nullable();
            $table->string('product');
            $table->integer('amount_cent');
            $table->string('currency', 3)->default('EUR');
            $table->string('status');
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });

        foreach (range(1, 5) as $i) {
            DB::table('payments')->insert(['email' => 'maria@example.com', 'product' => 'p'.$i, 'amount_cent' => 100, 'status' => 'paid', 'paid_at' => '2026-08-0'.$i.' 10:00:00']);
        }

        $timeline = ClientRooms::timeline(ClientRooms::open('maria@example.com'), 2);

        $this->assertCount(2, $timeline['entries']);
        $this->assertSame(5, $timeline['total']);
    }
}
