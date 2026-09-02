<?php

namespace Goldnead\ClientRooms\Models\Concerns;

use Goldnead\ClientRooms\Support\Brands;
use Illuminate\Database\Eloquent\Builder;

/**
 * Brand isolation, only where a brand exists.
 *
 * Brand Context's own `HasBrand` trait imports its scope and its model, which
 * makes the trait a hard dependency the moment it is `use`d. This one does the
 * same two things — apply the global scope, stamp the column — but asks first
 * whether the sibling is installed. Without it the scope is simply not added
 * and every row carries brand `0`.
 */
trait BelongsToBrand
{
    public static function bootBelongsToBrand(): void
    {
        if (Brands::available()) {
            // A class name: Eloquent instantiates it. Not `new`, so that
            // nothing here can trigger an autoload when the sibling is absent.
            static::addGlobalScope(Brands::SCOPE);
        }

        static::creating(function ($model): void {
            if ($model->getAttribute('brand_id') === null) {
                $model->setAttribute('brand_id', Brands::stampId());
            }
        });
    }

    public function getBrandColumn(): string
    {
        return 'brand_id';
    }

    /**
     * Every brand's rows. For lookups that carry their own brand id, such as
     * the listener's "does this buyer already have a room".
     *
     * @param  Builder<static>  $query
     * @return Builder<static>
     */
    public function scopeAcrossBrands(Builder $query): Builder
    {
        if (Brands::available()) {
            return $query->withoutGlobalScope(Brands::SCOPE);
        }

        return $query;
    }
}
