<?php
// Super Admin License Management Client
class SuperAdminClient
{
    private $privateKeyPath;
    private $serverUrl;
    private $privateKey;

    public function __construct($privateKeyPath, $serverUrl)
    {
        $this->privateKeyPath = $privateKeyPath;
        $this->serverUrl = rtrim($serverUrl, '/');
        $this->loadPrivateKey();
    }

    public function grantAccess($clientId, $expiryDate)
    {
        try {
            $timestamp = time();
            $message = $clientId . $expiryDate . $timestamp;
            $signature = $this->signMessage($message);

            $response = $this->sendRequest('grant-access', [
                'client_id' => $clientId,
                'expiry_date' => $expiryDate,
                'timestamp' => $timestamp,
                'signature' => $signature
            ]);

            if ($response && $response['success']) {
                echo "Access granted successfully!\n";
                echo "Client ID: {$clientId}\n";
                echo "Expires: {$expiryDate}\n";
                echo "License Hash: " . substr($response['license_hash'], 0, 20) . "...\n";
                return true;
            } else {
                echo "Grant failed: " . ($response['message'] ?? 'Unknown error') . "\n";
                return false;
            }

        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            return false;
        }
    }

    public function revokeAccess($clientId)
    {
        try {
            $timestamp = time();
            $message = $clientId . 'REVOKE' . $timestamp;
            $signature = $this->signMessage($message);

            $response = $this->sendRequest('revoke-access', [
                'client_id' => $clientId,
                'timestamp' => $timestamp,
                'signature' => $signature
            ]);

            if ($response && $response['success']) {
                echo "Access revoked successfully!\n";
                echo "Client ID: {$clientId}\n";
                return true;
            } else {
                echo "Revoke failed: " . ($response['message'] ?? 'Unknown error') . "\n";
                return false;
            }

        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            return false;
        }
    }

    public function checkLicense($clientId)
    {
        try {
            $response = $this->sendRequest('check-license', [
                'client_id' => $clientId
            ]);

            if ($response && $response['success']) {
                echo "License Status for Client {$clientId}:\n";
                echo "Status: " . ($response['license_valid'] ? 'VALID' : 'INVALID') . "\n";
                echo "License Status: " . ($response['license_status'] ?? null) . "\n";
                echo "Expiry: " . ($response['expiry_date'] ?? 'N/A') . "\n";
                return $response['license_valid'];
            } else {
                echo "Check failed: " . ($response['message'] ?? 'Unknown error') . "\n";
                return false;
            }

        } catch (Exception $e) {
            echo "Error: " . $e->getMessage() . "\n";
            return false;
        }
    }

    private function loadPrivateKey()
    {
        if (!file_exists($this->privateKeyPath)) {
            throw new Exception("Private key file not found: " . $this->privateKeyPath);
        }
        $this->privateKey = file_get_contents($this->privateKeyPath);
    }

    private function signMessage($message)
    {
        $signature = '';
        if (openssl_sign($message, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            return base64_encode($signature);
        }
        return false;
    }

    private function sendRequest($endpoint, $data)
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $this->serverUrl . '/api/license/' . $endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($data),
            CURLOPT_TIMEOUT => 10,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json']
        ]);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 200) {
            return json_decode($response, true);
        }
        
        return null;
    }
}

// CLI Usage
if (php_sapi_name() === 'cli') {
    echo "Super Admin License Management\n";
    echo "================================\n\n";
    
    $action = $argv[1] ?? '';
    $clientId = $argv[2] ?? '';
    $expiryOrPrivateKey = $argv[3] ?? 'admin_private.pem';
    $serverUrl = $argv[4] ?? 'http://localhost/turn-up';
    
    if ($action === 'grant' && !empty($clientId) && !empty($argv[3])) {
        $expiryDate = $argv[3];
        $privateKey = $argv[4] ?? 'admin_private.pem';
        $serverUrl = $argv[5] ?? 'http://localhost/turn-up';
        
        $client = new SuperAdminClient($privateKey, $serverUrl);
        $client->grantAccess($clientId, $expiryDate);
        
    } elseif ($action === 'revoke' && !empty($clientId)) {
        $privateKey = $expiryOrPrivateKey;
        
        $client = new SuperAdminClient($privateKey, $serverUrl);
        $client->revokeAccess($clientId);
        
    } elseif ($action === 'check' && !empty($clientId)) {
        $privateKey = $expiryOrPrivateKey;
        
        $client = new SuperAdminClient($privateKey, $serverUrl);
        $client->checkLicense($clientId);
        
    } else {
        echo "Usage:\n";
        echo "  Grant:  php super_admin_client.php grant <client_id> <expiry_date> [private_key] [server_url]\n";
        echo "  Revoke: php super_admin_client.php revoke <client_id> [private_key] [server_url]\n";
        echo "  Check:  php super_admin_client.php check <client_id> [private_key] [server_url]\n\n";
        echo "Examples:\n";
        echo "  php super_admin_client.php grant 1 '2025-12-31' admin_private.pem\n";
        echo "  php super_admin_client.php revoke 1 admin_private.pem\n";
        echo "  php super_admin_client.php check 1 admin_private.pem\n";
    }
}
?>