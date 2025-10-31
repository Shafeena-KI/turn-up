<?php

namespace App\Libraries;

use App\Models\Api\LoginModel;
use App\Libraries\Jwt;

class AuthService
{
    protected $loginModel;
    protected $jwt;

    public function __construct()
    {
        $this->loginModel = new LoginModel();
        $this->jwt = new Jwt();
    }

    public function getAuthenticatedUser($authHeader)
    {
        if (empty($authHeader)) {
            return null;
        }
        $token = str_replace('Bearer ', '', $authHeader);
        $decoded = $this->jwt->decode($token);
        if (!$decoded || empty($decoded['user_id'])) {
            return null;
        }

        $user = $this->loginModel
            ->where('user_id', $decoded['user_id'])
            ->where('jwt_token', $token)
            ->where('status !=', 9)
            ->first();

        return $user ?: null;
    }
}
