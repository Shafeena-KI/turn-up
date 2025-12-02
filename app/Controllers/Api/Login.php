<?php
namespace App\Controllers\Api;

require_once ROOTPATH . 'vendor/autoload.php';

use App\Controllers\BaseController;
use App\Libraries\LicenseHelper;
use App\Models\AdminModel;
use App\Models\AdminLicenseModel;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

class Login extends BaseController
{
    protected $adminModel;
    protected $licenseModel;

    public function __construct()
    {
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
        $this->adminModel = new AdminModel();
        $this->licenseModel = new AdminLicenseModel();
    }

    public function adminLogin()
    {
        try {
            $data = $this->request->getJSON(true);
            $email = $data['email'] ?? '';
            $password = $data['password'] ?? '';

            if (empty($email) || empty($password)) {
                return $this->errorResponse('Email and password required', 400);
            }

            // BUILT-IN LICENSE CHECK - CANNOT BE BYPASSED
            $licenseCheck = $this->validateLicenseBeforeLogin($email);
            if ($licenseCheck !== true) {
                return $this->response->setJSON($licenseCheck)->setStatusCode(402);
            }

            // Verify admin credentials
            $result = $this->adminModel->verifyAdmin($email, $password);
            if (isset($result['error']) && $result['error'] === true) {
                return $this->errorResponse($result['message'], 401);
            }

            $admin = $result['data'];

            // DOUBLE-CHECK LICENSE (redundant security)
            $license = $this->licenseModel->getCurrentLicense($admin['admin_id']);
            if (!$license || !LicenseHelper::validateLicenseHash($license)) {
                return $this->errorResponse('License validation failed', 402);
            }

            // Generate JWT
            $key = getenv('JWT_SECRET') ?: 'default_fallback_key';
            $payload = [
                'iss' => 'turn-up-secure',
                'iat' => time(),
                'exp' => time() + 3600,
                'data' => [
                    'admin_id' => $admin['admin_id'],
                    'email' => $admin['email'],
                    'license_check' => hash('sha256', $admin['admin_id'] . time())
                ]
            ];

            $token = JWT::encode($payload, $key, 'HS256');
            $this->adminModel->update($admin['admin_id'], ['token' => $token]);

            return $this->response->setJSON([
                'status' => 200,
                'success' => true,
                'data' => $admin,
                'message' => 'Login successful',
                'token' => $token
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Login failed', 500);
        }
    }

    private function validateLicenseBeforeLogin($email)
    {
        $admin = $this->adminModel->where('email', $email)->first();
        if (!$admin) {
            return [
                'status' => 402,
                'success' => false,
                'message' => 'Admin not found',
                'error_code' => 'ADMIN_NOT_FOUND'
            ];
        }

        $license = $this->licenseModel->getCurrentLicense($admin['admin_id']);
        $licenseStatus = LicenseHelper::getLicenseStatusResponse($license, $admin['admin_id']);
        
        if (!$licenseStatus['license_valid']) {
            return [
                'status' => 402,
                'success' => false,
                'message' => $licenseStatus['message'] ?? 'License validation failed',
                'error_code' => $this->getErrorCode($licenseStatus),
                'expiry_date' => $licenseStatus['expiry_date'] ?? null
            ];
        }

        return true;
    }

    private function getErrorCode($licenseStatus)
    {
        $statusMap = [
            'not_found' => 'LICENSE_NOT_FOUND',
            AdminLicenseModel::EXPIRED => 'LICENSE_EXPIRED',
            AdminLicenseModel::REVOKED => 'LICENSE_REVOKED',
            AdminLicenseModel::INACTIVE => 'LICENSE_INACTIVE'
        ];
        return $statusMap[$licenseStatus['license_status']] ?? 'LICENSE_INVALID';
    }



    private function errorResponse($message, $code)
    {
        return $this->response->setJSON([
            'status' => $code,
            'success' => false,
            'message' => $message
        ])->setStatusCode($code);
    }
}