<?php

/*
|--------------------------------------------------------------------------
| RateLimit Class
|--------------------------------------------------------------------------
|
| File-based rate limiter built on top of Slenix's Cache system.
| Tracks request counts per key (IP, user ID, session, or any custom key)
| within a fixed time window.
|
| The counter is stored as a JSON cache entry under a prefixed, hashed key.
| When the window expires the counter resets automatically on the next hit.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Security;

use Slenix\Supports\Cache\Cache;

class RateLimit
{

    /**
     * Cache key prefix used to namespace rate-limit entries and avoid
     * collisions with other cache entries stored by the application.
     *
     * @var string
     */
    private const PREFIX = 'rl_';

    /**
     * Attempts a rate-limited action.
     *
     * Increments the hit counter for the given key within the current window.
     * If the window has already expired a new one is opened automatically.
     * Returns a structured result array that the caller can use to decide
     * whether to allow or block the request and which headers to emit.
     *
     * @param string $key          Unique identifier for this rate-limit bucket.
     *                             Recommended formats:
     *                               - 'ip:1.2.3.4'
     *                               - 'user:42'
     *                               - 'login:1.2.3.4'
     *                               - 'api:user:42'
     * @param int    $maxAttempts  Maximum number of hits allowed within the window.
     * @param int    $decaySeconds Duration of the time window in seconds.
     *
     * @return array{
     *     allowed: bool,
     *     attempts: int,
     *     max_attempts: int,
     *     remaining: int,
     *     reset_at: int,
     *     retry_after: int
     * } Result details:
     *   - allowed      : Whether the current request should be processed.
     *   - attempts     : Total hits recorded in the current window (including this one).
     *   - max_attempts : The configured limit passed as $maxAttempts.
     *   - remaining    : Hits still available before the limit is reached (0 if blocked).
     *   - reset_at     : Unix timestamp when the window resets.
     *   - retry_after  : Seconds the client should wait before retrying (0 if allowed).
     */
    public static function attempt(string $key, int $maxAttempts, int $decaySeconds): array
    {
        $cacheKey = self::cacheKey($key);
        $now      = time();
        $record   = Cache::get($cacheKey);

        if ($record === null) {
            // First hit — open a new window.
            $record = [
                'attempts' => 1,
                'reset_at' => $now + $decaySeconds,
            ];
            Cache::put($cacheKey, $record, $decaySeconds);

        } elseif ($now >= $record['reset_at']) {
            // Previous window expired — start a fresh one.
            $record = [
                'attempts' => 1,
                'reset_at' => $now + $decaySeconds,
            ];
            Cache::put($cacheKey, $record, $decaySeconds);

        } else {
            // Still within the active window — increment the counter.
            $record['attempts']++;
            Cache::put($cacheKey, $record, max(1, $record['reset_at'] - $now));
        }

        $attempts   = $record['attempts'];
        $resetAt    = $record['reset_at'];
        $allowed    = $attempts <= $maxAttempts;
        $remaining  = max(0, $maxAttempts - $attempts);
        $retryAfter = (!$allowed && $now < $resetAt) ? ($resetAt - $now) : 0;

        return [
            'allowed'      => $allowed,
            'attempts'     => $attempts,
            'max_attempts' => $maxAttempts,
            'remaining'    => $remaining,
            'reset_at'     => $resetAt,
            'retry_after'  => $retryAfter,
        ];
    }

    /**
     * Returns how many attempts are still available for the given key
     * without incrementing the counter.
     *
     * Returns $maxAttempts when no active window exists, meaning the key
     * has never been hit or its window has already expired.
     *
     * @param string $key         Rate-limit bucket identifier.
     * @param int    $maxAttempts The configured limit to calculate remaining hits.
     *
     * @return int Number of remaining allowed hits (0 = limit already reached).
     */
    public static function remaining(string $key, int $maxAttempts): int
    {
        $record = Cache::get(self::cacheKey($key));

        if ($record === null || time() >= $record['reset_at']) {
            return $maxAttempts;
        }

        return max(0, $maxAttempts - $record['attempts']);
    }

    /**
     * Returns the number of seconds until the current rate-limit window resets.
     *
     * Returns 0 if no active window exists for the key — meaning the limit has
     * not been hit yet, or the previous window has already expired.
     *
     * @param string $key Rate-limit bucket identifier.
     *
     * @return int Seconds until the window resets, or 0 if no active window.
     */
    public static function availableIn(string $key): int
    {
        $record = Cache::get(self::cacheKey($key));

        if ($record === null || time() >= $record['reset_at']) {
            return 0;
        }

        return max(0, $record['reset_at'] - time());
    }

    /**
     * Determines whether the given key has exceeded the allowed limit
     * without modifying the stored counter.
     *
     * @param string $key         Rate-limit bucket identifier.
     * @param int    $maxAttempts The configured limit to compare against.
     *
     * @return bool True if the limit has been exceeded, false otherwise.
     */
    public static function tooManyAttempts(string $key, int $maxAttempts): bool
    {
        $record = Cache::get(self::cacheKey($key));

        if ($record === null || time() >= $record['reset_at']) {
            return false;
        }

        return $record['attempts'] >= $maxAttempts;
    }

    /**
     * Clears all recorded attempts for the given key by removing its cache entry.
     *
     * Use this after a successful authentication attempt, a successful password
     * reset, or any action that should lift the penalty for a particular client.
     *
     * Example — clear login throttle after a successful login:
     *
     *   RateLimit::clear('login:' . $request->ip());
     *
     * @param string $key Rate-limit bucket identifier to clear.
     *
     * @return void
     */
    public static function clear(string $key): void
    {
        Cache::forget(self::cacheKey($key));
    }

    /**
     * Returns the raw rate-limit record stored in the cache for the given key,
     * or null if no active record exists.
     *
     * Useful for diagnostics, logging, and building custom rate-limit responses.
     *
     * @param string $key Rate-limit bucket identifier.
     *
     * @return array{attempts: int, reset_at: int}|null
     *   The stored record, or null if the key has not been hit or has expired.
     */
    public static function get(string $key): ?array
    {
        return Cache::get(self::cacheKey($key));
    }

    /**
     * Builds the most appropriate rate-limit key for the current request.
     *
     * Resolves the caller's identity in the following priority order:
     *
     *   1. JWT user_id  — stateless API clients authenticated via a validated JWT.
     *   2. Session user_id — authenticated web users with a PHP session.
     *   3. IP address   — universal fallback for anonymous callers.
     *
     * The resulting key follows one of two formats:
     *   "{$route}:user:{$userId}"  — when a user identity is resolved.
     *   "{$route}:ip:{$ip}"        — for anonymous / unauthenticated callers.
     *
     * @param string      $route       A short descriptive prefix that scopes the limit
     *                                 to a specific route or action
     *                                 (e.g. 'api', 'login', 'password-reset').
     * @param string|null $ip          The client's IP address. When null the method
     *                                 resolves it automatically via {@see resolveIp()}.
     * @param string|null $jwtUserId   The user_id claim from a validated JWT payload,
     *                                 or null if no JWT is present or valid.
     * @param string|null $sessionKey  The $_SESSION key that holds the authenticated
     *                                 user's ID. Defaults to 'user_id'. Pass null to
     *                                 skip session-based identity resolution entirely.
     *
     * @return string The resolved rate-limit bucket key.
     */
    public static function buildKey(
        string  $route,
        ?string $ip         = null,
        ?string $jwtUserId  = null,
        ?string $sessionKey = 'user_id'
    ): string {
        // 1. JWT identity — preferred for stateless API calls.
        if ($jwtUserId !== null) {
            return "{$route}:user:{$jwtUserId}";
        }

        // 2. Session identity — preferred for stateful web requests.
        if ($sessionKey !== null && session_status() === PHP_SESSION_ACTIVE) {
            $sessionUserId = $_SESSION[$sessionKey] ?? null;

            if ($sessionUserId !== null) {
                return "{$route}:user:{$sessionUserId}";
            }
        }

        // 3. IP address — universal fallback.
        return "{$route}:ip:" . ($ip ?? self::resolveIp());
    }

    /**
     * Generates the namespaced, hashed cache key for a given rate-limit bucket.
     *
     * The raw key is MD5-hashed to produce a fixed-length, filesystem-safe
     * string that avoids issues with special characters in cache filenames.
     *
     * @param string $key Raw rate-limit bucket identifier.
     *
     * @return string Prefixed, hashed cache key ready for use with {@see Cache}.
     */
    private static function cacheKey(string $key): string
    {
        return self::PREFIX . md5($key);
    }

    /**
     * Resolves the client's IP address from common server variables.
     *
     * Checks the following headers in priority order, returning the first
     * value that passes PHP's FILTER_VALIDATE_IP check:
     *
     *   1. HTTP_CF_CONNECTING_IP  — Cloudflare real visitor IP.
     *   2. HTTP_CLIENT_IP         — Proxy-forwarded client IP.
     *   3. HTTP_X_FORWARDED_FOR   — Standard reverse-proxy forwarded IP.
     *                               Only the first (leftmost) address is used.
     *   4. REMOTE_ADDR            — Direct connection IP; always present.
     *
     * Returns '0.0.0.0' as a safe fallback when no valid IP can be resolved,
     * ensuring the rate limiter never throws in CLI or test contexts.
     *
     * @return string The resolved client IP address, or '0.0.0.0' on failure.
     */
    private static function resolveIp(): string
    {
        $candidates = [
            'HTTP_CF_CONNECTING_IP',
            'HTTP_CLIENT_IP',
            'HTTP_X_FORWARDED_FOR',
            'REMOTE_ADDR',
        ];

        foreach ($candidates as $header) {
            if (empty($_SERVER[$header])) {
                continue;
            }

            // X-Forwarded-For may contain a comma-separated list; the leftmost
            // entry represents the original client address.
            $ip = trim(explode(',', $_SERVER[$header])[0]);

            if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                return $ip;
            }
        }

        return '0.0.0.0';
    }
}