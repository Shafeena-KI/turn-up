<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\LicenseHelper;
use App\Models\AdminModel;
use App\Models\AdminLicenseModel;

class SuperAdminLicense extends BaseController
{
    private $adminModel;
    private $licenseModel;
    private $publicKey;

    public function __construct()
    {
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");
        
        $this->adminModel = new AdminModel();
        $this->licenseModel = new AdminLicenseModel();
        $this->loadPublicKey();
    }

    public function grantAccess()
    {
        try {
            $data = $this->request->getJSON(true);
            $clientId = $data['client_id'] ?? '';
            $expiryDate = $data['expiry_date'] ?? '';
            $signature = $data['signature'] ?? '';
            $timestamp = $data['timestamp'] ?? '';

            if (empty($clientId) || empty($expiryDate) || empty($signature) || empty($timestamp)) {
                return $this->errorResponse('Missing required parameters', 400);
            }

            // Chekc for the clientID
            $adminId = LicenseHelper::validateClient($clientId);
                if (!$adminId) {
                    return $this->response->setJSON([
                    'status' => 200,
                    'success' => false,
                    'client_id' => $clientId,
                    'message' => 'Client not found',
                ]);
            }

            // Verify super admin signature
            $message = $clientId . $expiryDate . $timestamp;
            if (!$this->verifySignature($message, $signature)) {
                return $this->errorResponse('Invalid super admin signature', 401);
            }

            // Generate license hash
            $licenseHash = $this->generateLicenseHash($clientId, $expiryDate);

            // Normalize expiry date format for database storage
            $normalizedExpiry = date('Y-m-d H:i:s', strtotime($expiryDate));
            
            // Create/Update license record
            $existingLicense = $this->licenseModel->getCurrentLicense($clientId);
            
            if ($existingLicense) {
                $this->licenseModel->update($existingLicense['id'], [
                    'license_hash'      => $licenseHash,
                    'license_expiry'    => $normalizedExpiry,
                    'license_status'    => AdminLicenseModel::ACTIVE,
                    'revocation_reason' => null,
                    'granted_at' => date('Y-m-d H:i:s')
                ]);
            } else {
                $this->licenseModel->insert([
                    'admin_id'          => $clientId,
                    'license_hash'      => $licenseHash,
                    'license_expiry'    => $normalizedExpiry,
                    'license_status'    => AdminLicenseModel::ACTIVE,
                    'granted_at'        => date('Y-m-d H:i:s')
                ]);
            }

            return $this->response->setJSON([
                'status' => 200,
                'success' => true,
                'message' => 'Access granted successfully',
                'client_id' => $clientId,
                'expiry_date' => $expiryDate,
                'license_hash' => $licenseHash
            ]);

        } catch (\Exception $e) {

            // Log the actual error message
            log_message('error', 'GrantAccess Error: ' . $e->getMessage());

            // Send error JSON response
            return $this->response->setJSON([
                'status' => 500,
                'success' => false,
                'message' => 'Grant access failed',
                'error' => $e->getMessage()   // optional, remove if not needed in API
            ]);
        }
    }

    public function revokeAccess()
    {
        try {
            $data = $this->request->getJSON(true);
            $clientId       = $data['client_id'] ?? '';
            $signature      = $data['signature'] ?? '';
            $timestamp      = $data['timestamp'] ?? '';
            $reasonType     = $data['reason_type'] ?? 'default';
            $customReason   = $data['reason'] ?? '';


            if (empty($clientId) || empty($signature) || empty($timestamp)) {
                return $this->errorResponse('Missing required parameters', 400);
            }

            // Chekc for the clientID
            $adminId = LicenseHelper::validateClient($clientId);
                if (!$adminId) {
                    return $this->response->setJSON([
                    'status' => 200,
                    'success' => false,
                    'client_id' => $clientId,
                    'message' => 'Client not found',
                ]);
            }

            // Verify super admin signature
            $message = $clientId . 'REVOKE' . $timestamp;
            if (!$this->verifySignature($message, $signature)) {
                return $this->errorResponse('Invalid super admin signature', 401);
            }

            // Revoke license
            $license = $this->licenseModel->getCurrentLicense($clientId);
            if (!$license) {
                return $this->errorResponse('No license found for client', 404);
            }
            
            // Generate dynamic reason
            $reason = !empty($customReason) ? $customReason : LicenseHelper::generateRevocationReason($reasonType);

            $updateData = [
                'license_hash' => null,
                'license_expiry' => null,
                'license_status' => AdminLicenseModel::REVOKED,
                'revoked_at' => date('Y-m-d H:i:s'),
                'revocation_reason' => $reason,
            ];

            $this->licenseModel->update($license['id'], $updateData);

            $license = $this->licenseModel->getCurrentLicense($clientId);
            $response = [
                'status' => 200,
                'success' => true,
                'message' => 'Access revoked successfully',
                'client_id' => $clientId,
                'reason' => $reason,
                'license_status' => $license['license_status']
            ];

            return $this->response->setJSON($response);

        } catch (\Exception $e) {
            // Log the actual error message
            log_message('error', 'RevokeAccessError: ' . $e->getMessage());

            // Send error JSON response
            return $this->response->setJSON([
                'status' => 500,
                'success' => false,
                'message' => 'Revoke access failed',
                'error' => $e->getMessage()   // optional, remove if not needed in API
            ]);
        }
    }

    public function checkLicense()
    {
        try {
            $data = $this->request->getJSON(true);
            $clientId = $data['client_id'] ?? '';

            if (empty($clientId)) {
                return $this->errorResponse('Client ID required', 400);
            }

            $admin = $this->adminModel->find($clientId);
            if (!$admin) {
                return $this->errorResponse('Client not found', 404);
            }

            $license = $this->licenseModel->getCurrentLicense($clientId);
            $licenseStatus = LicenseHelper::getLicenseStatusResponse($license, $clientId);

            $response = [
                'status' => 200,
                'success' => true,
                'client_id' => $clientId
            ];
            
            $response = array_merge($response, $licenseStatus);
            
            return $this->response->setJSON($response);

        } catch (\Exception $e) {

            // Log the actual error message
            log_message('error', 'License check failed Error: ' . $e->getMessage());

            // Send error JSON response
            return $this->response->setJSON([
                'status' => 500,
                'success' => false,
                'message' => 'License check failed',
                'error' => $e->getMessage()   // optional, remove if not needed in API
            ]);
        }
    }

    private function generateLicenseHash($clientId, $expiryDate)
    {
        return LicenseHelper::generateLicenseHash($clientId, $expiryDate);
    }

    private function validateLicense($license)
    {
        if (empty($license['license_hash']) || empty($license['license_expiry'])) {
            return false;
        }

        if ($license['license_status'] !== AdminLicenseModel::ACTIVE) {
            return false;
        }

        // Check expiry
        if (strtotime($license['license_expiry']) < time()) {
            return false;
        }

        // Verify license hash integrity using shared helper
        return LicenseHelper::validateLicenseHash($license);
    }

    private function verifySignature($message, $signature)
    {
        return openssl_verify($message, base64_decode($signature), $this->publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    private function loadPublicKey()
    {
        $keyPath = ROOTPATH . (getenv('ADMIN_PUBLIC_KEY_PATH') ?: 'admin_public.pem');
        if (!file_exists($keyPath)) {
            throw new \Exception('Public key not found');
        }
        $this->publicKey = file_get_contents($keyPath);
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