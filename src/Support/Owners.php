<?php

namespace Goldnead\ClientRooms\Support;

use Statamic\Auth\User as StatamicUser;
use Statamic\Facades\User;
use Throwable;

/**
 * Statamic users, asked by id or address and answered as a label.
 *
 * A room's owner is a Statamic user, whatever driver the site runs. The id
 * is stored as a string for that reason, and the lookups here never throw:
 * an owner who has since been deleted costs an empty label, not a screen.
 *
 * Typed against `Statamic\Auth\User` rather than the contract: the contract
 * declares no `name()`, and every driver's user extends the abstract class
 * anyway. The id is read through `getAuthIdentifier()`, which both drivers
 * answer with the same value `id()` gives.
 */
final class Owners
{
    /** Resolve a user id or e-mail address to a user id, or null. */
    public static function resolveId(?string $idOrEmail): ?string
    {
        $user = self::find($idOrEmail);

        return $user !== null ? (string) $user->getAuthIdentifier() : null;
    }

    /** The signed-in user's id, or null outside a request. */
    public static function currentId(): ?string
    {
        try {
            $user = User::current();
        } catch (Throwable) {
            return null;
        }

        return $user instanceof StatamicUser ? (string) $user->getAuthIdentifier() : null;
    }

    public static function find(?string $idOrEmail): ?StatamicUser
    {
        if ($idOrEmail === null || trim($idOrEmail) === '') {
            return null;
        }

        try {
            $user = User::find($idOrEmail);

            if ($user === null && str_contains($idOrEmail, '@')) {
                $user = User::findByEmail($idOrEmail);
            }

            return $user instanceof StatamicUser ? $user : null;
        } catch (Throwable) {
            return null;
        }
    }

    public static function label(?string $id): ?string
    {
        $user = self::find($id);

        if ($user === null) {
            return null;
        }

        $name = $user->name();

        return is_string($name) && trim($name) !== '' ? $name : (string) $user->email();
    }

    /**
     * Every user, for the owner picker.
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        try {
            return User::all()
                ->filter(fn ($user) => $user instanceof StatamicUser)
                ->map(fn (StatamicUser $user): array => [
                    'value' => (string) $user->getAuthIdentifier(),
                    'label' => self::label((string) $user->getAuthIdentifier()) ?? (string) $user->email(),
                ])
                ->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }
}
