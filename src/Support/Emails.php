<?php

namespace Goldnead\ClientRooms\Support;

/**
 * One rule for the key column: trimmed and lower-cased, nothing cleverer.
 *
 * The same rule LeadHub applies by default, so a room and a contact for the
 * same person land on the same string. No provider-specific tricks: they
 * break business addresses, and a room opened for the wrong person is worse
 * than two rooms for the same one.
 */
final class Emails
{
    public static function normalize(?string $email): ?string
    {
        if ($email === null) {
            return null;
        }

        $value = mb_strtolower(trim($email));

        return $value === '' ? null : $value;
    }
}
