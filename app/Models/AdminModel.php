<?php
namespace App\Models;

use CodeIgniter\Model;

class AdminModel extends Model
{
    protected $table = 'admin_users';
    protected $primaryKey = 'admin_id';
    protected $allowedFields = [
        'name', 'email', 'password', 'phone', 'role_id', 'token', 'status', 'created_at', 'updated_at'
    ];

  public function verifyAdmin($email, $password)
{
    $builder = $this->db->table($this->table);
    $admin = $builder->where('email', $email)->get()->getRowArray();

    if (!$admin) {
        return ['error' => true, 'message' => 'No admin found for this email.'];
    }
    if (!password_verify($password, $admin['password'])) {
        return ['error' => true, 'message' => 'Incorrect password.'];
    }

    return ['error' => false, 'data' => $admin];
}

}
