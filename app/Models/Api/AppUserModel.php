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
        'status',
        'created_at',
        'updated_at'
    ];

    protected $useTimestamps = false;
}
