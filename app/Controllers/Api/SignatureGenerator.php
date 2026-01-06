<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\LicenseHelper;

class SignatureGenerator extends BaseController
{
    private $privateKey;

    public function __construct()
    {
        // No parent constructor call to avoid issues
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

    public function generateSignature()
    {
        $this->setCorsHeaders();
        
        try {
            $data = $this->request->getJSON(true);
            $action = $data['action'] ?? '';
            $clientId = $data['client_id'] ?? '';
            $expiryDate = $data['expiry_date'] ?? '';
            $privateKeyContent = $data['private_key'] ?? '';
            $reasonType = $data['reason_type'] ?? 'default';
            $customReason = $data['reason'] ?? '';

            if (empty($action) || empty($privateKeyContent)) {
                return $this->errorResponse('Missing required parameters', 400);
            }

            // For bulk operations, client_id is optional
            if (!empty($clientId)) {
                // Check for the clientID only if provided
                $adminId = LicenseHelper::validateClient($clientId);
                if (!$adminId) {
                    return $this->response->setJSON([
                        'status' => 200,
                        'success' => false,
                        'client_id' => $clientId,
                        'message' => 'Client not found',
                    ]);
                }
            }


            // Load private key
            if (!$this->loadPrivateKey($privateKeyContent)) {
                return $this->errorResponse('Invalid private key format', 400);
            }


            $timestamp = time();
            $signature = '';
            $message   = '';

            if ($action === 'grant') {
                if (empty($expiryDate)) {
                    return $this->errorResponse('Expiry date required for grant action', 400);
                }
                // Use 'ALL' if no client_id provided for bulk operations
                $messageClientId = empty($clientId) ? 'ALL' : $clientId;
                $message = $messageClientId . $expiryDate . $timestamp;
                $signature = $this->signMessage($message);
            } elseif ($action === 'revoke') {
                // Use 'ALL' if no client_id provided for bulk operations
                $messageClientId = empty($clientId) ? 'ALL' : $clientId;
                $message = $messageClientId . 'REVOKE' . $timestamp;
                $signature = $this->signMessage($message);
            } else {
                return $this->errorResponse('Invalid action. Use "grant" or "revoke"', 400);
            }

            if (!$signature) {
                return $this->errorResponse('Failed to generate signature', 500);
            }

            return $this->response->setJSON([
                'status' => 200,
                'success' => true,
                'action' => $action,
                'client_id' => $clientId ?: 'ALL',
                'timestamp' => $timestamp,
                'signature' => $signature,
                'message' => $message,
                'ready_payload' => $this->buildPayload($action, $clientId, $expiryDate, $timestamp, $signature, $reasonType)
            ]);

        } catch (\Exception $e) {
            error_log('Error in SignatureGenerator: ' . $e->getMessage()); 
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    private function loadPrivateKey($privateKeyContent)
    {
        if (strpos($privateKeyContent, '-----BEGIN PRIVATE KEY-----') === false) {
            return false;
        }
        
        // Clean up the private key - just ensure it has proper newlines
        $privateKeyContent = trim($privateKeyContent);
        $this->privateKey = $privateKeyContent;
        return true;
    }

    private function signMessage($message)
    {
        $signature = '';
        $key = openssl_pkey_get_private($this->privateKey);
        
        if (!$key) {
            $error = openssl_error_string();
            // Return debug info in response
            throw new \Exception('Private key load failed: ' . ($error ?: 'Unknown error'));
        }
        
        $result = openssl_sign($message, $signature, $key, OPENSSL_ALGO_SHA256);
        
        if ($result) {
            return base64_encode($signature);
        }
        
        $error = openssl_error_string();
        throw new \Exception('Sign failed: ' . ($error ?: 'Unknown error'));
    }

    private function buildPayload($action, $clientId, $expiryDate, $timestamp, $signature, $reasonType = 'default')
    {
        if ($action === 'grant') {
            return [
                'client_id' => (int)$clientId,
                'expiry_date' => $expiryDate,
                'timestamp' => $timestamp,
                'signature' => $signature
            ];
        } else {
            $reason = \App\Libraries\LicenseHelper::generateRevocationReason($reasonType);
            
            return [
                'client_id' => $clientId,
                'timestamp' => $timestamp,
                'signature' => $signature,
                'reason_type' => $reasonType,
                'reason' => $reason,
            ];
        }
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