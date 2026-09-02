<?php

/*
 * Stand-ins for `goldnead/statamic-payments`, which is a suggest and not in
 * vendor. The event carries exactly what the real one carries — a `payment`
 * with `id`, `email`, `name`, `brand_id`, `product` and `items` — so the
 * listener is exercised against the shape it will meet. Loaded by the test
 * suite only, and scanned (not analysed) by PHPStan.
 */

namespace Goldnead\StatamicPayments\Events {
    if (! class_exists(PaymentPaid::class)) {
        class PaymentPaid
        {
            public function __construct(public readonly object $payment) {}
        }
    }
}

namespace Goldnead\StatamicPayments\Models {
    if (! class_exists(Payment::class)) {
        /**
         * @property int $id
         * @property string|null $email
         * @property string|null $name
         * @property int $brand_id
         * @property string|null $product
         * @property \Illuminate\Support\Collection<int, object> $items
         */
        class Payment
        {
            /** @param array<string, mixed> $attributes */
            public function __construct(public array $attributes = [])
            {
                $this->attributes['items'] = collect($this->attributes['items'] ?? [])
                    ->map(fn (array $item): object => (object) $item);
            }

            public function __get(string $name): mixed
            {
                return $this->attributes[$name] ?? null;
            }

            public function __isset(string $name): bool
            {
                return isset($this->attributes[$name]);
            }

            public function items(): mixed
            {
                return $this->attributes['items'];
            }
        }
    }
}
