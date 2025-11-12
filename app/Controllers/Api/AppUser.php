<?php
namespace App\Controllers\Api;
require_once ROOTPATH . 'vendor/autoload.php';
use App\Controllers\BaseController;
use App\Models\Api\AppUserModel;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
class AppUser extends BaseController
{
    protected $appUserModel;

    public function __construct()
    {
        $this->appUserModel = new AppUserModel();
    }

    public function register()
    {
        $data = $this->request->getPost();

        if (empty($data['name']) || empty($data['email']) || empty($data['phone']) || empty($data['password'])) {
            return $this->response->setJSON([
                'status' => 400,
                'success' => false,
                'message' => 'Name, email, phone, and password are required.'
            ]);
        }

        // Check duplicate email or phone
        $existingUser = $this->appUserModel
            ->groupStart()
            ->where('email', $data['email'])
            ->orWhere('phone', $data['phone'])
            ->groupEnd()
            ->first();

        if ($existingUser) {
            return $this->response->setJSON([
                'status' => 409,
                'success' => false,
                'message' => 'Email or phone number already registered.'
            ]);
        }

        // Hash password
        $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);

        // Generate OTP
        $otp = rand(100000, 999999);

        // Handle profile image upload
        $profileImage = null;
        $file = $this->request->getFile('profile_image');
        if ($file && $file->isValid() && !$file->hasMoved()) {
            $newName = 'user_' . time() . '.' . $file->getExtension();
            $uploadPath = FCPATH . 'public/uploads/profile_images/';
            if (!is_dir($uploadPath)) {
                mkdir($uploadPath, 0777, true);
            }
            $file->move($uploadPath, $newName);
            $profileImage = $newName;
        }

        // Calculate profile score
        $profile_score = 0;

        if (!empty($data['phone']))
            $profile_score += 25; // phone verified
        if (!empty($data['insta_id']))
            $profile_score += 20; // instagram added
        if (!empty($data['email']))
            $profile_score += 15; // email verified
        if (!empty($data['profile_image']))
            $profile_score += 10; // profile image uploaded
        if (!empty($data['interest_id']))
            $profile_score += 10; // interest selected
        if (!empty($data['linkedin_id']))
            $profile_score += 5; // linkedin added
        if (!empty($data['location']) && strtolower($data['location']) === 'kochi')
            $profile_score += 10; // Kochi verified
        if (!empty($data['dob']))
            $profile_score += 5; // DOB added

        // Prepare data for insertion
        $insertData = [
            'name' => $data['name'],
            'gender' => $data['gender'] ?? null,
            'dob' => $data['dob'] ?? null,
            'email' => $data['email'],
            'phone' => $data['phone'],
            'insta_id' => $data['insta_id'] ?? null,
            'linkedin_id' => $data['linkedin_id'] ?? null,
            'location' => $data['location'] ?? null,
            'interest_id' => $data['interest_id'] ?? null,
            'password' => $hashedPassword,
            'otp' => $otp,
            'profile_status' => 1,
            'profile_score' => $profile_score,
            'status' => 1,
            'created_at' => date('Y-m-d H:i:s'),
            'profile_image' => $profileImage
        ];

        // Save to DB
        $this->appUserModel->insert($insertData);

        $userId = $this->appUserModel->getInsertID();

