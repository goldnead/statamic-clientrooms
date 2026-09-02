<?php

namespace Goldnead\ClientRooms\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * The soft seam to `goldnead/statamic-brand-context`.
 *
 * Never a hard dependency. A single-coach install has one brand and no
 * tenancy, and for it every method here answers "zero" and nothing changes.
 * `class_exists` on a string rather than an import, so that nothing in this
 * file can autoload a package that is not there. The three-state reasoning
 * (single, multi, unknown) is `statamic-payments`' and is kept on purpose:
 * "no tenants here" and "there are tenants and I could not find out which"
 * must not collapse into the same boolean.
 */
final class Brands
{
    /** The value on every row of a single-brand install. */
    public const NONE = 0;

    /** The sibling's global scope, by name. Registered under this name too. */
    public const SCOPE = 'Goldnead\BrandContext\Scopes\BrandScope';

    public const SINGLE = 'single';

    public const MULTI = 'multi';

    public const UNKNOWN = 'unknown';

    public static function available(): bool
    {
        if (! class_exists(self::SCOPE)) {
            return false;
        }

        try {
            return app()->bound('brand-context');
        } catch (Throwable) {
            return false;
        }
    }

    public static function mode(): string
    {
        if (! self::available()) {
            return self::SINGLE;
        }

        try {
            return app('brand-context')->multiBrandEnabled() ? self::MULTI : self::SINGLE;
        } catch (Throwable $e) {
            Log::error('statamic-clientrooms: brand-context would not say whether this install is multi-brand.', [
                'exception' => $e->getMessage(),
            ]);

            return self::UNKNOWN;
        }
    }

    public static function multiBrand(): bool
    {
        return self::mode() === self::MULTI;
    }

    /**
     * The brand to write onto a row being created now.
     *
     * Zero unless this is a multi-brand install with a brand current — a
     * console command or a webhook has none, and a guessed brand is how a
     * client ends up in another tenant's list.
     */
    public static function stampId(): int
    {
        if (! self::multiBrand()) {
            return self::NONE;
        }

        try {
            $manager = app('brand-context');

            return $manager->hasCurrent() ? (int) $manager->currentId() : self::NONE;
        } catch (Throwable) {
            return self::NONE;
        }
    }

    /** The brand's display name, or null where there is nothing to name. */
    public static function label(int $brandId): ?string
    {
        if (! self::available() || $brandId === self::NONE) {
            return null;
        }

        try {
            $model = '\Goldnead\BrandContext\Models\Brand';

            return $model::query()->whereKey($brandId)->value('name');
        } catch (Throwable) {
            return null;
        }
    }
}
