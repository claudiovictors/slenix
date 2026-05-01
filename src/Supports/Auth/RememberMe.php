<?php

declare(strict_types=1);

namespace Slenix\Supports\Auth;

use Slenix\Database\Connection;

/*
|--------------------------------------------------------------------------
| RememberMe
|--------------------------------------------------------------------------
|
| Manages persistent "remember me" tokens stored in the remember_tokens
| table. The raw token is sent to the browser as a cookie; only its
| SHA-256 hash is stored in the database.
|
| Migration required:
|   CREATE TABLE remember_tokens (
|       id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
|       user_id    BIGINT UNSIGNED NOT NULL,
|       token      VARCHAR(64) NOT NULL UNIQUE,   -- SHA-256 hash
|       expires_at DATETIME    NOT NULL,
|       created_at DATETIME    NOT NULL
|   );
|
*/

class RememberMe
{
    /** Cookie / token lifetime: 30 days. */
    private const TTL_DAYS = 30;

    private \PDO $pdo;

    public function __construct()
    {
        $this->pdo = Connection::getInstance();
    }

    /**
     * Creates a new remember-me token for the given user.
     *
     * Stores only the SHA-256 hash in the DB.
     * Returns the raw (unhashed) token to be placed in the cookie.
     *
     * @param  int|string $userId
     * @return string     Raw token (64 hex chars).
     */
    public function create(int|string $userId): string
    {
        $raw    = bin2hex(random_bytes(32));          // 64-char hex
        $hashed = hash('sha256', $raw);
        $expiry = date('Y-m-d H:i:s', time() + (self::TTL_DAYS * 86400));

        $this->pdo->prepare(
            'INSERT INTO remember_tokens (user_id, token, expires_at, created_at)
             VALUES (:uid, :token, :exp, NOW())'
        )->execute([
            'uid'   => $userId,
            'token' => $hashed,
            'exp'   => $expiry,
        ]);

        return $raw;
    }

    /**
     * Validates a raw token from the cookie.
     *
     * Returns the user_id if valid and not expired, null otherwise.
     * Expired tokens are automatically deleted.
     *
     * @param  string $raw  Raw token from the browser cookie.
     * @return int|string|null
     */
    public function validate(string $raw): int|string|null
    {
        $hashed = hash('sha256', $raw);

        $stmt = $this->pdo->prepare(
            'SELECT user_id, expires_at FROM remember_tokens
             WHERE token = :token LIMIT 1'
        );
        $stmt->execute(['token' => $hashed]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // Token expired — clean up and reject.
        if (strtotime($row['expires_at']) < time()) {
            $this->deleteHash($hashed);
            return null;
        }

        return $row['user_id'];
    }

    /**
     * Deletes the token associated with the given raw value.
     * Called on logout to revoke the persistent session.
     */
    public function forget(string $raw): void
    {
        $this->deleteHash(hash('sha256', $raw));
    }

    /**
     * Deletes all remember-me tokens for a user.
     * Useful for "log out of all devices".
     */
    public function forgetAll(int|string $userId): void
    {
        $this->pdo->prepare(
            'DELETE FROM remember_tokens WHERE user_id = :uid'
        )->execute(['uid' => $userId]);
    }

    /**
     * Purges all expired tokens from the table.
     * Call this from a scheduled task (e.g. daily cron).
     */
    public function purgeExpired(): int
    {
        $stmt = $this->pdo->prepare(
            'DELETE FROM remember_tokens WHERE expires_at < NOW()'
        );
        $stmt->execute();
        return $stmt->rowCount();
    }

    private function deleteHash(string $hashed): void
    {
        $this->pdo->prepare(
            'DELETE FROM remember_tokens WHERE token = :token'
        )->execute(['token' => $hashed]);
    }
}