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
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
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
            $uploadPath = FCPATH . 'uploads/profile_images/';
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
        $fullImageURL = $profileImage
            ? base_url('uploads/profile_images/' . $profileImage)
            : null;
        // Success Response
        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'message' => 'Registration successful. Please verify OTP to activate your profile.',
            'data' => [
                'user_id' => $userId,
                'otp' => $otp,// Include only for testing; hide in production
                'profile_score' => $profile_score,
                'profile_image' => $fullImageURL
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

        // Check existing user
        $user = $this->appUserModel->where('phone', $phone)->first();
        $otp = rand(100000, 999999);

        if ($user) {
            // Only update OTP
            $this->appUserModel->update($user['user_id'], [
                'otp' => $otp,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
        } else {
            // New user = store phone_verified score 25
            $this->appUserModel->insert([
                'phone' => $phone,
                'otp' => $otp,
                'profile_status' => 0,
                'profile_score' => 25, // PHONE VERIFIED SCORE
                'status' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            $user['user_id'] = $this->appUserModel->getInsertID();
        }

        // WhatsApp OTP
        $whatsappResponse = $this->sendWhatsAppOtp($phone, $otp);

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'message' => 'OTP sent successfully.',
            'data' => [
                'user_id' => $user['user_id'],
                'otp' => $otp,
                'whatsapp_response' => $whatsappResponse
            ]
        ]);
    }


    public function verifyOtp()
    {
        try {
            $data = $this->request->getJSON(true) ?? $this->request->getPost();

            $phone = trim($data['phone'] ?? '');
            $otp = trim($data['otp'] ?? '');

            if (empty($phone) || empty($otp)) {
                return $this->response->setJSON([
                    'status' => 400,
                    'success' => false,
                    'message' => 'Phone and OTP are required.'
                ]);
            }

            // Fetch user by phone
            $user = $this->appUserModel->where('phone', $phone)->first();

            if (!$user) {
                return $this->response->setJSON([
                    'status' => 404,
                    'success' => false,
                    'message' => 'User not found.'
                ]);
            }

            // Verify OTP
            if ((string) $user['otp'] !== (string) $otp) {
                return $this->response->setJSON([
                    'status' => 401,
                    'success' => false,
                    'message' => 'Invalid OTP.'
                ]);
            }

            // Generate JWT Token (same format as adminLogin)
            $key = getenv('JWT_SECRET') ?: 'default_fallback_key';

            $payload = [
                'iss' => 'turn-up',           // Issuer
                'iat' => time(),              // Issued at
                'exp' => time() + 3600,       // Expires in 1 hour
                'data' => [
                    'user_id' => $user['user_id'],
                    'phone' => $user['phone']
                ]
            ];

            $token = \Firebase\JWT\JWT::encode($payload, $key, 'HS256');

            // Clear OTP and update token
            $this->appUserModel->update($user['user_id'], [
                'otp' => $otp,
                'token' => $token,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            unset($user['password']); // Remove sensitive data
            $user['token'] = $token;  // Include token in response
            // *********** SAFE FULL URL HANDLING FOR PROFILE IMAGE ***********
            if (!empty($user['profile_image'])) {

                // If already full URL, use as-is
                if (filter_var($user['profile_image'], FILTER_VALIDATE_URL)) {
                    $user['profile_image'] = $user['profile_image'];
                } else {
                    // If only filename, append base URL
                    $user['profile_image'] = base_url('uploads/profile_images/' . $user['profile_image']);
                }

            } else {
                $user['profile_image'] = "";
            }
            return $this->response->setJSON([
                'status' => 200,
                'success' => true,
                'message' => 'Login successful.',
                'token' => $token,
                'data' => $user
            ]);
        } catch (\Throwable $e) {
            log_message('error', 'OTP Verification Error: ' . $e->getMessage());

            return $this->response->setJSON([
                'status' => 500,
                'success' => false,
                'message' => 'Internal Server Error: ' . $e->getMessage()
            ]);
        }
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
        $json = $this->request->getJSON(true);
        $user_id = $json['user_id'] ?? null;

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

        // Fix profile image full URL
        if (!empty($user['profile_image'])) {

            // Add base_url only if image is NOT already full URL
            if (!preg_match('/^https?:\/\//', $user['profile_image'])) {
                $user['profile_image'] = base_url('uploads/profile_images/' . $user['profile_image']);
            }
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

        // Handle profile image upload
        $profileImage = $user['profile_image'];
        $file = $this->request->getFile('profile_image');

        if ($file && $file->isValid() && !$file->hasMoved()) {

            $newName = 'user_' . time() . '.' . $file->getExtension();

            // Correct upload path
            $uploadPath = FCPATH . 'uploads/profile_images/';

            if (!is_dir($uploadPath)) {
                mkdir($uploadPath, 0777, true);
            }

            $file->move($uploadPath, $newName);
            $profileImage = $newName;

            // Delete old image
            $oldImagePath = $uploadPath . $user['profile_image'];
            if (is_file($oldImagePath)) {
                unlink($oldImagePath);
            }
        }

        // Password hashing
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

        // Build full image URL for response
        $updateData['profile_image'] = !empty($profileImage)
            ? base_url('uploads/profile_images/' . $profileImage)
            : "";

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'message' => 'User updated successfully.',
            'data' => $updateData
        ]);


    }
    public function completeProfile()
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

        // Fetch user
        $user = $this->appUserModel->find($user_id);
        if (!$user) {
            return $this->response->setJSON([
                'status' => 404,
                'success' => false,
                'message' => 'User not found.'
            ]);
        }

        // ----------- Mandatory Fields Validation ------------
        $required_fields = ['name', 'dob', 'insta_id'];

        foreach ($required_fields as $f) {
            if (empty($data[$f])) {
                return $this->response->setJSON([
                    'status' => 400,
                    'success' => false,
                    'message' => "$f is required."
                ]);
            }
        }

        // ----------- Profile Image Upload --------------
        $profileImage = $user['profile_image'];
        $file = $this->request->getFile('profile_image');

        if ($file && $file->isValid() && !$file->hasMoved()) {
            $newName = 'user_' . time() . '.' . $file->getExtension();
            $uploadPath = FCPATH . 'uploads/profile_images/';

            if (!is_dir($uploadPath))
                mkdir($uploadPath, 0777, true);

            $file->move($uploadPath, $newName);
            $profileImage = $newName;

            if (!empty($user['profile_image']) && is_file($uploadPath . $user['profile_image'])) {
                unlink($uploadPath . $user['profile_image']);
            }
        }

        // -------- Profile Score Calculation ----------

        // If this is FIRST TIME completing profile → set to 60
        if ($user['profile_status'] != 2) {
            $newScore = 60;
        } else {
            // otherwise start from stored score
            $newScore = $user['profile_score'];
        }

        // Optional fields give bonus ONLY if newly added

        if (empty($user['email']) && !empty($data['email']))
            $newScore += 15;

        if (empty($user['profile_image']) && !empty($profileImage))
            $newScore += 10;

        if (empty($user['interest_id']) && !empty($data['interest_id']))
            $newScore += 10;

        if (empty($user['linkedin_id']) && !empty($data['linkedin_id']))
            $newScore += 5;

        if (
            (empty($user['location']) || strtolower($user['location']) != 'kochi') &&
            !empty($data['location']) && strtolower($data['location']) == 'kochi'
        )
            $newScore += 10;

        if (empty($user['dob']) && !empty($data['dob']))
            $newScore += 5;

        // max score limit
        $newScore = min(100, $newScore);

        // -------- Update DB --------------
        $updateData = [
            'name' => $data['name'],
            'gender' => $data['gender'] ?? $user['gender'],
            'dob' => $data['dob'],
            'email' => $data['email'] ?? $user['email'],
            'insta_id' => $data['insta_id'],
            'linkedin_id' => $data['linkedin_id'] ?? $user['linkedin_id'],
            'location' => $data['location'] ?? $user['location'],
            'interest_id' => $data['interest_id'] ?? $user['interest_id'],
            'profile_image' => $profileImage,
            'profile_status' => 2,
            'profile_score' => $newScore,
            'updated_at' => date('Y-m-d H:i:s')
        ];

        // -------- Update DB --------------
        $this->appUserModel->update($user_id, $updateData);

        $responseData = $updateData;

        // Convert image to full URL
        $responseData['profile_image'] = base_url('uploads/profile_images/' . $profileImage);

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'message' => 'Profile completed successfully.',
            'data' => array_merge(['user_id' => $user_id], $responseData)
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
        $page = (int) $this->request->getGet('current_page') ?: 1;
        $limit = (int) $this->request->getGet('per_page') ?: 10;

        // Accept both keyword and search
        $search = $this->request->getGet('keyword') ?? $this->request->getGet('search');

        $offset = ($page - 1) * $limit;

        // Base query
        $builder = $this->appUserModel->where('status !=', 4);

        // Apply search filter
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
                $user['profile_image'] = base_url('uploads/profile_images/' . $user['profile_image']);
            }
        }

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
                'users' => $users
            ]
        ]);
    }
    //profile status 
    public function updateProfileStatus()
    {
        $userId = $this->request->getVar('user_id');

        // Check user exists
        $user = $this->appUserModel->find($userId);
        if (!$user) {
            return $this->response->setJSON([
                'status' => 404,
                'success' => false,
                'message' => 'User not found'
            ]);
        }

        // Mandatory fields
        $name = $this->request->getVar('name');
        $dob = $this->request->getVar('dob');
        $gender = $this->request->getVar('gender');
        $insta_id = $this->request->getVar('insta_id');
        $profile_image = $this->request->getFile('profile_image');

        // Mandatory validation
        if (
            empty($name) ||
            empty($dob) ||
            empty($gender) ||
            empty($insta_id) ||
            !$profile_image || !$profile_image->isValid()
        ) {
            return $this->response->setJSON([
                'status' => 400,
                'success' => false,
                'message' => 'Please complete all mandatory fields including profile image.'
            ]);
        }

        // Upload image
        $newName = 'user_' . time() . '.' . $profile_image->getExtension();
        $profile_image->move('uploads/profile_images/', $newName);

        // Base data
        $updateData = [
            'name' => $name,
            'dob' => $dob,
            'gender' => $gender,
            'insta_id' => $insta_id,
            'profile_image' => $newName,
            'profile_status' => 1
        ];

        // Optional fields
        $optionalFields = ['email', 'linkedin_id', 'location', 'interest_id'];

        foreach ($optionalFields as $field) {
            $value = $this->request->getVar($field);
            if (!empty($value)) {
                $updateData[$field] = $value;
            }
        }

        // Update user
        $update = $this->appUserModel->update($userId, $updateData);

        if ($update) {
            $updatedUser = $this->appUserModel->find($userId);

            // Replace filename with full URL
            $updatedUser['profile_image'] = base_url('uploads/profile_images/' . $updatedUser['profile_image']);

            return $this->response->setJSON([
                'status' => 200,
                'success' => true,
                'message' => 'Profile updated successfully.',
                'data' => $updatedUser
            ]);
        }

        return $this->response->setJSON([
            'status' => 500,
            'success' => false,
            'message' => 'Failed to update profile'
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
