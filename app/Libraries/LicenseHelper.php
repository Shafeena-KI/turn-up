<?php
namespace App\Libraries;

use App\Models\AdminLicenseModel;
use App\Models\AdminModel;

class LicenseHelper
{
    public static function validateClient($client_id) {

        if(!$client_id) return  false;

        $adminModel = new AdminModel();
        $admin = $adminModel->where('admin_id', $client_id)->first();
        if(!$admin) 
            return false;
    
        return true;

    }

    public static function getMasterSecret()
    {
        $publicKeyPath = ROOTPATH . (getenv('ADMIN_PUBLIC_KEY_PATH') ?: 'admin_public.pem');
        if (!file_exists($publicKeyPath)) {
            throw new \Exception('Public key not found');
        }
        $publicKey = file_get_contents($publicKeyPath);
        
        // Use fixed string for consistency between controller and filter
        return hash('sha512', $publicKey . 'SUPER_ADMIN_LICENSE_MASTER_2024');
    }

    public static function generateLicenseHash($clientId, $expiryDate)
    {
        $normalizedDate = date('Y-m-d', strtotime($expiryDate));
        $masterSecret = self::getMasterSecret();
        return hash('sha256', $clientId . $normalizedDate . $masterSecret);
    }

    public static function validateLicenseHash($license)
    {
        if (empty($license['license_hash']) || empty($license['license_expiry'])) {
            return false;
        }

        $expectedHash = self::generateLicenseHash($license['admin_id'], $license['license_expiry']);
        return hash_equals($expectedHash, $license['license_hash']);
    }

    public static function generatePaymentLink($adminId)
    {
        $baseUrl = getenv('PAYMENT_BASE_URL') ?: 'https://payment.turnup.com';
        $planId = getenv('DEFAULT_PLAN_ID') ?: 'basic';
        return $baseUrl . '/renew?client_id=' . urlencode($adminId) . '&plan=' . $planId . '&return_url=' . urlencode(base_url());
    }

    public static function generateRevocationReason($type = 'default')
    {
        $reasons = [
            'payment_overdue' => 'Payment overdue - Please renew your subscription to continue using the service',
            'expired' => 'License has expired - Please renew your subscription',
            'violation' => 'License suspended due to terms violation - Contact support',
            'not_found' => 'No license found - Please contact administrator',
            'revoked' => 'License has been revoked - Contact support',
            'default' => 'License access revoked - Please contact administrator'
        ];
        return $reasons[$type] ?? $reasons['default'];
    }

    public static function getLicenseStatusResponse($license, $adminId)
    {
        if (!$license) {
            return [
                'license_valid' => false,
                'license_status' => 'not_found',
                'message' => 'No license found',
            ];
        }

        $isValid = self::isLicenseValid($license);

        $response = [
            'license_valid' => $isValid,
            'license_status' => $license['license_status'] ?? AdminLicenseModel::INACTIVE,
            'expiry_date' => $license['license_expiry'] ? date('Y-m-d', strtotime($license['license_expiry'])) : null
        ];

        if (!$isValid) {
            
            // Determine reason and message based on license status
            if ($license['license_expiry'] && strtotime($license['license_expiry'] ?? '') < time()) 
            {
                $response['reason'] = self::generateRevocationReason('expired');
                $response['message'] = $response['reason'];
            } 
            elseif ($license['license_status'] === AdminLicenseModel::REVOKED) 
            {
                $response['reason'] = $license['revocation_reason'] ?? self::generateRevocationReason('revoked');
                $response['message'] = $response['reason'];
            } 
            else
            {
                $response['reason'] = $license['revocation_reason'] ?? self::generateRevocationReason('default');
                $response['message'] = $response['reason'];
            }
        }

        return $response;
    }

    public static function isLicenseValid($license)
    {
        if (empty($license['license_hash']) || empty($license['license_expiry'])) {
            return false;
        }

        if ($license['license_status'] !== AdminLicenseModel::ACTIVE) {
            return false;
        }

        if (strtotime($license['license_expiry']) < time()) {
            return false;
        }

        return self::validateLicenseHash($license);
    }
}