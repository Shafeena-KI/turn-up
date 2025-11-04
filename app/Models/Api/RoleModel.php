<?php
namespace App\Models\Api;

use CodeIgniter\Model;

class RoleModel extends Model
{
    protected $table = 'role_access';
    protected $primaryKey = 'role_id';
    protected $allowedFields = ['role_name', 'role_status', 'created_at', 'updated_at'];

    protected $menuTable = 'role_menus';

    // Get all roles
    public function getAllRoles()
    {
        return $this->findAll();
    }

    // Get role by ID with menus
    public function getRoleWithMenus($roleId)
    {
        $role = $this->find($roleId);
        if (!$role) {
            return null;
        }

        $menus = $this->db->table($this->menuTable)
                          ->where('role_id', $roleId)
                          ->get()
                          ->getResultArray();

        $role['menus'] = $menus;
        return $role;
    }

    // Create new role with menus
    public function createRole($data)
    {
        $roleId = $this->insert([
            'role_name' => $data['role_name'],
            'role_status' => $data['role_status'] ?? 1
        ]);

        if (!empty($data['menus'])) {
            $this->saveMenus($roleId, $data['menus']);
        }

        return $roleId;
    }

    // Update role and menus
    public function updateRole($roleId, $data)
    {
        $this->update($roleId, [
            'role_name' => $data['role_name'] ?? null,
            'role_status' => $data['role_status'] ?? null
        ]);

        if (!empty($data['menus'])) {
            // Delete old menus
            $this->db->table($this->menuTable)->where('role_id', $roleId)->delete();
            $this->saveMenus($roleId, $data['menus']);
        }

        return true;
    }

    // Delete role and menus
    public function deleteRole($roleId)
    {
        $this->delete($roleId);
        $this->db->table($this->menuTable)->where('role_id', $roleId)->delete();
        return true;
    }

    // Helper: Save menu permissions
    private function saveMenus($roleId, $menus)
    {
        $builder = $this->db->table($this->menuTable);
        foreach ($menus as $menuName => $access) {
            $builder->insert([
                'role_id' => $roleId,
                'menu_name' => $menuName,
                'access' => $access ? '1' : '0'
            ]);
        }
    }
}
