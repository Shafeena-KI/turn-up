<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\RoleModel;

class RoleController extends BaseController
{
    protected $roleModel;

    public function __construct()
    {
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
        $this->roleModel = new RoleModel();
    }

    public function index()
    {
        $roles = $this->roleModel->getAllRoles();
        return $this->response->setJSON(['status' => true, 'data' => $roles]);
    }

    public function show($id)
    {
        $role = $this->roleModel->getRoleWithMenus($id);
        if (!$role) {
            return $this->response->setJSON(['status' => false, 'message' => 'Role not found']);
        }

        return $this->response->setJSON(['status' => true, 'data' => $role]);
    }

    // public function create()
    // {
    //     $data = $this->request->getJSON(true);

    //     if (empty($data['role_name'])) {
    //         return $this->response->setJSON(['status' => false, 'message' => 'Role name is required']);
    //     }

    //     $this->roleModel->createRole($data);
    //     return $this->response->setJSON([
    //         'status' => true, 
    //         'message' => 'Role created successfully',
    //         'data'    => $createdRole

    //     ]);
    // }

    public function create()
    {
        // Get JSON or form data
        $data = $this->request->getJSON(true);

        if (empty($data['role_name'])) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Role name is required'
            ]);
        }

        // Insert the role
        $roleId = $this->roleModel->createRole($data);

        // Fetch the created record (optional but recommended)
        $createdRole = $this->roleModel->find($roleId);

        // Return success response with data
        return $this->response->setJSON([
            'status' => true,
            'message' => 'Role created successfully',
            'data' => $createdRole
        ]);
    }


    public function update()
    {
        $data = $this->request->getJSON(true);
        $roleId = $this->request->getVar('role_id') ?? $data['role_id'] ?? null;

        if (empty($roleId)) {
            return $this->response->setJSON(['status' => false, 'message' => 'Role ID is required']);
        }

        $role = $this->roleModel->find($roleId);
        if (!$role) {
            return $this->response->setJSON(['status' => false, 'message' => 'Role not found']);
        }

        $this->roleModel->updateRole($roleId, $data);
        return $this->response->setJSON(['status' => true, 'message' => 'Role updated successfully']);
    }

    public function delete()
    {
        $roleId = $this->request->getVar('role_id');

        if (empty($roleId)) {
            return $this->response->setJSON(['status' => false, 'message' => 'Role ID is required']);
        }

        $this->roleModel->deleteRole($roleId);
        return $this->response->setJSON(['status' => true, 'message' => 'Role deleted successfully']);
    }
}
