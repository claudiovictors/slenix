<?php

/*
|--------------------------------------------------------------------------
| JWT Class
|--------------------------------------------------------------------------
|
| Stateless authentication using JSON Web Tokens (HS256).
|
*/

declare(strict_types=1);

namespace Slenix\Supports\Security;

class Jwt
{
    /** @var string Secret key for hashing */
    private string $secret;

    /**
     * Jwt constructor.
     * @param string|null $secret
     */
    public function __construct(?string $secret = null)
    {
        $this->secret = $secret ?? env('JWT_SECRET_TOKEN') ?: 'your_default_secret_key';
    }

    /**
     * Encodes a payload into a JWT string.
     * @param array $payload
     * @param int $expiresIn Seconds until expiration
     * @return string
     */
    public function generate(array $payload, int $expiresIn = 3600): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];

        $payload['iat'] = time();
        $payload['exp'] = time() + $expiresIn;

        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $this->secret, true);
        $signatureEncoded = $this->base64UrlEncode($signature);

        return "$headerEncoded.$payloadEncoded.$signatureEncoded";
    }

    /**
     * Validates a token and returns the decoded payload.
     * @param string $token
     * @return array|null
     */
    public function validate(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) return null;

        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;

        $signature = $this->base64UrlDecode($signatureEncoded);
        $expectedSignature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $this->secret, true);

        if (!hash_equals($signature, $expectedSignature)) return null;

        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);
        if (!$payload || !isset($payload['exp'])) return null;

        if ($payload['exp'] < time()) return null;

        return $payload;
    }

    /**
     * Base64Url encoding helper.
     * @param string $data
     * @return string
     */
    private function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    /**
     * Base64Url decoding helper.
     * @param string $data
     * @return string
     */
    private function base64UrlDecode(string $data): string
    {
        $data = str_replace(['-', '_'], ['+', '/'], $data);
        $mod4 = strlen($data) % 4;
        if ($mod4) $data .= str_repeat('=', 4 - $mod4);
        return base64_decode($data);
    }
}