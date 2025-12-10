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

    // public function adminLogin()
    // {
    //     try {
    //         $data = $this->request->getJSON(true);

    //         $email = $data['email'] ?? $this->request->getPost('email');
    //         $password = $data['password'] ?? $this->request->getPost('password');

    //         if (empty($email) || empty($password)) {
    //             return $this->response->setJSON([
    //                 'status' => 400,
    //                 'success' => false,
    //                 'message' => 'Email and Password are required.'
    //             ]);
    //         }

    //         // Verify credentials
    //         $result = $this->adminModel->verifyAdmin($email, $password);

    //         if (isset($result['error']) && $result['error'] === true) {
    //             return $this->response->setJSON([
    //                 'status' => 401,
    //                 'success' => false,
    //                 'message' => $result['message']
    //             ]);
    //         }

    //         $admin = $result['data'];

    //         // STATUS CHECK (MOST IMPORTANT PART)

    //         if ($admin['status'] == 2) {
    //             return $this->response->setJSON([
    //                 'status' => 403,
    //                 'success' => false,
    //                 'message' => 'Your account is suspended. Please contact Super Admin.'
    //             ]);
    //         }

    //         if ($admin['status'] == 3) {
    //             return $this->response->setJSON([
    //                 'status' => 410,
    //                 'success' => false,
    //                 'message' => 'This admin account has been deleted.'
    //             ]);
    //         }

    //         // ONLY ACTIVE ADMINS CAN LOGIN
    //         if ($admin['status'] != 1) {
    //             return $this->response->setJSON([
    //                 'status' => 403,
    //                 'success' => false,
    //                 'message' => 'Admin account is not active.'
    //             ]);
    //         }

    //         // Generate JWT token
    //         $key = getenv('JWT_SECRET') ?: 'default_fallback_key';

    //         $payload = [
    //             'iss' => 'turn-up',
    //             'iat' => time(),
    //             'exp' => time() + 3600, // 1 hour
    //             'data' => [
    //                 'admin_id' => $admin['admin_id'],
    //                 'email' => $admin['email']
    //             ]
    //         ];

    //         $token = JWT::encode($payload, $key, 'HS256');

    //         // Save token
    //         if (!empty($admin['admin_id'])) {
    //             $this->adminModel->update($admin['admin_id'], [
    //                 'token' => $token
    //             ]);
    //         }

    //         return $this->response->setJSON([
    //             'status' => 200,
    //             'success' => true,
    //             'message' => 'Login successful',
    //             'data' => [
    //                 'admin_id' => $admin['admin_id'],
    //                 'email' => $admin['email'],
    //                 'role_id' => $admin['role_id'] ?? null
    //             ],
    //             'token' => $token
    //         ]);

    //     } catch (\Throwable $e) {

    //         log_message('error', $e->getMessage());

    //         return $this->response->setJSON([
    //             'status' => 500,
    //             'success' => false,
    //             'message' => 'Internal Server Error'
    //         ]);
    //     }
    // }



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

        // Verify credentials
        $result = $this->adminModel->verifyAdmin($email, $password);

        if (isset($result['error']) && $result['error'] === true) {
            return $this->response->setJSON([
                'status' => 401,
                'success' => false,
                'message' => $result['message']
            ]);
        }

        $admin = $result['data'];

        // STATUS CHECK
        if ($admin['status'] == 2) {
            return $this->response->setJSON([
                'status' => 403,
                'success' => false,
                'message' => 'Your account is suspended. Please contact Super Admin.'
            ]);
        }

        if ($admin['status'] == 3) {
            return $this->response->setJSON([
                'status' => 410,
                'success' => false,
                'message' => 'This admin account has been deleted.'
            ]);
        }

        if ($admin['status'] != 1) {
            return $this->response->setJSON([
                'status' => 403,
                'success' => false,
                'message' => 'Admin account is not active.'
            ]);
        }

        // Get role details
        $roleModel = new \App\Models\Api\RoleModel();
        $roleData = null;
        if (!empty($admin['role_id'])) {
            $roleData = $roleModel->find($admin['role_id']);
            if ($roleData) {
                $roleData['role_permissions'] = json_decode($roleData['role_permissions'], true) ?? [];
            }
        }

        // Generate JWT token
        $key = getenv('JWT_SECRET') ?: 'default_fallback_key';

        $payload = [
            'iss' => 'turn-up',
            'iat' => time(),
            'exp' => time() + 3600, // 1 hour
            'data' => [
                'admin_id' => $admin['admin_id'],
                'email' => $admin['email']
            ]
        ];

        $token = JWT::encode($payload, $key, 'HS256');

        // Save token
        if (!empty($admin['admin_id'])) {
            $this->adminModel->update($admin['admin_id'], [
                'token' => $token
            ]);
        }

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'message' => 'Login successful',
            'data' => [
                'admin_id' => $admin['admin_id'],
                'name' => $admin['name'],                // added name
                'email' => $admin['email'],
                'role_id' => $admin['role_id'] ?? null,
                'role_name' => $roleData['role_name'] ?? null,              // role name
                'role_permissions' => $roleData['role_permissions'] ?? []   // role permissions
            ],
            'token' => $token
        ]);

    } catch (\Throwable $e) {

        log_message('error', $e->getMessage());

        return $this->response->setJSON([
            'status' => 500,
            'success' => false,
            'message' => 'Internal Server Error'
        ]);
    }
}




    public function updateAdminUserStatus()
    {
        $auth = $this->validateToken();
        if (!$auth['status'])
            return $this->response->setJSON($auth);
        $data = $this->request->getJSON(true);

        $adminId = $data['admin_id'] ?? null;
        $status = $data['status'] ?? null;

        // Validation
        if (!$adminId || !$status) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Admin ID and status are required'
            ])->setStatusCode(400);
        }

        // Allowed statuses
        // 1 = Active, 2 = Suspended, 3 = Deleted
        if (!in_array((int) $status, [1, 2, 3, 4])) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Invalid status value'
            ])->setStatusCode(400);
        }

        // Check admin exists
        $admin = $this->adminModel->find($adminId);
        if (!$admin) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Admin user not found'
            ])->setStatusCode(404);
        }

        // Update status
        $this->adminModel->update($adminId, [
            'status' => $status
        ]);

        return $this->response->setJSON([
            'success' => true,
            'message' => 'Admin account status updated successfully'
        ])->setStatusCode(200);
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


    //  Validate Token 
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


    public function createAdmin()
    {
        $auth = $this->validateToken();
        if (!$auth['status'])
            return $this->response->setJSON($auth);

        $data = $this->request->getJSON(true);

        if (empty($data['name']) || empty($data['email']) || empty($data['password'])) {
            return $this->response->setJSON([
                'status' => 400,
                'success' => false,
                'message' => 'Name, Email, Password are required'
            ]);
        }

        $insert = [
            'name' => $data['name'],
            'email' => $data['email'],
            'phone' => $data['phone'] ?? '',
            'role_id' => $data['role_id'] ?? 0,
            'password' => password_hash($data['password'], PASSWORD_DEFAULT),
            'status' => 1
        ];

        $this->adminModel->insert($insert);

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'message' => 'Admin created successfully'
        ]);
    }


