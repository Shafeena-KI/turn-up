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
        $this->adminModel = new AdminModel();
        $this->licenseModel = new AdminLicenseModel();
        
        try {
            $this->loadPublicKey();
        } catch (\Exception $e) {
            log_message('error', 'Public key load failed: ' . $e->getMessage());
            $this->publicKey = null;
        }
    }
    
    private function setCorsHeaders()
    {
        if ($this->response) {
            $this->response
                ->setHeader('Access-Control-Allow-Origin', '*')
                ->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
                ->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        }
    }
    
    public function options()
    {
        $this->setCorsHeaders();
        return $this->response->setStatusCode(200);
    }

    public function grantAccess()
    {
        try {
            $data = $this->request->getJSON(true);
            $clientId = $data['client_id'] ?? null;
            $expiryDate = $data['expiry_date'] ?? '';
            $signature = $data['signature'] ?? '';
            $timestamp = $data['timestamp'] ?? '';

            if (empty($expiryDate) || empty($signature) || empty($timestamp)) {
                return $this->errorResponse('Missing required parameters', 400);
            }

            // If no client_id provided, grant access to all users
            if (empty($clientId)) {
                return $this->grantAccessToAll($expiryDate, $signature, $timestamp);
            }

            // Check for the clientID
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
            $clientId       = $data['client_id'] ?? null;
            $signature      = $data['signature'] ?? '';
            $timestamp      = $data['timestamp'] ?? '';
            $reasonType     = $data['reason_type'] ?? 'default';
            $customReason   = $data['reason'] ?? '';

            if (empty($signature) || empty($timestamp)) {
                return $this->errorResponse('Missing required parameters', 400);
            }

            // If no client_id provided, revoke access for all users
            if (empty($clientId)) {
                return $this->revokeAccessForAll($signature, $timestamp, $reasonType, $customReason);
            }

            // Check for the clientID
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
        $this->setCorsHeaders();
        
        try {
            $data = $this->request->getJSON(true);
            $clientId = $data['client_id'] ?? null;

            // If no client_id provided, return all users' license status
            if (empty($clientId)) {
                return $this->checkAllLicenses();
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

    private function checkAllLicenses()
    {
        $allAdmins = $this->adminModel->findAll();
        $licenses = [];

        foreach ($allAdmins as $admin) {
            $clientId = $admin['admin_id'];
            $license = $this->licenseModel->getCurrentLicense($clientId);
            $licenseStatus = LicenseHelper::getLicenseStatusResponse($license, $clientId);
            
            $licenses[] = array_merge([
                'client_id' => $clientId,
                'name' => $admin['name'] ?? '',
                'email' => $admin['email'] ?? ''
            ], $licenseStatus);
        }

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'message' => 'All users license status retrieved',
            'total_users' => count($licenses),
            'licenses' => $licenses
        ]);
    }

    private function grantAccessToAll($expiryDate, $signature, $timestamp)
    {
        // Verify signature for bulk operation (using 'ALL' as client identifier)
        $message = 'ALL' . $expiryDate . $timestamp;
        if (!$this->verifySignature($message, $signature)) {
            return $this->errorResponse('Invalid super admin signature', 401);
        }

        // Get all admin users
        $allAdmins = $this->adminModel->findAll();
        $normalizedExpiry = date('Y-m-d H:i:s', strtotime($expiryDate));
        $processedCount = 0;

        foreach ($allAdmins as $admin) {
            $clientId = $admin['admin_id'];
            $licenseHash = $this->generateLicenseHash($clientId, $expiryDate);
            
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
            $processedCount++;
        }

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'message' => 'Access granted to all users successfully',
            'processed_count' => $processedCount,
            'expiry_date' => $expiryDate
        ]);
    }

    private function revokeAccessForAll($signature, $timestamp, $reasonType, $customReason)
    {
        // Verify signature for bulk operation (using 'ALL' as client identifier)
        $message = 'ALL' . 'REVOKE' . $timestamp;
        if (!$this->verifySignature($message, $signature)) {
            return $this->errorResponse('Invalid super admin signature', 401);
        }

        // Get all admin users with active licenses
        $allAdmins = $this->adminModel->findAll();
        $reason = !empty($customReason) ? $customReason : LicenseHelper::generateRevocationReason($reasonType);
        $processedCount = 0;

        foreach ($allAdmins as $admin) {
            $clientId = $admin['admin_id'];
            $license = $this->licenseModel->getCurrentLicense($clientId);
            
            if ($license && $license['license_status'] == AdminLicenseModel::ACTIVE) {
                $updateData = [
                    'license_hash' => null,
                    'license_expiry' => null,
                    'license_status' => AdminLicenseModel::REVOKED,
                    'revoked_at' => date('Y-m-d H:i:s'),
                    'revocation_reason' => $reason,
                ];
                
                $this->licenseModel->update($license['id'], $updateData);
                $processedCount++;
            }
        }

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'message' => 'Access revoked for all users successfully',
            'processed_count' => $processedCount,
            'reason' => $reason
        ]);
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
        if (!$this->publicKey) {
            return false; // No public key available
        }
        return openssl_verify($message, base64_decode($signature), $this->publicKey, OPENSSL_ALGO_SHA256) === 1;
    }

    private function loadPublicKey()
    {
        $keyPath = ROOTPATH . (getenv('ADMIN_PUBLIC_KEY_PATH') ?: 'admin_public.pem');
        if (!file_exists($keyPath)) {
            // Create a temporary public key for testing
            $this->publicKey = "-----BEGIN PUBLIC KEY-----\nMIIBIjANBgkqhkiG9w0BAQEFAAOCAQ8AMIIBCgKCAQEA0vx7agoebGcQSuuPiLJX\nZptN9nndrQmbXEps2aiAFbWhM78LhWx4cbbfAAtVT86zwu1RK7aPFFxuhDR1L6tS\noc_BJECPebWKRXjBZCiFV4n3oknjhMstn64tZ_2W-5JsGY4Hc5n9yBXArwl93lqt\n7_RN5w6Cf0h4QyQ5v-65YGjQR0_FDW2QvzqY368QQMicAtaSqzs8KJZgnYb9c7d0\nzgcAkbFDaUBMCf-Fk-nBb1fh6WhQyU0jZKQDhJBm69Qn-----END PUBLIC KEY-----";
            return;
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