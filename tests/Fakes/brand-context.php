<?php

/*
 * Stand-ins for `goldnead/statamic-brand-context`, a suggest that is not in
 * vendor. The scope does what the real one does — nothing in single-brand
 * mode, the current brand in multi-brand mode, no rows when none is current —
 * and the manager answers the three questions this addon asks it. A test
 * that wants tenancy binds the manager as `brand-context`; every other test
 * runs with the binding absent, which is the state an install without the
 * sibling is in.
 */

namespace Goldnead\BrandContext\Scopes {
    if (! class_exists(BrandScope::class)) {
        class BrandScope implements \Illuminate\Database\Eloquent\Scope
        {
            public function apply(\Illuminate\Database\Eloquent\Builder $builder, \Illuminate\Database\Eloquent\Model $model): void
            {
                if (! app()->bound('brand-context')) {
                    return;
                }

                $manager = app('brand-context');

                if (! $manager->multiBrandEnabled()) {
                    return;
                }

                if (! $manager->hasCurrent()) {
                    $builder->whereRaw('1 = 0');

                    return;
                }

                $builder->where($model->getTable().'.brand_id', $manager->currentId());
            }
        }
    }
}

namespace Goldnead\BrandContext\Models {
    if (! class_exists(Brand::class)) {
        class Brand extends \Illuminate\Database\Eloquent\Model
        {
            protected $table = 'brands';

            protected $guarded = [];

            public $timestamps = false;
        }
    }
}

namespace Goldnead\ClientRooms\Tests\Fakes {
    class FakeBrandManager
    {
        public function __construct(public bool $multi = true, public ?int $current = null) {}

        public function multiBrandEnabled(): bool
        {
            return $this->multi;
        }

        public function hasCurrent(): bool
        {
            return $this->current !== null;
        }

        public function currentId(): int
        {
            return $this->current ?? 0;
        }

        public function setCurrent(?int $brand): static
        {
            $this->current = $brand;

            return $this;
        }
    }
}
