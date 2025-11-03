<?php

namespace App\Libraries;

use Firebase\JWT\JWT as FirebaseJWT;
use Firebase\JWT\Key;


class JWT
{
    private $key;

    public function __construct()
    {
        $this->key = getenv('JWT_SECRET') ?: 'your_secret_key';
    }

    public function encode($data, $exp = 3600)
    {
        $issuedAt = time();
        $expirationTime = $issuedAt + $exp;

        $payload = [
            'iat' => $issuedAt,
            'exp' => $expirationTime,
            'data' => $data
        ];

        return FirebaseJWT::encode($payload, $this->key, 'HS256');
    }

    public function decode($token)
    {
        try {
            return FirebaseJWT::decode($token, new Key($this->key, 'HS256'))->data;
        } catch (\Exception $e) {
            return false;
        }
    }
}