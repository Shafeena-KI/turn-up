<?php
namespace App\Controllers\Api;

require_once ROOTPATH . 'vendor/autoload.php';

use App\Controllers\BaseController;
use App\Models\AdminModel;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Login extends BaseController
{
    protected $adminModel;

    public function __construct()
    {
        $this->adminModel = new AdminModel();
    }

    public function adminLogin()
    {
        try {
            $data = $this->request->getJSON(true);

            $email = $data['email'] ?? $this->request->getPost('email');
            $password = $data['password'] ?? $this->request->getPost('password');

            if (empty($email) || empty($password)) {
                return $this->response->setJSON([
                    'status' => 400,
                    'success' => false,
                    'message' => 'Email and Password are required.'
                ]);
            }

            $result = $this->adminModel->verifyAdmin($email, $password);
            if (isset($result['error']) && $result['error'] === true) {
                return $this->response->setJSON([
                    'status' => 401,
                    'success' => false,
                    'message' => $result['message']
                ]);
            }

            $admin = $result['data'];

            // Use .env secret key
            $key = getenv('JWT_SECRET') ?: 'default_fallback_key';

            $payload = [
                'iss' => 'turn-up',            
                'iat' => time(),              
                'exp' => time() + 3600,         // Expiration time (1 hour)
                'data' => [
                    'admin_id' => $admin['admin_id'],
                    'email' => $admin['email']
                ]
            ];

            $token = JWT::encode($payload, $key, 'HS256');

            // Make sure token column is allowed and admin_id exists
            if (!empty($admin['admin_id'])) {
                $updated = $this->adminModel->update($admin['admin_id'], ['token' => $token]);
                if (!$updated) {
                    log_message('error', 'Failed to update token: ' . json_encode($this->adminModel->errors()));
                }
            }

            return $this->response->setJSON([
                'status' => 200,
                'success' => true,
                'data' => $admin,
                'message' => 'Login successful',
                'token' => $token,
              
            ]);
        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => 500,
                'success' => false,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
}