// public function listAdmins()
// {
//     // Read token from headers
//     $token = $this->request->getHeaderLine('Authorization');

//     // Validate token only if provided
//     if (!empty($token)) {
//         $auth = $this->validateToken();
//         if (!$auth['status']) {
//             return $this->response->setStatusCode(401)->setJSON($auth);
//         }
//     }

//     // Pagination Params
//     $page = (int) $this->request->getGet('current_page') ?: 1;
//     $limit = (int) $this->request->getGet('per_page') ?: 10;
//     $offset = ($page - 1) * $limit;

//     // Search Param
//     $search = $this->request->getGet('keyword') ?? $this->request->getGet('search');

//     // Base query with JOIN
//     $builder = $this->adminModel
//         ->select('admin_users.*, role_access.role_name')
//         ->join('role_access', 'role_access.role_id = admin_users.role_id', 'left');

//     // Apply search
//     if (!empty($search)) {
//         $builder->groupStart()
//             ->like('admin_users.name', $search)
//             ->orLike('admin_users.email', $search)
//             ->orLike('admin_users.phone', $search)
//             ->groupEnd();
//     }

//     // Count total
//     $total = $builder->countAllResults(false);

//     // Fetch paginated data
//     $admins = $builder
//         ->orderBy('admin_users.admin_id', 'DESC')
//         ->findAll($limit, $offset);

