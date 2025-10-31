<?php

namespace App\Libraries;

class Jwt
{
    private $key;

    public function __construct()
    {
        // Use .env secret or fallback
        $this->key = getenv('JWT_SECRET') ?: 'your_fallback_secret_key';
    }

    public function encode($data, $exp = 3600)
    {
        $issuedAt = time();
        $expirationTime = $issuedAt + $exp;

        $payload = [
            'iat'  => $issuedAt,
            'exp'  => $expirationTime,
            'data' => $data
        ];

        $header = ['alg' => 'HS256', 'typ' => 'JWT'];

        // Encode header & payload
        $base64UrlHeader = $this->base64UrlEncode(json_encode($header));
        $base64UrlPayload = $this->base64UrlEncode(json_encode($payload));

        // Create signature
        $signature = hash_hmac('sha256', "$base64UrlHeader.$base64UrlPayload", $this->key, true);
        $base64UrlSignature = $this->base64UrlEncode($signature);

        // Final token
        return "$base64UrlHeader.$base64UrlPayload.$base64UrlSignature";
    }

    public function decode($token)
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return false;
        }

        [$header, $payload, $signature] = $parts;

        $decodedHeader = json_decode($this->base64UrlDecode($header), true);
        $decodedPayload = json_decode($this->base64UrlDecode($payload), true);
        $decodedSignature = $this->base64UrlDecode($signature);

        // Verify signature
        $expectedSignature = hash_hmac('sha256', "$header.$payload", $this->key, true);
        if (!hash_equals($expectedSignature, $decodedSignature)) {
            return false;
        }

        // Check expiration
        if (isset($decodedPayload['exp']) && time() > $decodedPayload['exp']) {
            return false;
        }

        return $decodedPayload['data'] ?? false;
    }

    private function base64UrlEncode($data)
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    private function base64UrlDecode($data)
    {
        return base64_decode(strtr($data, '-_', '+/'));
    }
}
