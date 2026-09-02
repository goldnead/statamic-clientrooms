<?php

namespace Goldnead\ClientRooms\Listeners;

use Goldnead\ClientRooms\ClientRoomsManager;
use Goldnead\ClientRooms\Support\Brands;
use Goldnead\StatamicPayments\Events\PaymentPaid;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The first coaching purchase opens the room.
 *
 * Autoloaded by core off the parameter type below, which registers a listener
 * on the class *name*. Without `statamic-payments` the event never fires and
 * this class is never loaded — the import is a string to PHP until then.
 *
 * Idempotent by construction: `open()` returns an open room untouched, and
 * `PaymentPaid` is dispatched once per payment by the sibling. A second
 * coaching product bought later reopens a closed room and does nothing to an
 * open one.
 */
class OpenRoomOnPayment
{
    public function __construct(protected ClientRoomsManager $rooms) {}

    public function handle(PaymentPaid $event): void
    {
        $payment = $event->payment;

        $email = $payment->email;

        if (! is_string($email) || trim($email) === '') {
            return;
        }

        $handles = $this->handlesOf($payment);

        if ($handles === []) {
            return;
        }

        $match = $this->match($handles);

        if ($match === null) {
            return;
        }

        try {
            $brandId = (int) ($payment->brand_id ?? 0);

            if ($brandId === Brands::NONE) {
                $brandId = $match['brand_id'] ?? Brands::stampId();
            }

            $this->rooms->open($email, config('statamic-clientrooms.default_owner'), [
                'name' => is_string($payment->name) && trim($payment->name) !== '' ? $payment->name : null,
                'brand_id' => $brandId,
                'meta' => [
                    'opened_by' => 'payments',
                    'payment_id' => $payment->id,
                    'product' => $match['handle'],
                ],
            ]);
        } catch (Throwable $e) {
            // A room that could not be opened must not fail the payment's
            // other listeners — access, invoice, mail. Logged so it is found.
            Log::error('statamic-clientrooms: could not open a room for a paid payment.', [
                'payment' => $payment->id ?? null,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Every product handle on the payment: the lines, plus the legacy
     * single-product column for payments without lines.
     *
     * @return list<string>
     */
    protected function handlesOf(object $payment): array
    {
        $handles = [];

        try {
            $items = method_exists($payment, 'items') ? $payment->items : null;

            if ($items !== null) {
                foreach ($items as $item) {
                    $handle = $item->product ?? null;

                    if (is_string($handle) && $handle !== '') {
                        $handles[] = $handle;
                    }
                }
            }
        } catch (Throwable) {
            // No lines table, or none readable. The column below still answers.
        }

        $primary = $payment->product ?? null;

        if (is_string($primary) && $primary !== '') {
            $handles[] = $primary;
        }

        return array_values(array_unique($handles));
    }

    /**
     * Whether one of the handles is a coaching product.
     *
     * By handle first — that list is the site's own word and needs no other
     * addon. By kind second, read straight off the products table, when
     * `statamic-products` has one. The brand of the matching product rides
     * along, for payments that carry none of their own.
     *
     * @param  list<string>  $handles
     * @return array{handle: string, brand_id: int|null}|null
     */
    protected function match(array $handles): ?array
    {
        $configured = array_values(array_filter((array) config('statamic-clientrooms.open_on_products', []), 'is_string'));

        foreach ($handles as $handle) {
            if (in_array($handle, $configured, true)) {
                return ['handle' => $handle, 'brand_id' => $this->productBrand($handle)];
            }
        }

        $types = array_values(array_filter((array) config('statamic-clientrooms.open_on_product_types', []), 'is_string'));

        if ($types === []) {
            return null;
        }

        try {
            if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'type')) {
                return null;
            }

            $row = DB::table('products')
                ->whereIn('handle', $handles)
                ->whereIn('type', $types)
                ->orderBy('id')
                ->first(['handle', 'brand_id']);
        } catch (Throwable $e) {
            Log::warning('statamic-clientrooms: could not read the products table to decide whether to open a room.', ['exception' => $e->getMessage()]);

            return null;
        }

        if ($row === null) {
            return null;
        }

        $brand = isset($row->brand_id) && (int) $row->brand_id > 0 ? (int) $row->brand_id : null;

        return ['handle' => (string) $row->handle, 'brand_id' => $brand];
    }

    protected function productBrand(string $handle): ?int
    {
        try {
            if (! Schema::hasTable('products') || ! Schema::hasColumn('products', 'brand_id')) {
                return null;
            }

            $brand = DB::table('products')->where('handle', $handle)->value('brand_id');

            return $brand !== null && (int) $brand > 0 ? (int) $brand : null;
        } catch (Throwable) {
            return null;
        }
    }
}