//     // Pagination metadata
//     $totalPages = ceil($total / $limit);

//     return $this->response->setJSON([
//         'status' => 200,
//         'success' => true,
//         'data' => [
//             'current_page' => $page,
//             'per_page' => $limit,
//             'keyword' => $search,
//             'total_records' => $total,
//             'total_pages' => $totalPages,
//             'admins' => $admins
//         ]
//     ]);
// }


public function listAdmins()
{
    // Read token from headers
    $token = $this->request->getHeaderLine('Authorization');

    // Validate token only if provided
    if (!empty($token)) {
        $auth = $this->validateToken();
        if (!$auth['status']) {
            return $this->response->setStatusCode(401)->setJSON($auth);
        }
    }

    // Pagination Params
    $page = (int) $this->request->getGet('current_page') ?: 1;
    $limit = (int) $this->request->getGet('per_page') ?: 10;
    $offset = ($page - 1) * $limit;

    // Search Param
    $search = $this->request->getGet('keyword') ?? $this->request->getGet('search');

    // Base query with JOIN
    $builder = $this->adminModel
        ->select('admin_users.*, role_access.role_name, role_access.role_permissions')
        ->join('role_access', 'role_access.role_id = admin_users.role_id', 'left');

    // Apply search
    if (!empty($search)) {
        $builder->groupStart()
            ->like('admin_users.name', $search)
            ->orLike('admin_users.email', $search)
            ->orLike('admin_users.phone', $search)
            ->groupEnd();
    }

    // Count total
    $total = $builder->countAllResults(false);

    // Fetch paginated data
    $admins = $builder
        ->orderBy('admin_users.admin_id', 'DESC')
        ->findAll($limit, $offset);

    // Decode role permissions for each admin
    foreach ($admins as &$admin) {
        $admin['role_permissions'] = json_decode($admin['role_permissions'], true) ?? [];
    }

    // Pagination metadata
    $totalPages = ceil($total / $limit);

    return $this->response->setJSON([
        'status' => 200,
        'success' => true,
        'data' => [
            'current_page' => $page,
            'per_page' => $limit,
            'keyword' => $search,
            'total_records' => $total,
            'total_pages' => $totalPages,
            'admins' => $admins
        ]
    ]);
}



    public function getAdmin($id)
    {
        $auth = $this->validateToken();
        if (!$auth['status'])
            return $this->response->setJSON($auth);

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






    public function updateAdmin()
    {
        
        // Validate token
        $auth = $this->validateToken();
        if (!$auth['status'])
            return $this->response->setJSON($auth);

        // Detect JSON
        $contentType = $this->request->getHeaderLine('Content-Type');
        $isJson = strpos($contentType, 'application/json') !== false;
        $json = $isJson ? $this->request->getJSON(true) : [];

        // Get admin ID from JSON or POST
        $id = $isJson ? ($json['admin_id'] ?? null) : $this->request->getPost('admin_id');
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



    public function deleteAdmin()
    {
        // Validate token
        $auth = $this->validateToken();
        if (!$auth['status'])
            return $this->response->setJSON($auth);

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