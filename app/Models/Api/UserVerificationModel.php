<?php
namespace App\Models\Api;

use CodeIgniter\Model;

class UserVerificationModel extends Model
{
    protected $table = 'user_verifications';
    protected $primaryKey = 'verifiy_id';

    protected $allowedFields = [
        'user_id', 
        'phone_verified', 
        'email_verified', 
        'instagram_verified', 
        'linkedin_verified', 
        'location_verified', 
        'dob_added', 
        'profile_image_added', 
        'score', 
        'updated_at'
    ];

    public function isUserVerified($userId)
    {
        $row = $this->where('user_id', $userId)->first();

        if (!$row) {
            return false; // user does not exist → not verified
        }

        // Verification fields
        $fields = [
            'phone_verified',
            'email_verified',
            'instagram_verified',
            'linkedin_verified',
            'location_verified',
            'dob_added',
            'profile_image_added'
        ];

        // Check all fields
        foreach ($fields as $field) {
            if (empty($row[$field])) {
                return false; // any field = 0 → not verified
            }
        }

        return true; // all fields = 1 → fully verified
    }
}
