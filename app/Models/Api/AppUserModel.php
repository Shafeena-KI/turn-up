<?php
namespace App\Models\Api;

use CodeIgniter\Model;

class AppUserModel extends Model
{
    protected $table = 'app_users';
    protected $primaryKey = 'user_id';

    protected $allowedFields = [
        'name',
        'gender',
        'dob',
        'email',
        'phone',
        'insta_id',
        'linkedin_id',
        'location',
        'profile_image',
        'interest_id',
        'password',
        'otp',
        'profile_status',
        'profile_score',
        'token',
        'status',
        'created_at',
        'updated_at'
    ];

    protected $useTimestamps = false;
    public function verifyUser($email, $password)
    {
        $builder = $this->db->table($this->table);
        $user = $builder->where('email', $email)->get()->getRowArray();

        if (!$user) {
            return ['error' => true, 'message' => 'No user found for this email.'];
        }

        if (!password_verify($password, $user['password'])) {
            return ['error' => true, 'message' => 'Incorrect password.'];
        }

        if ($user['status'] != 1) {
            return ['error' => true, 'message' => 'User account is inactive.'];
        }

        return ['error' => false, 'data' => $user];
    }
}