        // Success Response
        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'message' => 'Registration successful. Please verify OTP to activate your profile.',
            'data' => [
                'user_id' => $userId,
                'otp' => $otp,// Include only for testing; hide in production
                'profile_score' => $profile_score
            ]
        ]);
    }
    public function UserLogin()
    {
        $data = $this->request->getJSON(true);
        $phone = $data['phone'] ?? $this->request->getPost('phone');

        if (empty($phone)) {
            return $this->response->setJSON([
                'status' => 400,
                'success' => false,
                'message' => 'Phone number is required.'
            ]);
        }

        // Check if user exists
        $user = $this->appUserModel->where('phone', $phone)->first();

        // Generate OTP
        $otp = rand(100000, 999999);

        if ($user) {
            // Update existing user OTP
            $this->appUserModel->update($user['user_id'], [
                'otp' => $otp,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        } else {
            // Register new user with phone only
            $this->appUserModel->insert([
                'phone' => $phone,
                'otp' => $otp,
                'profile_status' => 1,
                'status' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            $user['user_id'] = $this->appUserModel->getInsertID();
        }

        // Send OTP via WhatsApp
        $whatsappResponse = $this->sendWhatsAppOtp($phone, $otp);

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'message' => 'OTP sent successfully via WhatsApp. Please verify OTP to continue.',
            'data' => [
                'user_id' => $user['user_id'],
                'otp' => $otp, // for testing only
                'whatsapp_response' => $whatsappResponse
            ]
        ]);
    }

    public function verifyOtp()
    {
        $data = $this->request->getJSON(true);
        $phone = $data['phone'] ?? $this->request->getPost('phone');
        $otp = $data['otp'] ?? $this->request->getPost('otp');

        if (empty($phone) || empty($otp)) {
            return $this->response->setJSON([
                'status' => 400,
                'success' => false,
                'message' => 'Phone and OTP are required.'
            ]);
        }

        $user = $this->appUserModel->where('phone', $phone)->first();

        if (!$user) {
            return $this->response->setJSON([
                'status' => 404,
                'success' => false,
                'message' => 'User not found.'
            ]);
        }

        if ($user['otp'] != $otp) {
            return $this->response->setJSON([
                'status' => 401,
                'success' => false,
                'message' => 'Invalid OTP.'
            ]);
        }

        // Generate login token
        $token = bin2hex(random_bytes(16));

        // Clear OTP after verification
        $this->appUserModel->update($user['user_id'], [
            'otp' => null,
            'token' => $token,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        unset($user['password']);

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'message' => 'Login successful.',
            'token' => $token,
            'data' => $user
        ]);
    }
    private function sendWhatsAppOtp($phone, $otp)
    {
        $url = "https://api.turbodev.ai/api/organizations/690dff1d279dea55dc371e0b/integrations/genericWebhook/690e02d83dcbb55508455c59/webhook/execute";
        if (strpos($phone, '+91') !== 0) {
            $phone = '+91' . ltrim($phone, '0');
        }

        $payload = [
            "phone" => $phone,
            "name" => "Test",
            "otp" => (string) $otp
        ];


        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Content-Type: application/json"]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));

        // TEMPORARY FIX — disable SSL certificate verification
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);

        $response = curl_exec($ch);

        if (curl_errno($ch)) {
            $error = curl_error($ch);
            curl_close($ch);
            return ["success" => false, "error" => $error];
        }

        curl_close($ch);
        return json_decode($response, true);
    }


    public function getUserById()
    {
        $user_id = $this->request->getJSON(true);

        if (empty($user_id)) {
            return $this->response->setJSON([
                'status' => 400,
                'success' => false,
                'message' => 'user_id is required.'
            ]);
        }

        $user = $this->appUserModel->find($user_id);

        if (!$user) {
            return $this->response->setJSON([
                'status' => 404,
                'success' => false,
                'message' => 'User not found.'
            ]);
        }

        // Optionally prefix image URL
        if (!empty($user['profile_image'])) {
            $user['profile_image'] = base_url('public/uploads/profile_images/' . $user['profile_image']);
        }

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'data' => $user
        ]);
    }
    // UPDATE USER DETAILS
    public function updateUser()
    {
        $data = $this->request->getPost();
        $user_id = $data['user_id'] ?? null;

        if (empty($user_id)) {
            return $this->response->setJSON([
                'status' => 400,
                'success' => false,
                'message' => 'user_id is required.'
            ]);
        }

        $user = $this->appUserModel->find($user_id);
        if (!$user) {
            return $this->response->setJSON([
                'status' => 404,
                'success' => false,
                'message' => 'User not found.'
            ]);
        }

        // Handle profile image update
        $profileImage = $user['profile_image'];
        $file = $this->request->getFile('profile_image');

        if ($file && $file->isValid() && !$file->hasMoved()) {
            $newName = 'user_' . time() . '.' . $file->getExtension();
            $uploadPath = FCPATH . 'public/uploads/profile_images/';
            if (!is_dir($uploadPath)) {
                mkdir($uploadPath, 0777, true);
            }
            $file->move($uploadPath, $newName);
            $profileImage = $newName;

            // delete old image if exists
            $oldImagePath = $uploadPath . $user['profile_image'];
            if (is_file($oldImagePath)) {
                unlink($oldImagePath);
            }
        }

        // If password is sent, hash it
        if (!empty($data['password'])) {
            $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
        } else {
            unset($data['password']);
        }

        $updateData = [
            'name' => $data['name'] ?? $user['name'],
            'gender' => $data['gender'] ?? $user['gender'],
            'dob' => $data['dob'] ?? $user['dob'],
            'email' => $data['email'] ?? $user['email'],
            'phone' => $data['phone'] ?? $user['phone'],
            'insta_id' => $data['insta_id'] ?? $user['insta_id'],
            'linkedin_id' => $data['linkedin_id'] ?? $user['linkedin_id'],
            'location' => $data['location'] ?? $user['location'],
            'interest_id' => $data['interest_id'] ?? $user['interest_id'],
            'profile_image' => $profileImage,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->appUserModel->update($user_id, $updateData);

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'message' => 'User updated successfully.'
        ]);
    }
    // DELETE USER (soft delete)
    public function deleteUser()
    {
        $user_id = $this->request->getJSON(true);

        if (empty($user_id)) {
            return $this->response->setJSON([
                'status' => 400,
                'success' => false,
                'message' => 'user_id is required.'
            ]);
        }

        $user = $this->appUserModel->find($user_id);
        if (!$user) {
            return $this->response->setJSON([
                'status' => 404,
                'success' => false,
                'message' => 'User not found.'
            ]);
        }

        // Soft delete (mark as deleted)
        $this->appUserModel->update($user_id, [
            'status' => 4,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'message' => 'User deleted successfully.'
        ]);
    }
    // listing users, deleted users are not bieng listed
    public function listUsers($search = '')
    {
        $page = (int) $this->request->getGet('page') ?: 1;
        $limit = (int) $this->request->getGet('limit') ?: 10;

        $offset = ($page - 1) * $limit;

        // Base query
        $builder = $this->appUserModel->where('status !=', 4);

        // Apply search filter if provided
        if (!empty($search)) {
            $builder->groupStart()
                ->like('name', $search)
                ->orLike('email', $search)
                ->orLike('location', $search)
                ->groupEnd();
        }

        // Count total results
        $total = $builder->countAllResults(false);

        // Fetch data
        $users = $builder
            ->orderBy('user_id', 'DESC')
            ->findAll($limit, $offset);

        // Add base URL to images
        foreach ($users as &$user) {
            if (!empty($user['profile_image'])) {
                $user['profile_image'] = base_url('public/uploads/profile_images/' . $user['profile_image']);
            }
        }

        $totalPages = ceil($total / $limit);

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'data' => [
                'current_page' => $page,
                'per_page' => $limit,
                'total_records' => $total,
                'total_pages' => $totalPages,
                'users' => $users
            ]
        ]);
    }

    //profile status 
    public function updateProfileStatus()
    {
        $userId = $this->request->getVar('user_id');
        $status = $this->request->getVar('profile_status');

        // Validate input
        if (empty($userId) || empty($status)) {
            return $this->response->setJSON([
                'status' => 400,
                'success' => false,
                'message' => 'User ID and profile status are required'
            ]);
        }

        // Ensure valid status values (1=pending, 2=verified, 3=rejected)
        if (!in_array($status, [1, 2, 3])) {
            return $this->response->setJSON([
                'status' => 400,
                'success' => false,
                'message' => 'Invalid profile status value'
            ]);
        }

        // Check if user exists
        $user = $this->appUserModel->find($userId);
        if (!$user) {
            return $this->response->setJSON([
                'status' => 404,
                'success' => false,
                'message' => 'User not found'
            ]);
        }

        // Update profile status
        $update = $this->appUserModel->update($userId, ['profile_status' => $status]);

        if ($update) {
            return $this->response->setJSON([
                'status' => 200,
                'success' => true,
                'message' => 'Profile status updated successfully'
            ]);
        }

        return $this->response->setJSON([
            'status' => 500,
            'success' => false,
            'message' => 'Failed to update profile status'
        ]);
    }
    //Account status Updates 
    public function updateAccountStatus()
    {
        $userId = $this->request->getVar('user_id');
        $status = $this->request->getVar('status');

        // Validate inputs
        if (empty($userId) || empty($status)) {
            return $this->response->setJSON([
                'status' => 400,
                'success' => false,
                'message' => 'User ID and account status are required'
            ]);
        }

        // Ensure valid status values (1=active, 2=suspended, 3=blocked, 4=deleted)
        if (!in_array($status, [1, 2, 3, 4])) {
            return $this->response->setJSON([
                'status' => 400,
                'success' => false,
                'message' => 'Invalid account status value'
            ]);
        }

        // Check if user exists
        $user = $this->appUserModel->find($userId);
        if (!$user) {
            return $this->response->setJSON([
                'status' => 404,
                'success' => false,
                'message' => 'User not found'
            ]);
        }

        // Update account status
        $update = $this->appUserModel->update($userId, ['status' => $status]);

        if ($update) {
            return $this->response->setJSON([
                'status' => 200,
                'success' => true,
                'message' => 'Account status updated successfully'
            ]);
        }

        return $this->response->setJSON([
            'status' => 500,
            'success' => false,
            'message' => 'Failed to update account status'
        ]);
    }



}
