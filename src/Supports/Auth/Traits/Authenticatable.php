<?php

declare(strict_types=1);

namespace Slenix\Supports\Auth\Traits;

use Slenix\Database\Model;

/**
 * @mixin Model
 */
trait Authenticatable
{
    /**
     * Returns the primary key value used to identify the user.
     * Used by SessionGuard (stores in session) and JwtGuard (JWT 'sub' claim).
     */
    public function getAuthIdentifier(): int|string
    {
        return $this->{$this->primaryKey ?? 'id'};
    }

    /**
     * Returns the hashed password stored in the database.
     * Column name defaults to 'password' but can be overridden.
     */
    public function getAuthPassword(): string
    {
        $col = $this->authPasswordColumn ?? 'password';
        return (string) ($this->attributes[$col] ?? '');
    }

    /**
     * Verifies a plain-text password against the stored bcrypt hash.
     * Called by UserProvider::validateCredentials().
     */
    public function verifyPassword(string $plain): bool
    {
        return password_verify($plain, $this->getAuthPassword());
    }

    /**
     * Returns true if the stored hash needs to be rehashed (cost changed).
     * Call this after a successful login and re-save the user if true.
     *
     * @example
     *   if (auth()->user()->passwordNeedsRehash()) {
     *       $user->password = hash_make($plain);
     *       $user->save();
     *   }
     */
    public function passwordNeedsRehash(int $cost = 12): bool
    {
        return password_needs_rehash(
            $this->getAuthPassword(),
            PASSWORD_BCRYPT,
            ['cost' => $cost]
        );
    }

    /**
     * Hashes and sets the password attribute.
     * Saves you from calling hash_make() manually in controllers.
     *
     * @example $user->setPassword('new_plain_password');
     */
    public function setPassword(string $plain, int $cost = 12): static
    {
        $col = $this->authPasswordColumn ?? 'password';
        $this->setAttribute($col, password_hash($plain, PASSWORD_BCRYPT, ['cost' => $cost]));
        return $this;
    }
}