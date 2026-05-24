<?php

/*
 |--------------------------------------------------------------------------
 | Slenix Crypt — End-to-End Encryption
 |--------------------------------------------------------------------------
 |
 | Secure encryption class for the Slenix Framework.
 | Inspired by Laravel's Illuminate\Encryption, featuring native support
 | for asymmetric cryptography (RSA) and key derivation (PBKDF2/Argon2).
 |
 | Supported Algorithms:
 |   Symmetric  → AES-256-GCM (Authenticated, Default) / AES-256-CBC
 |   Asymmetric → RSA-OAEP (SHA-256)
 |   Hashing    → Argon2id (Default) / Bcrypt
 |   Signature  → HMAC-SHA256 / HMAC-SHA512 / Ed25519 (OpenSSL)
 |
 */

declare(strict_types=1);

namespace Slenix\Supports\Security;

use RuntimeException;
use InvalidArgumentException;

class Crypt
{
    public const ALGO_AES_GCM = 'aes-256-gcm';
    public const ALGO_AES_CBC = 'aes-256-cbc';

    private const GCM_TAG_LENGTH  = 16;   // bytes
    private const GCM_IV_LENGTH   = 12;   // bytes (96 bits — NIST standard)
    private const CBC_IV_LENGTH   = 16;   // bytes

