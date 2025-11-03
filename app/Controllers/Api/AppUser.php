<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\AppUserModel;

class AppUser extends BaseController
{
    protected $appUserModel;

    public function __construct()
    {
        $this->appUserModel = new AppUserModel();
    }

    // API: POST /api/user/register
    public function register()
    {
        try {
            $data = $this->request->getJSON(true); // Accept JSON from mobile app

            // ✅ Required fields check
            if (empty($data['name']) || empty($data['email']) || empty($data['phone']) || empty($data['password'])) {
                return $this->response->setJSON([
                    'status' => 400,
                    'success' => false,
                    'message' => 'Name, email, phone, and password are required.'
                ]);
            }

            // ✅ Check duplicate email or phone
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

            // ✅ Hash password
            $hashedPassword = password_hash($data['password'], PASSWORD_BCRYPT);

            // ✅ Generate OTP
            $otp = rand(100000, 999999);

            // ✅ Prepare data for insertion
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
                'profile_status' => 1, // 1 = Pending
                'profile_score' => 0,
                'status' => 1, // 1 = Active
                'created_at' => date('Y-m-d H:i:s')
            ];

            // ✅ Save to DB
            $this->appUserModel->insert($insertData);

            $userId = $this->appUserModel->getInsertID();

            // ✅ Success Response
            return $this->response->setJSON([
                'status' => 200,
                'success' => true,
                'message' => 'Registration successful. Please verify OTP to activate your profile.',
                'data' => [
                    'user_id' => $userId,
                    'otp' => $otp // ⚠️ Include only for testing; hide in production
                ]
            ]);
        } catch (\Exception $e) {
            return $this->response->setJSON([
                'status' => 500,
                'success' => false,
                'message' => 'Internal Server Error: ' . $e->getMessage()
            ]);
        }
    }
}
