<?php

namespace Goldnead\ClientRooms\Support;

use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The soft seam to LeadHub's contacts. Read, never written: this addon does
 * not create contacts, it links to the one LeadHub already has.
 */
final class Contacts
{
    /** LeadHub's contact model, by name. A suggest, never imported. */
    public const MODEL = 'Goldnead\Leadhub\Models\Contact';

    public static function available(): bool
    {
        return class_exists(self::MODEL);
    }

    /**
     * The LeadHub contact id for this address in this brand, or null.
     */
    public static function idFor(string $email, int $brandId): ?int
    {
        if (! self::available()) {
            return null;
        }

        try {
            if (! Schema::hasTable('leadhub_contacts')) {
                return null;
            }

            $model = self::MODEL;
            $query = $model::query()->withoutGlobalScopes()->where('email_normalized', mb_strtolower(trim($email)));

            if (Schema::hasColumn('leadhub_contacts', 'brand_id')) {
                $query->where('brand_id', $brandId);
            }

            $id = $query->orderBy('id')->value('id');

            return $id !== null ? (int) $id : null;
        } catch (Throwable) {
            return null;
        }
    }
}
