<?php
namespace App\Models\Api;

use CodeIgniter\Model;

class RoleModel extends Model
{
    protected $table = 'role_access';
    protected $primaryKey = 'role_id';

    protected $allowedFields = [
        'role_name',
        'role_status',
        'role_permissions',
        'created_at',
        'updated_at'
    ];

    protected $menuTable = 'role_menus';

    // List all roles + menu names
    public function getAllRolesWithMenus()
    {
        $roles = $this->findAll();

        // Get all menu items once
        $menus = $this->db->table($this->menuTable)
                          ->get()
                          ->getResultArray();

        // Map menus by ID for fast lookup
        $menuMap = [];
        foreach ($menus as $m) {
            $menuMap[$m['rolemenu_id']] = $m['menu_name'];
        }

        // Attach menu names to each role
        foreach ($roles as &$role) {

            $ids = json_decode($role['role_permissions'], true);

            $role['permissions'] = [];

            foreach ($ids as $id) {
                if (isset($menuMap[$id])) {
                    $role['permissions'][] = [
                        'id' => $id,
                        'name' => $menuMap[$id]
                    ];
                }
            }
        }

        return $roles;
    }
}
