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
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
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
    public function adminLogout()
    {
        try {
            // Get token from Authorization header
            $authHeader = $this->request->getHeaderLine('Authorization');

            if (!$authHeader) {
                return $this->response->setJSON([
                    'status' => 400,
                    'success' => false,
                    'message' => 'Authorization token is required.'
                ]);
            }

            // Format: Bearer tokenvalue
            $token = str_replace('Bearer ', '', $authHeader);

            if (empty($token)) {
                return $this->response->setJSON([
                    'status' => 400,
                    'success' => false,
                    'message' => 'Invalid token format.'
                ]);
            }

            // Decode token
            $key = getenv('JWT_SECRET') ?: 'default_fallback_key';
            $decoded = JWT::decode($token, new \Firebase\JWT\Key($key, 'HS256'));

            $admin_id = $decoded->data->admin_id ?? null;

            if (!$admin_id) {
                return $this->response->setJSON([
                    'status' => 401,
                    'success' => false,
                    'message' => 'Invalid token.'
                ]);
            }

            // Clear token from DB
            $this->adminModel->update($admin_id, ['token' => null]);

            return $this->response->setJSON([
                'status' => 200,
                'success' => true,
                'message' => 'Logout successful. Token cleared.'
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

    // ============================================================
    //  Validate Token 
    // ============================================================
    private function validateToken()
        {
            $authHeader = $this->request->getHeaderLine('Authorization');
            if (!$authHeader) {
                return ['status' => false, 'message' => 'Authorization token required'];
            }

            $token = str_replace('Bearer ', '', $authHeader);
            $key = getenv('JWT_SECRET') ?: 'default_fallback_key';

            try {
                $decoded = JWT::decode($token, new Key($key, 'HS256'));
                return ['status' => true, 'admin_id' => $decoded->data->admin_id];
            } catch (\Throwable $e) {
                return ['status' => false, 'message' => 'Invalid or expired token'];
            }
        }

    // ============================================================
    //  CREATE ADMIN
    // ============================================================
    public function createAdmin()
    {
        $auth = $this->validateToken();
        if (!$auth['status']) return $this->response->setJSON($auth);

        $data = $this->request->getJSON(true);

        if (empty($data['name']) || empty($data['email']) || empty($data['password'])) {
            return $this->response->setJSON([
                'status' => 400,
                'success' => false,
                'message' => 'Name, Email, Password are required'
            ]);
        }

        $insert = [
            'name'      => $data['name'],
            'email'     => $data['email'],
            'phone'     => $data['phone'] ?? '',
            'role_id'   => $data['role_id'] ?? 0,
            'password'  => password_hash($data['password'], PASSWORD_DEFAULT),
            'status'    => 1
        ];

        $this->adminModel->insert($insert);

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'message' => 'Admin created successfully'
        ]);
    }

    // ============================================================
    //  LIST ALL ADMINS
    // ============================================================
    // public function listAdmins()
    // {
    //     $auth = $this->validateToken();
    //     if (!$auth['status']) return $this->response->setJSON($auth);

    //     $admins = $this->adminModel->orderBy('admin_id', 'DESC')->findAll();

    //     return $this->response->setJSON([
    //         'status' => 200,
    //         'success' => true,
    //         'data' => $admins
    //     ]);
    // }

    public function listAdmins()
{
    // Read token from headers
    $token = $this->request->getHeaderLine('Authorization');

    // If token is provided, validate it
    if (!empty($token)) {
        $auth = $this->validateToken();
        if (!$auth['status']) {
            return $this->response->setStatusCode(401)->setJSON($auth);
        }
    }

    // No token or token OK → return data
    $admins = $this->adminModel->orderBy('admin_id', 'DESC')->findAll();

    return $this->response->setJSON([
        'status' => 200,
        'success' => true,
        'data' => $admins
    ]);
}


    // ============================================================
    //  GET SINGLE ADMIN
    // ============================================================
    public function getAdmin($id)
    {
        $auth = $this->validateToken();
        if (!$auth['status']) return $this->response->setJSON($auth);

        $admin = $this->adminModel->find($id);

        if (!$admin) {
            return $this->response->setJSON([
                'status' => 404,
                'success' => false,
                'message' => 'Admin not found'
            ]);
        }

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'data' => $admin
        ]);
    }

    // ============================================================
    //  UPDATE ADMIN
    // ============================================================
public function updateAdmin()
{
    // Validate token
    $auth = $this->validateToken();
    if (!$auth['status']) return $this->response->setJSON($auth);

    // Detect JSON
    $contentType = $this->request->getHeaderLine('Content-Type');
    $isJson = strpos($contentType, 'application/json') !== false;
    $json = $isJson ? $this->request->getJSON(true) : [];

    // Get admin ID from JSON or POST
    $id = $isJson ? ($json['id'] ?? null) : $this->request->getPost('id');
    if (empty($id)) {
        return $this->response->setJSON([
            'status' => 400,
            'success' => false,
            'message' => 'Admin ID is required'
        ]);
    }

    // Find admin
    $admin = $this->adminModel->find($id);
    if (!$admin) {
        return $this->response->setJSON([
            'status' => 404,
            'success' => false,
            'message' => 'Admin not found'
        ]);
    }

    // Fields to update
    $fields = ['name', 'email', 'phone', 'role_id', 'status', 'password'];
    $update = [];

    foreach ($fields as $field) {
        $value = $isJson ? ($json[$field] ?? null) : $this->request->getPost($field);
        if ($value !== null && $value !== '') {
            if ($field == 'password') {
                $value = password_hash($value, PASSWORD_DEFAULT);
            }
            $update[$field] = $value;
        }
    }

    $this->adminModel->update($id, $update);

    // Fetch updated admin
    $updatedAdmin = $this->adminModel->find($id);

    return $this->response->setJSON([
        'status' => 200,
        'success' => true,
        'message' => 'Admin updated successfully',
        'data' => $updatedAdmin
    ]);
}



    // ============================================================
    //  DELETE ADMIN
    // ============================================================
    public function deleteAdmin()
{
    // Validate token
    $auth = $this->validateToken();
    if (!$auth['status']) return $this->response->setJSON($auth);

    // Detect JSON
    $contentType = $this->request->getHeaderLine('Content-Type');
    $isJson = strpos($contentType, 'application/json') !== false;
    $json = $isJson ? $this->request->getJSON(true) : [];

    // Get admin ID from JSON or POST
    $id = $isJson ? ($json['id'] ?? null) : $this->request->getPost('id');
    if (empty($id)) {
        return $this->response->setJSON([
            'status' => 400,
            'success' => false,
            'message' => 'Admin ID is required'
        ]);
    }

    // Find admin
    $admin = $this->adminModel->find($id);
    if (!$admin) {
        return $this->response->setJSON([
            'status' => 404,
            'success' => false,
            'message' => 'Admin not found'
        ]);
    }

    // Delete admin
    $this->adminModel->delete($id);

    return $this->response->setJSON([
        'status' => 200,
        'success' => true,
        'message' => 'Admin deleted successfully'
    ]);
}


}