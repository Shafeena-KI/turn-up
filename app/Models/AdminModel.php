<?php
namespace App\Models;

use CodeIgniter\Model;

class AdminModel extends Model
{
    protected $table = 'admin_users';
    protected $primaryKey = 'admin_id';
    protected $allowedFields = [
        'name', 'email', 'phone', 'password', 'role_id', 'token', 'status', 'created_at', 'updated_at'
    ];
}
