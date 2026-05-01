<?php

/*
|--------------------------------------------------------------------------
| JwtGuard
|--------------------------------------------------------------------------
|
| Handles stateless (API) authentication using JSON Web Tokens.
| Reads the Bearer token from the Authorization header, validates it
| via the existing Jwt class, and resolves the user from the database.
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Auth\Guards;

use Slenix\Supports\Auth\UserProvider;
use Slenix\Supports\Security\Jwt;

class JwtGuard implements GuardInterface
{
    /**
     * Default token lifetime in seconds (1 hour).
     *
     * @var int
     */
    private const DEFAULT_TTL = 3600;

    /**
     * The resolved user model, cached for the duration of the request.
     *
     * @var object|null
     */
    private ?object $resolvedUser = null;

    /**
     * The raw JWT string — set after a successful login() or attempt().
     *
     * @var string|null
     */
    private ?string $token = null;

    /**
     * The decoded and validated JWT payload from the current request.
     *
     * @var array|null
     */
    private ?array $payload = null;

    /**
     * Whether the token has already been extracted from the request.
     * Prevents redundant header parsing on subsequent calls.
     *
     * @var bool
     */
    private bool $tokenResolved = false;

    /**
     * The UserProvider instance used to retrieve the User model.
     *
     * @var UserProvider
     */
    private UserProvider $provider;

    /**
     * The Jwt instance used to generate and validate tokens.
     *
     * @var Jwt
     */
    private Jwt $jwt;

    /**
     * Create a new JwtGuard instance.
     *
     * @param UserProvider $provider
     * @param Jwt          $jwt
     */
    public function __construct(UserProvider $provider, Jwt $jwt)
    {
        $this->provider = $provider;
        $this->jwt      = $jwt;
    }

    // -------------------------------------------------------------------------
    // GuardInterface
    // -------------------------------------------------------------------------

    /**
     * Determine if the current request has a valid authenticated user.
     *
     * @return bool
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Determine if the current request has no authenticated user.
     *
     * @return bool
     */
    public function guest(): bool
    {
        return !$this->check();
    }

    /**
     * Get the authenticated user from the Bearer token.
     *
     * Resolution order:
     *   1. In-memory cache  — avoids re-parsing and DB hit per call.
     *   2. Authorization header — extracts, validates, and loads user.
     *
     * @return object|null
     */
    public function user(): ?object
    {
        if ($this->resolvedUser !== null) {
            return $this->resolvedUser;
        }

        $payload = $this->getValidPayload();

        if ($payload === null) {
            return null;
        }

        $userId = $payload['sub'] ?? null;

        if ($userId === null) {
            return null;
        }

        $this->resolvedUser = $this->provider->retrieveById($userId);

        return $this->resolvedUser;
    }

    /**
     * Get the ID of the currently authenticated user.
     *
     * @return int|string|null
     */
    public function id(): int|string|null
    {
        return $this->user()?->getAuthIdentifier();
    }

    /**
     * Validate credentials and — on success — issue a JWT.
     *
     * Unlike SessionGuard, a successful login generates a token rather
     * than writing to the session. Retrieve the token with getToken().
     *
     * @param  array $credentials  ['email' => ..., 'password' => ...]
     * @param  bool  $remember     Ignored — JWTs are stateless by nature.
     * @return bool
     */
    public function attempt(array $credentials, bool $remember = false): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        if ($user === null) {
            return false;
        }

        if (!$this->provider->validateCredentials($user, $credentials)) {
            return false;
        }

        $this->login($user);
        return true;
    }

    /**
     * Issue a JWT for the given user without verifying credentials.
     *
     * @param  object $user     Must implement getAuthIdentifier().
     * @param  bool   $remember Ignored — JWTs are stateless by nature.
     * @return void
     */
    public function login(object $user, bool $remember = false): void
    {
        $this->resolvedUser = $user;
        $this->token        = $this->jwt->generate(
            ['sub' => $user->getAuthIdentifier()],
            self::DEFAULT_TTL
        );
        $this->payload = $this->jwt->validate($this->token);
    }

    /**
     * Clear the in-memory authentication state.
     *
     * JWTs are stateless — the issued token cannot be invalidated here.
     * For true revocation, maintain a token blocklist in the database
     * and check it inside UserProvider or a middleware.
     *
     * @return void
     */
    public function logout(): void
    {
        $this->resolvedUser  = null;
        $this->token         = null;
        $this->payload       = null;
        $this->tokenResolved = false;
    }

    /**
     * Validate credentials without logging in or issuing a token.
     *
     * @param  array $credentials
     * @return bool
     */
    public function validate(array $credentials): bool
    {
        $user = $this->provider->retrieveByCredentials($credentials);

        if ($user === null) {
            return false;
        }

        return $this->provider->validateCredentials($user, $credentials);
    }

    // -------------------------------------------------------------------------
    // JWT-specific helpers
    // -------------------------------------------------------------------------

    /**
     * Get the raw JWT string generated by the most recent login() or attempt().
     *
     * Typical API response pattern:
     *
     *   if (auth('api')->attempt($credentials)) {
     *       return response()->json(['token' => auth('api')->getToken()]);
     *   }
     *
     * @return string|null
     */
    public function getToken(): ?string
    {
        return $this->token;
    }

    /**
     * Get the decoded payload from the current request's token.
     * Returns null if no valid token is present in the request.
     *
     * @return array|null
     */
    public function getPayload(): ?array
    {
        return $this->getValidPayload();
    }

    /**
     * Issue a new token for the given user with a custom lifetime.
     *
     * Useful for refresh-token flows or long-lived API keys.
     *
     * @param  object $user The user to issue the token for.
     * @param  int    $ttl  Token lifetime in seconds.
     * @return string       The raw JWT string.
     */
    public function issueToken(object $user, int $ttl): string
    {
        return $this->jwt->generate(
            ['sub' => $user->getAuthIdentifier()],
            $ttl
        );
    }

    // -------------------------------------------------------------------------
    // Request parsing internals
    // -------------------------------------------------------------------------

    /**
     * Lazily extract and validate the token from the current request.
     *
     * Only parses the header once per request lifecycle. Subsequent calls
     * return the cached payload without re-validating.
     *
     * @return array|null The decoded payload, or null if invalid or missing.
     */
    private function getValidPayload(): ?array
    {
        if ($this->tokenResolved) {
            return $this->payload;
        }

        $this->tokenResolved = true;
        $raw = $this->extractTokenFromRequest();

        if ($raw === null) {
            return null;
        }

        $this->payload = $this->jwt->validate($raw);

        if ($this->payload !== null) {
            $this->token = $raw;
        }

        return $this->payload;
    }

    /**
     * Extract the raw token string from the current HTTP request.
     *
     * Checks in the following order:
     *   1. Authorization: Bearer <token>  — standard header
     *   2. Authorization: <token>         — bare token fallback
     *   3. ?token=<token>                 — query string (WebSocket handshakes)
     *
     * @return string|null
     */
    private function extractTokenFromRequest(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION']
            ?? apache_request_headers()['Authorization']
            ?? null;

        if ($header !== null) {
            if (str_starts_with($header, 'Bearer ')) {
                return substr($header, 7);
            }
            if (trim($header) !== '') {
                return trim($header);
            }
        }

        $query = $_GET['token'] ?? null;

        return is_string($query) && $query !== '' ? $query : null;
    }
}