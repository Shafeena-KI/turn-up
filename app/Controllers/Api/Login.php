<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\AdminModel;
use App\Libraries\Jwt;

class Login extends BaseController
{
    protected $adminModel;

    public function __construct()
    {
        $this->adminModel = new AdminModel();
    }

    public function adminLogin()
    {
        $data = $this->request->getJSON(true);

        // 🔹 Step 1: Validate input
        if (empty($data['email']) || empty($data['password'])) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Email and Password are required.',
                'data'    => []
            ]);
        }

        // 🔹 Step 2: Check if admin exists
        $admin = $this->adminModel
            ->where('email', $data['email'])
            ->where('status !=', 3) // 3 = deleted
            ->first();

        if (!$admin) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Invalid email or password.',
                'data'    => []
            ]);
        }

        // 🔹 Step 3: Handle account status
        if ($admin['status'] == 2) { // 2 = suspended
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Your account has been suspended by the admin.',
                'data'    => []
            ]);
        }

        if ($admin['status'] == 3) { // 3 = deleted
            return $this->response->setJSON([
                'success' => false,
                'message' => 'This account has been deleted.',
                'data'    => []
            ]);
        }

        // 🔹 Step 4: Verify password
        $inputPassword = $data['password'];
        $dbPassword    = $admin['password'];
        $isValid = false;

        if (password_verify($inputPassword, $dbPassword)) {
            $isValid = true;
        } elseif (md5($inputPassword) === $dbPassword) { 
            $isValid = true;
        }

        if (!$isValid) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Invalid email or password.',
                'data'    => []
            ]);
        }

        // 🔹 Step 5: Generate JWT token
        $jwt = new Jwt();
        $now = date('Y-m-d H:i:s');
        $token = $jwt->encode([
            'admin_id' => $admin['admin_id'],
            'email'    => $admin['email'],
            'role_id'  => $admin['role_id']
        ]);

        // 🔹 Step 6: Update token & last login
        $this->adminModel->update($admin['admin_id'], [
            'token'       => $token,
            'updated_at'  => $now
        ]);

        // 🔹 Step 7: Success response
        return $this->response->setJSON([
            'success' => true,
            'message' => 'Login successful.',
            'data' => [
                'admin_id'  => $admin['admin_id'],
                'name'      => $admin['name'] ?? '',
                'email'     => $admin['email'],
                'phone'     => $admin['phone'] ?? '',
                'role_id'   => $admin['role_id'] ?? null,
                'status'    => $admin['status'],
                'token'     => $token,
                'created_at'=> $admin['created_at'] ?? '',
                'updated_at'=> $now
            ]
        ]);
    }
}
