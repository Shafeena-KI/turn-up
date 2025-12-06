<?php
namespace App\Filters;

use App\Models\Api\AppUserModel;
use App\Models\Api\UserVerificationModel;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class PaymentFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        return true;
        $uri = $request->getUri()->getPath();
        
        // Only process create-order requests
        if (strpos($uri, 'create-order') === false) {
            return;
        }

        // Extract user_id from JWT token
        $userId = $this->extractUserIdFromJWT($request);
        if (!$userId) {
            return service('response')->setJSON([
                'status' => 401,
                'success' => false,
                'message' => 'Authentication required. Please login.'
            ])->setStatusCode(401);
        }

        // Check if the user is verified
        $verifiyUser = $this->isUserVerified($userId);
        if (!$verifiyUser) {
            return service('response')->setJSON([
                'status' => 401,
                'success' => false,
                'message' => 'Please verifiy your account.'
            ])->setStatusCode(401);
        }
        
        // Add user_id to request for use in controller
        $request->setGlobal('post', ['user_id' => $userId]);
    }


    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Add license headers
        $response->setHeader('X-License-Protected', 'true');
    }

    // Extracting UserId from JWT
    private function extractUserIdFromJWT($request)
    {
        // Get token from Authorization header
        $authHeader = $request->getHeaderLine('Authorization');
        if (!$authHeader || !preg_match('/Bearer\s+(\S+)/', $authHeader, $matches)) {
            return null;
        }

        $token = $matches[1];
        $key = getenv('JWT_SECRET') ?: 'default_fallback_key';

        try {
            $decoded = JWT::decode($token, new Key($key, 'HS256'));
            return $decoded->data->user_id ?? null;
        } catch (\Exception $e) {
            return null;
        }
    }

    // Check if the user is verified or not
    private function isUserVerified($userId)
    {
        $verificationModel = new UserVerificationModel();
        $user = $verificationModel->isUserVerified($userId);

        return $user;
    }
}