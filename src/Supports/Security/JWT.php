<?php

declare(strict_types=1);

namespace Slenix\Supports\Security;

class Jwt {
    
    private string $secret;

    public function __construct(?string $secret = null) {
        $this->secret = $secret ?? getenv('JWT_SECRET_TOKEN') ?: 'sua_chave_secreta_aqui';
    }

    /**
     * Gera um token JWT.
     *
     * @param array $payload Dados a serem incluídos no token (ex.: ['user_id' => 1])
     * @param int $expiresIn Tempo de expiração em segundos (padrão: 1 hora)
     * @return string Token JWT
     */
    public function generate(array $payload, int $expiresIn = 3600): string
    {
        $header = [
            'alg' => 'HS256',
            'typ' => 'JWT'
        ];

        $payload['iat'] = time(); // Issued at
        $payload['exp'] = time() + $expiresIn; // Expiration time

        // Codifica header e payload em base64
        $headerEncoded = $this->base64UrlEncode(json_encode($header));
        $payloadEncoded = $this->base64UrlEncode(json_encode($payload));

        // Cria a assinatura
        $signature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $this->secret, true);
        $signatureEncoded = $this->base64UrlEncode($signature);

        // Retorna o token completo
        return "$headerEncoded.$payloadEncoded.$signatureEncoded";
    }

    /**
     * Valida um token JWT.
     *
     * @param string $token Token JWT
     * @return array|null Payload decodificado se válido, ou null se inválido
     */
    public function validate(string $token): ?array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null; // Token inválido
        }

        list($headerEncoded, $payloadEncoded, $signatureEncoded) = $parts;

        // Verifica a assinatura
        $signature = $this->base64UrlDecode($signatureEncoded);
        $expectedSignature = hash_hmac('sha256', "$headerEncoded.$payloadEncoded", $this->secret, true);

        if (!hash_equals($signature, $expectedSignature)) {
            return null; // Assinatura inválida
        }

        // Decodifica o payload
        $payload = json_decode($this->base64UrlDecode($payloadEncoded), true);
        if (!$payload || !isset($payload['exp'])) {
            return null; // Payload inválido
        }

        // Verifica se o token expirou
        if ($payload['exp'] < time()) {
            return null; // Token expirado
        }

        return $payload;
    }

    /**
     * Codifica uma string em Base64 URL-safe.
     *
     * @param string $data
     * @return string
     */
    private function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    /**
     * Decodifica uma string Base64 URL-safe.
     *
     * @param string $data
     * @return string
     */
    private function base64UrlDecode(string $data): string
    {
        $data = str_replace(['-', '_'], ['+', '/'], $data);
        $mod4 = strlen($data) % 4;
        if ($mod4) {
            $data .= str_repeat('=', 4 - $mod4);
        }
        return base64_decode($data);
    }
}