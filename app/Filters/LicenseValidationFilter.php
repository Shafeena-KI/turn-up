<?php
namespace App\Filters;

use App\Libraries\LicenseHelper;
use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;
use App\Models\AdminModel;
use App\Models\AdminLicenseModel;

class LicenseValidationFilter implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        // Skip for license management endpoints
        $uri = $request->getUri()->getPath();
        if (strpos($uri, 'license') !== false || strpos($uri, 'challenge') !== false) {
            return;
        }

        // Get admin ID from request or email
        $adminId = $this->extractAdminId($request);
        if (!$adminId) {
            return $this->licenseError('','The email or password you entered is incorrect.');
        }
        // Debug info
        error_log("License Filter - Checking admin ID: $adminId");

        // Validate license
        $licenseValid = $this->validateClientLicense($adminId);
        error_log("License Filter - Admin $adminId license valid: " . ($licenseValid ? 'YES' : 'NO'));
        

        if (!$licenseValid) {
            $licenseModel = new AdminLicenseModel();
            $license = $licenseModel->getCurrentLicense($adminId);
            $licenseStatus = LicenseHelper::getLicenseStatusResponse($license, $adminId);

            $status_code = $licenseStatus['license_status'] == 2 || $licenseStatus['license_status'] == 1 ? 402 : 401;

            
            return $this->licenseError($licenseStatus['license_status'],
                $licenseStatus['message'] ?? 'License validation failed',
                $status_code,
                $licenseStatus['expiry_date'] ?? null
            );
        }
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null)
    {
        // Add license headers
        $response->setHeader('X-License-Protected', 'true');
    }

    private function extractAdminId($request)
    {
        // First try explicit admin_id
        $adminId = $request->getHeaderLine('X-Admin-ID') 
                ?: $request->getPost('admin_id') 
                ?: ($request->getJSON(true)['admin_id'] ?? '');
        
        if ($adminId) return $adminId;
        
        // Extract from email for login requests
        $email = $request->getPost('email') ?: ($request->getJSON(true)['email'] ?? '');
        if ($email) {
            $adminModel = new AdminModel();
            $admin = $adminModel->where('email', $email)->first();
            return $admin ? $admin['admin_id'] : null;
        }
        
        return null;
    }

    private function validateClientLicense($adminId)
    {
        $adminModel = new AdminModel();
        $admin = $adminModel->find($adminId);

        if (!$admin) return false;

        $licenseModel = new AdminLicenseModel();
        $license = $licenseModel->getCurrentLicense($adminId);

        if ($license === null) {
            return false;
        }


        // Check license status
        if (($license['license_status'] ?? '') !== AdminLicenseModel::ACTIVE) {
            return false;
        }

        // Check expiry
        if (empty($license['license_expiry']) || strtotime($license['license_expiry']) < time()) {
            return false;
        }
        // Verify license hash integrity (CANNOT BE TAMPERED)
        if (!$this->verifyLicenseHash($license)) {
            return false;
        }

        return true;
    }

    private function verifyLicenseHash($license)
    {
        return LicenseHelper::validateLicenseHash($license);
    }

    private function licenseError($status = null, $message, $status_code = 401, $expiry_date = null)
    {
        $response = [
            'status' => $status_code,
            'success' => false,
            'message' => $message,
            'license_status' => $status
        ];
        
        if ($expiry_date) $response['expiry_date'] = $expiry_date;

        
        return service('response')->setJSON($response)->setStatusCode($status_code);
    }
}