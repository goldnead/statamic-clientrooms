<?php

namespace Goldnead\ClientRooms\Tests\Feature;

use Goldnead\ClientRooms\Events\ClientRoomOpened;
use Goldnead\ClientRooms\Facades\ClientRooms;
use Goldnead\ClientRooms\Models\ClientRoom;
use Goldnead\ClientRooms\Tests\TestCase;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Goldnead\StatamicPayments\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\Test;

/**
 * The first coaching purchase opens the room. Against the stand-in event in
 * tests/Fakes, which carries what the real one carries.
 */
class OpenRoomOnPaymentTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('products')->insert([
            ['handle' => 'coaching-5', 'type' => 'sessions', 'brand_id' => 2],
            ['handle' => 'kurs', 'type' => 'access', 'brand_id' => 2],
            ['handle' => 'noten', 'type' => 'download', 'brand_id' => 0],
        ]);
    }

    protected function paid(array $attributes = []): void
    {
        event(new PaymentPaid(new Payment(array_merge([
            'id' => 149,
            'email' => 'Bärbel.Öztürk@beispiel.de',
            'name' => 'Bärbel Öztürk-Weiß',
            'brand_id' => 0,
            'product' => 'kurs',
            'items' => [['product' => 'kurs']],
        ], $attributes))));
    }

    #[Test]
    public function a_product_of_a_configured_kind_opens_a_room(): void
    {
        Event::fake([ClientRoomOpened::class]);

        $this->paid(['product' => 'coaching-5', 'items' => [['product' => 'coaching-5'], ['product' => 'noten']]]);

        $room = ClientRoom::query()->first();

        $this->assertNotNull($room);
        $this->assertSame('bärbel.öztürk@beispiel.de', $room->email);
        $this->assertSame('Bärbel Öztürk-Weiß', $room->name);
        $this->assertSame('payments', $room->meta['opened_by']);
        $this->assertSame(149, $room->meta['payment_id']);
        $this->assertSame('coaching-5', $room->meta['product']);
        // The payment carried no brand; the product did.
        $this->assertSame(2, $room->brand_id);

        Event::assertDispatchedTimes(ClientRoomOpened::class, 1);
    }

    #[Test]
    public function a_product_of_another_kind_opens_nothing(): void
    {
        $this->paid();

        $this->assertSame(0, ClientRoom::query()->count());
    }

    #[Test]
    public function a_handle_from_the_config_opens_a_room_whatever_its_kind(): void
    {
        config()->set('statamic-clientrooms.open_on_products', ['kurs']);

        $this->paid();

        $this->assertSame(1, ClientRoom::query()->count());
    }

    #[Test]
    public function the_kind_list_can_be_changed(): void
    {
        config()->set('statamic-clientrooms.open_on_product_types', ['access']);

        $this->paid();

        $this->assertSame(1, ClientRoom::query()->count());
    }

    #[Test]
    public function it_is_idempotent(): void
    {
        Event::fake([ClientRoomOpened::class]);

        $this->paid(['product' => 'coaching-5', 'items' => [['product' => 'coaching-5']]]);
        $this->paid(['product' => 'coaching-5', 'items' => [['product' => 'coaching-5']]]);
        $this->paid(['id' => 150, 'product' => 'coaching-5', 'items' => [['product' => 'coaching-5']]]);

        $this->assertSame(1, ClientRoom::query()->count());
        Event::assertDispatchedTimes(ClientRoomOpened::class, 1);
    }

    #[Test]
    public function a_later_purchase_reopens_a_closed_room(): void
    {
        $this->paid(['product' => 'coaching-5', 'items' => [['product' => 'coaching-5']]]);
        $room = ClientRoom::query()->firstOrFail();
        ClientRooms::close($room);

        $this->paid(['id' => 151, 'product' => 'coaching-5', 'items' => [['product' => 'coaching-5']]]);

        $this->assertSame(1, ClientRoom::query()->count());
        $this->assertTrue($room->refresh()->isOpen());
    }

    #[Test]
    public function the_payments_brand_wins_over_the_products(): void
    {
        $this->paid(['brand_id' => 7, 'product' => 'coaching-5', 'items' => [['product' => 'coaching-5']]]);

        $this->assertSame(7, ClientRoom::query()->firstOrFail()->brand_id);
    }

    #[Test]
    public function the_default_owner_is_resolved_by_address(): void
    {
        $coach = $this->superUser();
        config()->set('statamic-clientrooms.default_owner', $coach->email());

        $this->paid(['product' => 'coaching-5', 'items' => [['product' => 'coaching-5']]]);

        $this->assertSame((string) $coach->id(), ClientRoom::query()->firstOrFail()->owner_user_id);
    }

    #[Test]
    public function a_payment_without_an_address_opens_nothing(): void
    {
        $this->paid(['email' => null, 'product' => 'coaching-5', 'items' => [['product' => 'coaching-5']]]);

        $this->assertSame(0, ClientRoom::query()->count());
    }

    #[Test]
    public function a_payment_without_an_address_is_logged_without_the_address(): void
    {
        Log::spy();

        $this->paid(['email' => null, 'product' => 'coaching-5', 'items' => [['product' => 'coaching-5']]]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'statamic-clientrooms')
                && ($context['payment'] ?? null) === 149
                && ! array_key_exists('email', $context))
            ->atLeast()->once();
    }

    #[Test]
    public function a_payment_of_another_kind_is_logged_as_no_room(): void
    {
        Log::spy();

        $this->paid();

        Log::shouldHaveReceived('info')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'statamic-clientrooms')
                && ($context['payment'] ?? null) === 149
                && ($context['product'] ?? null) === ['kurs'])
            ->atLeast()->once();
    }

    #[Test]
    public function a_payment_without_any_product_is_logged(): void
    {
        Log::spy();

        $this->paid(['product' => null, 'items' => []]);

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'statamic-clientrooms')
                && ($context['payment'] ?? null) === 149)
            ->atLeast()->once();
    }

    #[Test]
    public function unreadable_lines_are_logged_and_the_payments_own_product_still_opens_the_room(): void
    {
        Log::spy();

        $unreadable = new class(['id' => 149, 'email' => 'kunde@beispiel.de', 'name' => 'Kunde', 'brand_id' => 0, 'product' => 'coaching-5']) extends Payment
        {
            public function __get(string $name): mixed
            {
                if ($name === 'items') {
                    return new class implements \IteratorAggregate
                    {
                        public function getIterator(): \Traversable
                        {
                            throw new \RuntimeException('lines table unreadable');
                        }
                    };
                }

                return parent::__get($name);
            }
        };

        event(new PaymentPaid($unreadable));

        $this->assertSame(1, ClientRoom::query()->count());

        Log::shouldHaveReceived('warning')
            ->withArgs(fn (string $message, array $context = []) => str_contains($message, 'statamic-clientrooms')
                && ($context['payment'] ?? null) === 149
                && str_contains((string) ($context['exception'] ?? ''), 'lines table unreadable'))
            ->atLeast()->once();
    }
}
