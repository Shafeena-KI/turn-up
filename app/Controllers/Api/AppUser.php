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

        $email = $data['email'] ?? $this->request->getPost('email');
        $password = $data['password'] ?? $this->request->getPost('password');

        if (empty($email) || empty($password)) {
            return $this->response->setJSON([
                'status' => 400,
                'success' => false,
                'message' => 'Email and Password are required.'
            ]);
        }

        $result = $this->appUserModel->verifyUser($email, $password);

        if (isset($result['error']) && $result['error'] === true) {
            return $this->response->setJSON([
                'status' => 401,
                'success' => false,
                'message' => $result['message']
            ]);
        }

        $user = $result['data'];

        // Create JWT Token
        $key = getenv('JWT_SECRET') ?: 'default_fallback_key';

        $payload = [
            'iss' => 'turn-up',
            'iat' => time(),
            'exp' => time() + 3600,   // Expiration (1 hour)
            'data' => [
                'user_id' => $user['user_id'],
                'email' => $user['email']
            ]
        ];

        $token = JWT::encode($payload, $key, 'HS256');

        // Update token in DB
        if (!empty($user['user_id'])) {
            $this->appUserModel->update($user['user_id'], ['token' => $token, 'updated_at' => date('Y-m-d H:i:s')]);
        }

        // Remove password before sending response
        unset($user['password']);

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'message' => 'Login Successful',
            'data' => $user,
            'token' => $token
        ]);
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

}
