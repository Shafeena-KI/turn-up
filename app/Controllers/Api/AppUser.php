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
        $this->db = \Config\Database::connect();
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

            // Existing user → update only OTP
            $this->appUserModel->update($user['user_id'], [
                'otp' => $otp,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

        } else {

            // NEW USER → Insert and give profile score = 25 (Phone verified)
            $this->appUserModel->insert([
                'phone' => $phone,
                'otp' => $otp,
                'profile_status' => 0,
                'profile_score' => 20,
                'status' => 1,
                'created_at' => date('Y-m-d H:i:s')
            ]);

            // GET NEW USER ID
            $newUserId = $this->appUserModel->getInsertID();

            // Default verification row
            $this->db->table('user_verifications')->insert([
                'user_id' => $newUserId,
                'phone_verified' => 1,   // Phone verified at registration
                'email_verified' => 0,
                'instagram_verified' => 0,
                'linkedin_verified' => 0,
                'location_verified' => 0,
                'dob_added' => 0,
                'profile_image_added' => 0,
                'interest_added' => 0,
                'score' => 20,  // Add 25 points for phone
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            // Assign to user variable for response
            $user = [
                'user_id' => $newUserId
            ];
        }

        // Send OTP via WhatsApp
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

            // Get user
            $user = $this->appUserModel->where('phone', $phone)->first();
            if (!$user) {
                return $this->response->setJSON([
                    'status' => 404,
                    'success' => false,
                    'message' => 'User not found.'
                ]);
            }

            // OTP mismatch
            if ((string) $user['otp'] !== (string) $otp) {
                return $this->response->setJSON([
                    'status' => 401,
                    'success' => false,
                    'message' => 'Invalid OTP.'
                ]);
            }

            // Get verification row
            $verify = $this->db->table('user_verifications')
                ->where('user_id', $user['user_id'])
                ->get()
                ->getRowArray();

            // If missing → create default row
            if (!$verify) {
                $this->db->table('user_verifications')->insert([
                    'user_id' => $user['user_id'],
                    'phone_verified' => 0,
                    'score' => 0,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                $verify = $this->db->table('user_verifications')
                    ->where('user_id', $user['user_id'])
                    ->get()
                    ->getRowArray();
            }

            $addedScore = 0;

            // ADD PHONE VERIFY SCORE ONLY ONCE
            if ($verify['phone_verified'] == 0) {

                $addedScore = 20;

                // Update verification table
                $this->db->table('user_verifications')
                    ->where('user_id', $user['user_id'])
                    ->update([
                        'phone_verified' => 1,
                        'score' => $verify['score'] + 20,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);

                // Update user profile
                $this->appUserModel->update($user['user_id'], [
                    'profile_score' => $user['profile_score'] + 20,
                    'updated_at' => date('Y-m-d H:i:s')
                ]);

                // Reload updated user
                $user = $this->appUserModel->where('user_id', $user['user_id'])->first();
            }

            // Generate JWT
            $key = getenv('JWT_SECRET') ?: 'default_fallback_key';
            $payload = [
                'iss' => 'turn-up',
                'iat' => time(),
                'exp' => time() + 3600,
                'data' => [
                    'user_id' => $user['user_id'],
                    'phone' => $user['phone']
                ]
            ];

            $token = \Firebase\JWT\JWT::encode($payload, $key, 'HS256');

            // Save token
            $this->appUserModel->update($user['user_id'], ['token' => $token]);

            // Fix profile image path
            if (!empty($user['profile_image']) && !filter_var($user['profile_image'], FILTER_VALIDATE_URL)) {
                $user['profile_image'] = base_url('uploads/profile_images/' . $user['profile_image']);
            } else {
                $user['profile_image'] = "";
            }

            return $this->response->setJSON([
                'status' => 200,
                'success' => true,
                'message' => 'Login successful.',
                'new_score_added' => $addedScore,
                'data' => $user,
                'token' => $token
            ]);

        } catch (\Throwable $e) {

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
        $auth = $this->getAuthenticatedUser();

        if (isset($auth['error']) && $auth['error'] != 'Token missing') {
            return $this->response->setJSON([
                'status' => 401,
                'success' => false,
                'message' => $auth['error']
            ]);
        }


        $user_id = $auth['user_id'];

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

        // Profile image URL
        if (!empty($user['profile_image'])) {
            if (!preg_match('/^https?:\/\//', $user['profile_image'])) {
                $user['profile_image'] = base_url('uploads/profile_images/' . $user['profile_image']);
            }
        }

        // Gender mapping
        $genderMap = [
            1 => 'Male',
            2 => 'Female',
            3 => 'Other',
            4 => 'Couple',
        ];
        $user['gender'] = $genderMap[(int) $user['gender']] ?? 'Not set';

        // Handle interests
        $interestList = [];
        if (!empty($user['interest_id'])) {
            $interestIds = explode(',', $user['interest_id']);
            $db = \Config\Database::connect();
            $builder = $db->table('interests');
            $interests = $builder->whereIn('interest_id', $interestIds)->get()->getResultArray();

            foreach ($interests as $i) {
                $interestList[] = [
                    'interest_id' => $i['interest_id'],
                    'interest_name' => $i['interest_name']
                ];
            }
        }
        unset($user['interest_id']);
        $user['interests'] = $interestList;

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'data' => $user
        ]);
    }
    private function getAdminAuthenticatedUser()
    {
        $authHeader = $this->request->getHeaderLine('Authorization');

        if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return ['error' => 'Authorization token missing'];
        }

        $token = $matches[1];
        $key = getenv('JWT_SECRET') ?: 'default_fallback_key';

        try {
            $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($key, 'HS256'));
            $decoded = (array) $decoded; // Convert to array

            // FIX: Handle both possible structures
            if (isset($decoded['data'])) {
                return [
                    'admin_id' => $decoded['data']->admin_id ?? null,
                    'email' => $decoded['data']->email ?? null,
                ];
            }

            // If token was created without "data" wrapper
            return [
                'admin_id' => $decoded['admin_id'] ?? null,
                'email' => $decoded['email'] ?? null,
            ];

        } catch (\Throwable $e) {
            return ['error' => 'Invalid or expired token: ' . $e->getMessage()];
        }
    }

    public function AdmingetUserById()
    {
        // Validate admin token
        $auth = $this->getAdminAuthenticatedUser(); // MUST return admin data OR error

        if (isset($auth['error'])) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON([
                    'status' => 401,
                    'success' => false,
                    'message' => $auth['error']
                ]);
        }

        // Logged-in admin
        $admin_id = $auth['admin_id'];

        // Read JSON body
        $json = $this->request->getJSON(true);
        $user_id = $json['user_id'] ?? null;

        if (empty($user_id)) {
            return $this->response
                ->setStatusCode(400)
                ->setJSON([
                    'status' => 400,
                    'success' => false,
                    'message' => 'user_id is required.'
                ]);
        }

        // Fetch User
        $user = $this->appUserModel->find($user_id);

        if (!$user) {
            return $this->response
                ->setStatusCode(404)
                ->setJSON([
                    'status' => 404,
                    'success' => false,
                    'message' => 'User not found.'
                ]);
        }

        // Profile image handling
        if (!empty($user['profile_image']) && !preg_match('/^https?:\/\//', $user['profile_image'])) {
            $user['profile_image'] = base_url('uploads/profile_images/' . $user['profile_image']);
        }

        // Gender mapping
        $genderMap = [
            1 => 'Male',
            2 => 'Female',
            3 => 'Other',
            4 => 'Couple',
        ];
        $user['gender'] = $genderMap[(int) $user['gender']] ?? 'Not set';

        // Interests processing
        $interestList = [];
        if (!empty($user['interest_id'])) {
            $ids = explode(',', $user['interest_id']);

            $db = \Config\Database::connect();
            $interests = $db->table('interests')
                ->whereIn('interest_id', $ids)
                ->get()
                ->getResultArray();

            foreach ($interests as $i) {
                $interestList[] = [
                    'interest_id' => $i['interest_id'],
                    'interest_name' => $i['interest_name'],
                ];
            }
        }

        $user['interests'] = $interestList;
        unset($user['interest_id']);
        
        $verify = $this->db->table('user_verifications')
            ->where('user_id', $user_id)
            ->get()
            ->getRowArray();

        // If no verification row exists → return default values
        $user['instagram_verified'] = (int) ($verify['instagram_verified'] ?? 0);
        $user['linkedin_verified'] = (int) ($verify['linkedin_verified'] ?? 0);
        $user['profile_score'] = (int) ($verify['score'] ?? 0);
        return $this->response
            ->setStatusCode(200)
            ->setJSON([
                'status' => 200,
                'success' => true,
                'data' => $user
            ]);
    }

    // UPDATE USER DETAILS
    public function updateUser()
    {
        $auth = $this->getAuthenticatedUser();

        if (isset($auth['error'])) {
            return $this->response->setJSON([
                'status' => 401,
                'success' => false,
                'message' => $auth['error']
            ]);
        }

        $user_id = $auth['user_id'];

        $data = $this->request->getPost();
        $user_id = $data['user_id'] ?? null;

        if (!$user_id) {
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

        $profileScore = (int) ($user['profile_score'] ?? 0);
        $updatedBy = $data['updated_by'] ?? 'user'; // default user

        /* ---------- Scoring Rules ---------- */

        // Phone score only first time
        if (!empty($data['phone']) && empty($user['phone'])) {
            $profileScore += 20;
        }

        // Insta score only user first time
        if ($updatedBy == 'user' && !empty($data['insta_id']) && empty($user['insta_id'])) {
            $profileScore += 20;
        }

        // Email score only admin verification first time
        if ($updatedBy == 'admin' && !empty($data['email']) && empty($user['email'])) {
            $profileScore += 15;
        }

        // LinkedIn only user first time
        if ($updatedBy == 'user' && !empty($data['linkedin_id']) && empty($user['linkedin_id'])) {
            $profileScore += 5;
        }

        // Interest score first time
        if (!empty($data['interest_id']) && empty($user['interest_id'])) {
            $profileScore += 10;
        }

        // Location: score only if Kochi first time
        if (
            !empty($data['location']) &&
            strtolower($data['location']) === 'kochi' &&
            (empty($user['location']) || strtolower($user['location']) !== 'kochi')
        ) {
            $profileScore += 10;
        }

        // DOB first time
        if (!empty($data['dob']) && empty($user['dob'])) {
            $profileScore += 5;
        }

        /* ---------- Profile Image ---------- */
        $profileImage = $user['profile_image'];
        $file = $this->request->getFile('profile_image');

        if ($file && $file->isValid() && !$file->hasMoved()) {

            $newName = 'user_' . time() . '.' . $file->getExtension();
            $uploadPath = FCPATH . 'uploads/profile_images/';
            if (!is_dir($uploadPath))
                mkdir($uploadPath, 0777, true);

            $file->move($uploadPath, $newName);
            $profileImage = $newName;

            // First Image Upload -> score
            if (empty($user['profile_image'])) {
                $profileScore += 10;
            }

            // Remove old photo
            if (!empty($user['profile_image'])) {
                $oldPath = $uploadPath . $user['profile_image'];
                if (is_file($oldPath))
                    unlink($oldPath);
            }
        }

        if (!empty($data['password'])) {
            $data['password'] = password_hash($data['password'], PASSWORD_BCRYPT);
        } else {
            unset($data['password']);
        }
        $gender = $data['gender'] ?? $user['gender'];
        $allowedGender = [1, 2, 3, 4];
        if (!in_array((int) $gender, $allowedGender)) {
            $gender = $user['gender']; // fallback
        }

        $genderMap = [
            1 => 'Male',
            2 => 'Female',
            3 => 'Other',
            4 => 'Couple',
        ];
        $genderText = $genderMap[(int) $gender] ?? 'Not set';


        /* ---------- Build Update Data ---------- */
        $updateData = [
            'name' => $data['name'] ?? $user['name'],
            'gender' => $gender,
            'dob' => $data['dob'] ?? $user['dob'],
            'email' => $data['email'] ?? $user['email'],
            'phone' => $data['phone'] ?? $user['phone'],
            'insta_id' => $data['insta_id'] ?? $user['insta_id'],
            'linkedin_id' => $data['linkedin_id'] ?? $user['linkedin_id'],
            'location' => $data['location'] ?? $user['location'],
            'interest_id' => $data['interest_id'] ?? $user['interest_id'],
            'profile_image' => $profileImage,
            'profile_score' => $profileScore,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        /* ---------- Handle Interests ---------- */
        $interestIds = [];
        if (isset($data['interest_id'])) {
            // Convert string like ["1","2","3"] → array
            if (!is_array($data['interest_id'])) {
                $decoded = json_decode($data['interest_id'], true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $interestIds = $decoded;
                } else {
                    $interestIds = [$data['interest_id']];
                }
            } else {
                $interestIds = $data['interest_id'];
            }

            // Store comma separated values in DB
            $updateData['interest_id'] = implode(",", $interestIds);

        } else {
            // If not sent in request, take from existing user data
            $interestIds = !empty($user['interest_id']) ? explode(",", $user['interest_id']) : [];
            $updateData['interest_id'] = $user['interest_id'];
        }

        $this->appUserModel->update($user_id, $updateData);

        // ---------- Fetch interest names directly from table ----------
        $interestList = [];
        if (!empty($interestIds)) {
            $db = \Config\Database::connect();
            $builder = $db->table('interests');
            $interests = $builder->whereIn('interest_id', $interestIds)->get()->getResultArray();

            foreach ($interests as $i) {
                $interestList[] = [
                    'interest_id' => $i['interest_id'],
                    'interest_name' => $i['interest_name']
                ];
            }
        }


        $updateData['profile_image'] = !empty($profileImage)
            ? base_url('uploads/profile_images/' . $profileImage)
            : "";
        unset($updateData['interest_id']);
        $updateData['interests'] = $interestList;
        $updateData['gender'] = $genderText;
        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'message' => 'Profile updated successfully.',
            'profile_score' => $profileScore,
            'data' => $updateData
        ]);
    }
    private function getAuthenticatedUser()
    {
        $authHeader = $this->request->getHeaderLine('Authorization');

        if (!$authHeader || !preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
            return ['error' => 'Authorization token missing'];
        }

        $token = $matches[1];
        $key = getenv('JWT_SECRET') ?: 'default_fallback_key';

        try {
            $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($key, 'HS256'));

            return [
                'user_id' => $decoded->data->user_id,
                'phone' => $decoded->data->phone
            ];

        } catch (\Throwable $e) {
            return ['error' => 'Invalid or expired token: ' . $e->getMessage()];
        }
    }
    public function completeProfile()
    {
        $auth = $this->getAuthenticatedUser();
        if (isset($auth['error'])) {
            return $this->response->setJSON([
                'status' => 401,
                'success' => false,
                'message' => $auth['error']
            ]);
        }

        $user_id = $auth['user_id'];
        $data = $this->request->getPost();

        $user = $this->appUserModel->find($user_id);
        if (!$user) {
            return $this->response->setJSON([
                'status' => 404,
                'success' => false,
                'message' => 'User not found.'
            ]);
        }

        // Mandatory fields check
        $mandatoryFields = ['name', 'dob', 'gender', 'insta_id'];
        foreach ($mandatoryFields as $field) {
            if (empty($data[$field])) {
                return $this->response->setJSON([
                    'status' => 400,
                    'success' => false,
                    'message' => "$field is required."
                ]);
            }
        }

        // PROFILE IMAGE UPLOAD
        $profileImage = $user['profile_image'];
        $file = $this->request->getFile('profile_image');
        if ($file && $file->isValid() && !$file->hasMoved()) {
            $newName = 'user_' . time() . '.' . $file->getExtension();
            $uploadPath = FCPATH . 'uploads/profile_images/';
            if (!is_dir($uploadPath))
                mkdir($uploadPath, 0777, true);
            $file->move($uploadPath, $newName);
            $profileImage = $newName;
        }

        // GET VERIFICATION ROW
        $verify = $this->db->table('user_verifications')->where('user_id', $user_id)->get()->getRowArray();
        if (!$verify) {
            $this->db->table('user_verifications')->insert([
                'user_id' => $user_id,
                'phone_verified' => 0,
                'email_verified' => 0,
                'instagram_verified' => 0,
                'linkedin_verified' => 0,
                'location_verified' => 0,
                'dob_added' => 0,
                'gender_verified' => 0,
                'profile_image_added' => 0,
                'interest_added' => 0,
                'instagram_score_added' => 0,
                'linkedin_score_added' => 0,
                'email_score_added' => 0,
                'score' => 0,
                'updated_at' => date('Y-m-d H:i:s')
            ]);
            $verify = $this->db->table('user_verifications')->where('user_id', $user_id)->get()->getRowArray();
        }

        $addedScore = 0;
        $profile_score = (int) $user['profile_score'];

        $updateVerify = [];

        // SCORING LOGIC

        // Name + gender (mandatory)
        if ((int) $verify['gender_verified'] === 0) {
            $profile_score += 5;
            $addedScore += 5;
            $updateVerify['gender_verified'] = 1;
        }

        // DOB
        if ((int) $verify['dob_added'] === 0 && !empty($data['dob'])) {
            $profile_score += 5;
            $addedScore += 5;
            $updateVerify['dob_added'] = 1;
        }

        // Profile image
        if ((int) $verify['profile_image_added'] === 0 && !empty($profileImage)) {
            $profile_score += 10;
            $addedScore += 10;
            $updateVerify['profile_image_added'] = 1;
        }

        // Location (any non-empty)
        $userLocation = strtolower(trim($data['location'] ?? ''));
        if ((int) $verify['location_verified'] === 0 && !empty($userLocation)) {
            $profile_score += 10;
            $addedScore += 10;
            $updateVerify['location_verified'] = 1;
        }

        // Interests
        $interestIds = [];
        $updateInterest = $user['interest_id'];
        if (!empty($data['interest_id']) && (int) $verify['interest_added'] === 0) {
            if (!is_array($data['interest_id'])) {
                $decoded = json_decode($data['interest_id'], true);
                $interestIds = json_last_error() === JSON_ERROR_NONE ? $decoded : [$data['interest_id']];
            } else {
                $interestIds = $data['interest_id'];
            }
            $updateInterest = implode(',', $interestIds);
            $profile_score += 10;
            $addedScore += 10;
            $updateVerify['interest_added'] = 1;
        }

        // --- ADMIN VERIFIED FIELDS ---
        if (!empty($data['insta_id']) && (int) $verify['instagram_verified'] === 1 && (int) $verify['instagram_score_added'] === 0) {
            $profile_score += 20;
            $addedScore += 20;
            $updateVerify['instagram_score_added'] = 1;
        }

        if (!empty($data['linkedin_id']) && (int) $verify['linkedin_verified'] === 1 && (int) $verify['linkedin_score_added'] === 0) {
            $profile_score += 5;
            $addedScore += 5;
            $updateVerify['linkedin_score_added'] = 1;
        }

        if (!empty($data['email']) && (int) $verify['email_verified'] === 1 && (int) $verify['email_score_added'] === 0) {
            $profile_score += 15;
            $addedScore += 15;
            $updateVerify['email_score_added'] = 1;
        }

        // --- UPDATE PROFILE SCORE AND VERIFICATION ---
        $profile_score = min(100, $profile_score);
        if (!empty($updateVerify)) {
            $updateVerify['score'] = $profile_score;
            $updateVerify['updated_at'] = date('Y-m-d H:i:s');
            $this->db->table('user_verifications')->where('user_id', $user_id)->update($updateVerify);
        }

        // GENDER VALIDATION
        $gender = $data['gender'] ?? $user['gender'];
        $allowedGender = [1, 2, 3, 4];
        if (!in_array((int) $gender, $allowedGender))
            $gender = $user['gender'];

        // UPDATE USER TABLE
        $updateData = [
            'name' => $data['name'],
            'gender' => $gender,
            'dob' => $data['dob'],
            'email' => $data['email'] ?? $user['email'],
            'insta_id' => $data['insta_id'],
            'linkedin_id' => $data['linkedin_id'] ?? $user['linkedin_id'],
            'location' => $data['location'] ?? $user['location'],
            'interest_id' => $updateInterest,
            'profile_image' => $profileImage ?: $user['profile_image'],
            'profile_status' => 1,
            'profile_score' => $profile_score,
            'updated_at' => date('Y-m-d H:i:s')
        ];
        $this->appUserModel->update($user_id, $updateData);

        // Prepare response
        $genderMap = [1 => 'Male', 2 => 'Female', 3 => 'Other', 4 => 'Couple'];
        $genderText = $genderMap[(int) $updateData['gender']] ?? 'Not set';

        $interestList = [];
        if (!empty($interestIds)) {
            $db = \Config\Database::connect();
            $builder = $db->table('interests');
            $interests = $builder->whereIn('interest_id', $interestIds)->get()->getResultArray();
            foreach ($interests as $i) {
                $interestList[] = ['interest_id' => $i['interest_id'], 'interest_name' => $i['interest_name']];
            }
        }

        $responseData = $updateData;
        unset($responseData['interest_id']);
        $responseData['interests'] = $interestList;
        $responseData['gender'] = $genderText;

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'message' => 'Profile completed successfully. Pending admin verification for some fields.',
            'new_score_added' => $addedScore,
            'data' => array_merge(['user_id' => $user_id], $responseData)
        ]);
    }
    public function verifySocial()
    {
        try {
            $data = $this->request->getJSON(true);

            $user_id = $data['user_id'] ?? null;
            $type = strtolower($data['type'] ?? "");
            $status = $data['status'] ?? null; // 1 = verify, 0 = unverify

            if (!$user_id || !in_array($type, ['instagram', 'linkedin']) || !isset($status)) {
                return $this->response->setJSON([
                    'status' => 400,
                    'success' => false,
                    'message' => 'user_id, type, and status are required.'
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

            // Fetch verifications
            $verify = $this->db->table('user_verifications')
                ->where('user_id', $user_id)
                ->get()
                ->getRowArray();

            if (!$verify) {
                return $this->response->setJSON([
                    'status' => 404,
                    'success' => false,
                    'message' => 'Verification record missing.'
                ]);
            }

            $profileScore = (int) $verify['score'];
            $addedScore = 0;

            // -------------------------------
            // INSTAGRAM VERIFICATION (20 POINTS)
            // -------------------------------
            if ($type === 'instagram') {

                // If admin is verifying AND not verified earlier → add score
                if ($status == 1 && (int) $verify['instagram_verified'] == 0) {
                    $addedScore = 20;
                    $profileScore += 20;
                }

                // Update verification status
                $this->db->table('user_verifications')
                    ->where('user_id', $user_id)
                    ->update([
                        'instagram_verified' => $status,
                        'score' => $profileScore,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            }

            // -------------------------------
            // LINKEDIN VERIFICATION (5 POINTS)
            // -------------------------------
            if ($type === 'linkedin') {

                if ($status == 1 && (int) $verify['linkedin_verified'] == 0) {
                    $addedScore = 5;
                    $profileScore += 5;
                }

                $this->db->table('user_verifications')
                    ->where('user_id', $user_id)
                    ->update([
                        'linkedin_verified' => $status,
                        'score' => $profileScore,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
            }

            // Update user table profile_score
            $this->appUserModel->update($user_id, [
                'profile_score' => $profileScore,
                'updated_at' => date('Y-m-d H:i:s')
            ]);

            return $this->response->setJSON([
                'status' => 200,
                'success' => true,
                'message' => ucfirst($type) . ' verification updated successfully.',
                'new_score_added' => $addedScore,
                'total_profile_score' => $profileScore
            ]);

        } catch (\Throwable $e) {
            return $this->response->setJSON([
                'status' => 500,
                'success' => false,
                'message' => 'Error: ' . $e->getMessage()
            ]);
        }
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
        $genderMap = [
            1 => 'Male',
            2 => 'Female',
            3 => 'Other',
            4 => 'Couple',
        ];
        // Add base URL to images
        foreach ($users as &$user) {
            // Map gender integer to text
            $user['gender'] = $genderMap[(int) $user['gender']] ?? 'Not set';
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
        $json = $this->request->getJSON(true);
        $userId = $json['user_id'] ?? $this->request->getVar('user_id');
        $status = isset($json['profile_status'])
            ? (int) $json['profile_status']
            : (int) $this->request->getVar('profile_status');
        if (empty($userId)) {
            return $this->response->setJSON([
                'status' => 400,
                'success' => false,
                'message' => 'User ID is required'
            ]);
        }

        if (!in_array($status, [2, 3])) {
            return $this->response->setJSON([
                'status' => 400,
                'success' => false,
                'message' => 'Invalid status! Only 2 (Verified) or 3 (Rejected) allowed'
            ]);
        }

        $user = $this->appUserModel->find($userId);
        if (!$user) {
            return $this->response->setJSON([
                'status' => 404,
                'success' => false,
                'message' => 'User not found'
            ]);
        }

        $profile_score = (int) ($user['profile_score'] ?? 0);
        $updatedScore = $profile_score;
        $updateData = ['profile_status' => $status];

        if ($status == 2) {

            // Add missing scores only once
            if (!empty($user['email'])) {
                $updatedScore += 15;
            }

            if (!empty($user['insta_id'])) {
                $updatedScore += 20;
            }

            if (!empty($user['linkedin_id'])) {
                $updatedScore += 5;
            }

            // Score should not exceed 100
            $updatedScore = min($updatedScore, 100);
            $updateData['profile_score'] = $updatedScore;
        }

        $this->appUserModel->update($userId, $updateData);
        $genderMap = [1 => 'Male', 2 => 'Female', 3 => 'Other', 4 => 'Couple'];
        $genderText = $genderMap[(int) $user['gender']] ?? 'Not set';
        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'message' => "Profile status updated successfully.",
            'previous_score' => $profile_score,
            'updated_score' => $updatedScore,
            'gender' => $genderText,
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