    /**
     * Encrypts data using AES-256-GCM (AEAD — Authenticated Encryption).
     * Returns a base64url payload formatted as: iv.ciphertext.tag
     *
     * @param string $data The plaintext data to encrypt.
     * @param string $key  The secret key (minimum 32 bytes).
     * @param string $algo The encryption algorithm (self::ALGO_AES_GCM | self::ALGO_AES_CBC).
     * @param string $aad  Additional Authenticated Data (optional, GCM only).
     * @return string      The encrypted payload (base64url-encoded).
     *
     * @throws RuntimeException If the encryption process fails.
     */
    public static function encrypt(
        string $data,
        string $key,
        string $algo = self::ALGO_AES_GCM,
        string $aad = ''
    ): string {
        static::assertKeyLength($key);

        $ivLength = $algo === self::ALGO_AES_CBC ? self::CBC_IV_LENGTH : self::GCM_IV_LENGTH;
        $iv = random_bytes($ivLength);

        if ($algo === self::ALGO_AES_GCM) {
            $tag = '';
            $cipher = openssl_encrypt(
                $data,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $aad,
                self::GCM_TAG_LENGTH
            );

            if ($cipher === false) {
                throw new RuntimeException('Crypt: encryption failed (GCM).');
            }

            // Format: iv (12B) | tag (16B) | ciphertext
            $payload = $iv . $tag . $cipher;

        } else {
            // AES-256-CBC with HMAC-SHA256 for authentication (Encrypt-then-MAC)
            $cipher = openssl_encrypt($data, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

            if ($cipher === false) {
                throw new RuntimeException('Crypt: encryption failed (CBC).');
            }

            $mac = hash_hmac('sha256', $iv . $cipher, $key, true);
            // Format: iv (16B) | mac (32B) | ciphertext
            $payload = $iv . $mac . $cipher;
        }

        return static::base64UrlEncode($payload);
    }

    /**
     * Decrypts a payload encrypted by the encrypt() method.
     *
     * @param string $payload The encrypted payload (base64url).
     * @param string $key     The secret key.
     * @param string $algo    The encryption algorithm used during encryption.
     * @param string $aad     Additional Authenticated Data (must match the encryption AAD).
     * @return string         The decrypted original data.
     *
     * @throws RuntimeException If the payload is invalid or decryption fails.
     */
    public static function decrypt(
        string $payload,
        string $key,
        string $algo = self::ALGO_AES_GCM,
        string $aad = ''
    ): string {
        static::assertKeyLength($key);

        $raw = static::base64UrlDecode($payload);

        if ($algo === self::ALGO_AES_GCM) {
            if (strlen($raw) < self::GCM_IV_LENGTH + self::GCM_TAG_LENGTH) {
                throw new RuntimeException('Crypt: invalid payload (GCM).');
            }

            $iv         = substr($raw, 0, self::GCM_IV_LENGTH);
            $tag        = substr($raw, self::GCM_IV_LENGTH, self::GCM_TAG_LENGTH);
            $ciphertext = substr($raw, self::GCM_IV_LENGTH + self::GCM_TAG_LENGTH);

            $plain = openssl_decrypt(
                $ciphertext,
                'aes-256-gcm',
                $key,
                OPENSSL_RAW_DATA,
                $iv,
                $tag,
                $aad
            );

            if ($plain === false) {
                throw new RuntimeException('Crypt: decryption failed — payload tampered or wrong key.');
            }

        } else {
            // AES-256-CBC
            $ivLen = self::CBC_IV_LENGTH;
            $macLen = 32;

            if (strlen($raw) < $ivLen + $macLen) {
                throw new RuntimeException('Crypt: invalid payload (CBC).');
            }

            $iv         = substr($raw, 0, $ivLen);
            $mac        = substr($raw, $ivLen, $macLen);
            $ciphertext = substr($raw, $ivLen + $macLen);

            // Verify MAC before decryption to prevent timing attacks
            $expectedMac = hash_hmac('sha256', $iv . $ciphertext, $key, true);
            if (!hash_equals($expectedMac, $mac)) {
                throw new RuntimeException('Crypt: MAC verification failed — payload tampered.');
            }

            $plain = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

            if ($plain === false) {
                throw new RuntimeException('Crypt: decryption failed (CBC).');
            }
        }

        return $plain;
    }

    /**
     * Encrypts and serializes any PHP value (arrays, objects, etc.).
     *
     * @param mixed  $data Any serializable PHP value.
     * @param string $key  The secret key.
     * @return string      The encrypted payload.
     */
    public static function encryptSerialize(mixed $data, string $key): string
    {
        return static::encrypt(serialize($data), $key);
    }

    /**
     * Decrypts and unserializes a payload encrypted by encryptSerialize().
     *
     * @param string $payload The encrypted payload.
     * @param string $key     The secret key.
     * @return mixed          The decrypted and unserialized original value.
     */
    public static function decryptSerialize(string $payload, string $key): mixed
    {
        return unserialize(static::decrypt($payload, $key));
    }

    /**
     * Generates a secure RSA key pair.
     * Returns an array: ['private' => '...PEM...', 'public' => '...PEM...']
     *
     * @param int $bits The key size in bits (e.g., 2048 | 4096).
     * @return array    An array containing the private and public keys.
     * 
     * @throws RuntimeException If key generation or export fails.
     */
    public static function generateKeyPair(int $bits = 4096): array
    {
        $config = [
            'digest_alg'       => 'sha256',
            'private_key_bits' => $bits,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $resource = openssl_pkey_new($config);

        if ($resource === false) {
            throw new RuntimeException('Crypt: failed to generate RSA key pair. Check openssl extension.');
        }

        openssl_pkey_export($resource, $privateKey);
        $details = openssl_pkey_get_details($resource);

        if ($details === false || !isset($details['key'])) {
            throw new RuntimeException('Crypt: failed to export RSA public key.');
        }

        return [
            'private' => $privateKey,
            'public'  => $details['key'],
        ];
    }

    /**
     * Encrypts data using an RSA public key (RSA-OAEP with SHA-256).
     * Only the holder of the matching private key can decrypt this data.
     *
     * @param string $data      The data to encrypt (max ~470 bytes for RSA-4096).
     * @param string $publicKey The PEM-formatted public key.
     * @return string           The base64url-encoded encrypted payload.
     *
     * @throws InvalidArgumentException If the public key is invalid.
     * @throws RuntimeException         If encryption fails (e.g., data exceeds limits).
     */
    public static function encryptAsymmetric(string $data, string $publicKey): string
    {
        $key = openssl_pkey_get_public($publicKey);

        if ($key === false) {
            throw new InvalidArgumentException('Crypt: invalid RSA public key.');
        }

        $encrypted = '';
        $result = openssl_public_encrypt($data, $encrypted, $key, OPENSSL_PKCS1_OAEP_PADDING);

        if (!$result) {
            throw new RuntimeException('Crypt: RSA encryption failed. Data may be too large for the key size.');
        }

        return static::base64UrlEncode($encrypted);
    }

    /**
     * Decrypts data using an RSA private key.
     *
     * @param string $payload    The base64url-encoded encrypted payload.
     * @param string $privateKey The PEM-formatted private key.
     * @param string $passphrase The private key passphrase (if applicable).
     * @return string            The decrypted plaintext data.
     *
     * @throws InvalidArgumentException If the private key or passphrase is invalid.
     * @throws RuntimeException         If decryption fails.
     */
    public static function decryptAsymmetric(
        string $payload,
        string $privateKey,
        string $passphrase = ''
    ): string {
        $key = openssl_pkey_get_private($privateKey, $passphrase ?: null);

        if ($key === false) {
            throw new InvalidArgumentException('Crypt: invalid RSA private key or wrong passphrase.');
        }

        $decrypted = '';
        $result = openssl_private_decrypt(
            static::base64UrlDecode($payload),
            $decrypted,
            $key,
            OPENSSL_PKCS1_OAEP_PADDING
        );

        if (!$result) {
            throw new RuntimeException('Crypt: RSA decryption failed.');
        }

        return $decrypted;
    }

    /**
     * Hybrid Encryption: RSA + AES-256-GCM.
     * Recommended for large payloads — uses AES for data and RSA to secure the AES key.
     * Returns a base64-encoded JSON payload containing the encrypted key and ciphertext.
     *
     * @param string $data      The plaintext data to encrypt (any size).
     * @param string $publicKey The PEM-formatted RSA public key.
     * @return string           The hybrid encrypted envelope payload.
     */
    public static function encryptHybrid(string $data, string $publicKey): string
    {
        // Generate an ephemeral 32-byte AES key
        $aesKey = random_bytes(32);

        // Encrypt the data with AES-256-GCM
        $ciphertext = static::encrypt($data, $aesKey);

        // Encrypt the ephemeral AES key with RSA
        $encryptedKey = static::encryptAsymmetric($aesKey, $publicKey);

        return base64_encode(json_encode([
            'k' => $encryptedKey,   // RSA-encrypted AES key
            'c' => $ciphertext,     // AES-GCM ciphertext
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * Decrypts a hybrid payload generated by encryptHybrid().
     *
     * @param string $payload    The base64 payload from encryptHybrid().
     * @param string $privateKey The PEM-formatted RSA private key.
     * @param string $passphrase The private key passphrase (if applicable).
     * @return string            The decrypted original data.
     *
     * @throws InvalidArgumentException If the payload is malformed.
     */
    public static function decryptHybrid(
        string $payload,
        string $privateKey,
        string $passphrase = ''
    ): string {
        $decoded = json_decode(base64_decode($payload), true, 512, JSON_THROW_ON_ERROR);

        if (!isset($decoded['k'], $decoded['c'])) {
            throw new InvalidArgumentException('Crypt: malformed hybrid payload.');
        }

        // Recover the ephemeral AES key
        $aesKey = static::decryptAsymmetric($decoded['k'], $privateKey, $passphrase);

        // Decrypt the ciphertext using the recovered AES key
        return static::decrypt($decoded['c'], $aesKey);
    }

    /**
     * Generates a secure cryptographic password hash.
     * Default: Argon2id (highly resilient against GPU/ASIC attacks).
     *
     * @param string $password  The plaintext password.
     * @param string $algorithm The hashing algorithm ('argon2id' | 'argon2i' | 'bcrypt').
     * @param array  $options   Algorithm-specific options.
     * @return string           The secure password hash string.
     *
     * @throws InvalidArgumentException If an unknown algorithm is provided.
     * @throws RuntimeException         If the hashing process fails.
     */
    public static function hashPassword(
        string $password,
        string $algorithm = 'argon2id',
        array $options = []
    ): string {
        $algo = match ($algorithm) {
            'argon2id' => PASSWORD_ARGON2ID,
            'argon2i'  => PASSWORD_ARGON2I,
            'bcrypt'   => PASSWORD_BCRYPT,
            default    => throw new InvalidArgumentException("Crypt: unknown algorithm '{$algorithm}'."),
        };

        $defaultOptions = match ($algorithm) {
            'bcrypt'  => ['cost' => 12],
            default   => [
                'memory_cost' => 65536,   // 64 MB
                'time_cost'   => 4,       // 4 iterations
                'threads'     => 2,
            ],
        };

        $hash = password_hash($password, $algo, array_merge($defaultOptions, $options));

        if ($hash === false) {
            throw new RuntimeException('Crypt: password hashing failed.');
        }

        return $hash;
    }

    /**
     * Verifies that a plaintext password matches a given hash (timing-safe).
     *
     * @param string $password The plaintext password.
     * @param string $hash     The hash generated by hashPassword().
     * @return bool            True if the password matches, false otherwise.
     */
    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    /**
     * Checks if an existing hash needs to be rehashed based on updated settings.
     *
     * @param string $hash      The current password hash.
     * @param string $algorithm The desired algorithm.
     * @param array  $options   The desired configuration options.
     * @return bool             True if rehashing is required, false otherwise.
     */
    public static function needsRehash(
        string $hash,
        string $algorithm = 'argon2id',
        array $options = []
    ): bool {
        $algo = match ($algorithm) {
            'argon2id' => PASSWORD_ARGON2ID,
            'argon2i'  => PASSWORD_ARGON2I,
            'bcrypt'   => PASSWORD_BCRYPT,
            default    => PASSWORD_ARGON2ID,
        };

        return password_needs_rehash($hash, $algo, $options);
    }

    /**
     * Derives a cryptographic key from a password using PBKDF2.
     * Ideal for transforming human-readable passwords into AES keys.
     *
     * @param string $password   The input password or passphrase.
     * @param string $salt       A secure random salt (generate using generateSalt()).
     * @param int    $length     The output key length in bytes (32 bytes = 256 bits).
     * @param int    $iterations The number of iterations (recommended minimum: 100,000).
     * @param string $algo       The underlying hashing algorithm.
     * @return string            The derived raw binary key material.
     *
     * @throws RuntimeException If the key derivation process fails.
     */
    public static function deriveKey(
        string $password,
        string $salt,
        int $length = 32,
        int $iterations = 100_000,
        string $algo = 'sha256'
    ): string {
        $key = hash_pbkdf2($algo, $password, $salt, $iterations, $length, true);

        if ($key === false) {
            throw new RuntimeException('Crypt: PBKDF2 key derivation failed.');
        }

        return $key;
    }

    /**
     * Derives a sub-key from a master key using HKDF (RFC 5869).
     * Useful for safely branching cryptographic keys for specific contexts.
     *
     * @param string $key    The master input key material (IKM).
     * @param string $info   Context/application-specific information string.
     * @param int    $length The desired output key length in bytes.
     * @param string $salt   An optional salt value.
     * @param string $algo   The underlying hashing algorithm.
     * @return string        The cryptographically isolated derived sub-key.
     *
     * @throws RuntimeException If HKDF key derivation fails.
     */
    public static function deriveKeyHkdf(
        string $key,
        string $info = '',
        int $length = 32,
        string $salt = '',
        string $algo = 'sha256'
    ): string {
        $derived = hash_hkdf($algo, $key, $length, $info, $salt);

        if ($derived === false) {
            throw new RuntimeException('Crypt: HKDF key derivation failed.');
        }

        return $derived;
    }

    /**
     * Generates a cryptographically secure random salt.
     *
     * @param int $length The desired length in bytes (default 32 bytes).
     * @return string     The raw random binary bytes.
     */
    public static function generateSalt(int $length = 32): string
    {
        return random_bytes($length);
    }

    /**
     * Generates an HMAC signature for the given data.
     * Example: Crypt::sign('message', $key) → base64url string
     *
     * @param string $data The input data to sign.
     * @param string $key  The secret key.
     * @param string $algo The hashing algorithm ('sha256' | 'sha512').
     * @param bool   $raw  If true, returns raw bytes; if false, returns base64url.
     * @return string      The calculated signature.
     */
    public static function sign(
        string $data,
        string $key,
        string $algo = 'sha256',
        bool $raw = false
    ): string {
        $mac = hash_hmac($algo, $data, $key, true);
        return $raw ? $mac : static::base64UrlEncode($mac);
    }

    /**
     * Verifies an HMAC signature using a timing-safe comparison.
     *
     * @param string $data      The original payload data.
     * @param string $signature The signature payload to verify (base64url or raw).
     * @param string $key       The signing secret key.
     * @param string $algo      The hashing algorithm used to sign the data.
     * @param bool   $raw       True if the signature parameter is raw binary bytes.
     * @return bool             True if valid, false otherwise.
     */
    public static function verify(
        string $data,
        string $signature,
        string $key,
        string $algo = 'sha256',
        bool $raw = false
    ): bool {
        $expected = static::sign($data, $key, $algo, $raw);
        $compare  = $signature;

        return hash_equals($expected, $compare);
    }

    /**
     * Signs data using an RSA private key (RSA-SHA256).
     *
     * @param string $data       The data payload to sign.
     * @param string $privateKey The PEM-formatted private key.
     * @param string $passphrase The private key passphrase (if applicable).
     * @return string            The base64url-encoded RSA signature.
     *
     * @throws InvalidArgumentException If the private key cannot be parsed.
     * @throws RuntimeException         If the signing operation fails.
     */
    public static function signAsymmetric(
        string $data,
        string $privateKey,
        string $passphrase = ''
    ): string {
        $key = openssl_pkey_get_private($privateKey, $passphrase ?: null);

        if ($key === false) {
            throw new InvalidArgumentException('Crypt: invalid private key for signing.');
        }

        $signature = '';
        if (!openssl_sign($data, $signature, $key, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Crypt: RSA signing failed.');
        }

        return static::base64UrlEncode($signature);
    }

    /**
     * Verifies an RSA digital signature using a public key.
     *
     * @param string $data      The original unsigned data payload.
     * @param string $signature The base64url-encoded signature to verify.
     * @param string $publicKey The PEM-formatted RSA public key.
     * @return bool             True if the signature matches, false otherwise.
     *
     * @throws InvalidArgumentException If the public key cannot be parsed.
     */
    public static function verifyAsymmetric(
        string $data,
        string $signature,
        string $publicKey
    ): bool {
        $key = openssl_pkey_get_public($publicKey);

        if ($key === false) {
            throw new InvalidArgumentException('Crypt: invalid public key for verification.');
        }

        $result = openssl_verify(
            $data,
            static::base64UrlDecode($signature),
            $key,
            OPENSSL_ALGO_SHA256
        );

        return $result === 1;
    }

    /**
     * Generates a cryptographically secure random token.
     *
     * @param int    $bytes  The entropy size in bytes (default 32 bytes = 256 bits).
     * @param string $format The encoding format ('hex' | 'base64' | 'base64url').
     * @return string        The encoded token string.
     */
    public static function generateToken(int $bytes = 32, string $format = 'hex'): string
    {
        $raw = random_bytes($bytes);

        return match ($format) {
            'base64'    => base64_encode($raw),
            'base64url' => static::base64UrlEncode($raw),
            default     => bin2hex($raw),
        };
    }

    /**
     * Generates an expiration-bound token (HMAC-signed JSON payload containing timestamps).
     * Example: Crypt::generateTimedToken($key, 3600) → token valid for 1 hour.
     *
     * @param string $key    The secret signing key.
     * @param int    $ttl    Time-to-live parameter in seconds.
     * @param array  $claims Optional metadata or attributes to insert into the token.
     * @return string        The formatted timed token string (payload.signature).
     */
    public static function generateTimedToken(string $key, int $ttl = 3600, array $claims = []): string
    {
        $payload = array_merge($claims, [
            'iat' => time(),
            'exp' => time() + $ttl,
            'jti' => bin2hex(random_bytes(8)),
        ]);

        $encoded = static::base64UrlEncode(json_encode($payload, JSON_THROW_ON_ERROR));
        $signature = static::sign($encoded, $key);

        return $encoded . '.' . $signature;
    }

    /**
     * Validates a timed token and unpacks its payload data.
     *
     * @param string $token The token generated via generateTimedToken().
     * @param string $key   The secret signing key.
     * @return array        The token's raw claims payload.
     *
     * @throws RuntimeException If the token format is corrupt, signature mismatches, or it has expired.
     */
    public static function validateTimedToken(string $token, string $key): array
    {
        $parts = explode('.', $token, 2);

        if (count($parts) !== 2) {
            throw new RuntimeException('Crypt: malformed token.');
        }

        [$encoded, $signature] = $parts;

        if (!static::verify($encoded, $signature, $key)) {
            throw new RuntimeException('Crypt: token signature invalid.');
        }

        $payload = json_decode(static::base64UrlDecode($encoded), true, 512, JSON_THROW_ON_ERROR);

        if (!isset($payload['exp']) || $payload['exp'] < time()) {
            throw new RuntimeException('Crypt: token has expired.');
        }

        return $payload;
    }

    /**
     * Generates a cryptographically secure random AES-256 key (32 bytes).
     * 
     * @return string The raw 32-byte key material.
     */
    public static function generateKey(): string
    {
        return random_bytes(32);
    }

    /**
     * Encodes raw string bytes into URL-safe base64 format without padding.
     * 
     * @param string $data The raw binary string data.
     * @return string      The base64url-encoded payload.
     */
    public static function base64UrlEncode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Decodes a URL-safe base64 string back into raw string bytes.
     * 
     * @param string $data The base64url-encoded string payload.
     * @return string      The raw binary string content.
     * 
     * @throws RuntimeException If base64 decoding fails.
     */
    public static function base64UrlDecode(string $data): string
    {
        $decoded = base64_decode(strtr($data, '-_', '+/') . str_repeat('=', (4 - strlen($data) % 4) % 4));

        if ($decoded === false) {
            throw new RuntimeException('Crypt: invalid base64url encoding.');
        }

        return $decoded;
    }

    /**
     * Validates that the provided key length meets cryptographic requirements for AES-256.
     * 
     * @param string $key The secret key.
     * 
     * @throws InvalidArgumentException If the key size is under 32 bytes.
     */
    private static function assertKeyLength(string $key): void
    {
        if (strlen($key) < 32) {
            throw new InvalidArgumentException(
                sprintf('Crypt: key must be at least 32 bytes, %d given. Use Crypt::generateKey() or Crypt::deriveKey().', strlen($key))
            );
        }
    }
}