<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Libraries\LicenseHelper;

class SignatureGenerator extends BaseController
{
    private $privateKey;

    public function __construct()
    {
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");
    }

    public function generateSignature()
    {
        try {
            $data = $this->request->getJSON(true);
            $action = $data['action'] ?? '';
            $clientId = $data['client_id'] ?? '';
            $expiryDate = $data['expiry_date'] ?? '';
            $privateKeyContent = $data['private_key'] ?? '';
            $reasonType = $data['reason_type'] ?? 'default';
            $customReason = $data['reason'] ?? '';

            if (empty($action) || empty($clientId) || empty($privateKeyContent)) {
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

            // Load private key
            if (!$this->loadPrivateKey($privateKeyContent)) {
                return $this->errorResponse('Invalid private key format', 400);
            }

            $timestamp = time();
            $signature = '';

            if ($action === 'grant') {
                if (empty($expiryDate)) {
                    return $this->errorResponse('Expiry date required for grant action', 400);
                }
                $message = $clientId . $expiryDate . $timestamp;
                $signature = $this->signMessage($message);
            } elseif ($action === 'revoke') {
                $message = $clientId . 'REVOKE' . $timestamp;
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
                'client_id' => $clientId,
                'timestamp' => $timestamp,
                'signature' => $signature,
                'message' => $message,
                'ready_payload' => $this->buildPayload($action, $clientId, $expiryDate, $timestamp, $signature, $reasonType)
            ]);

        } catch (\Exception $e) {
            return $this->errorResponse('Signature generation failed', 500);
        }
    }

    private function loadPrivateKey($privateKeyContent)
    {
        if (strpos($privateKeyContent, '-----BEGIN PRIVATE KEY-----') === false) {
            return false;
        }
        $this->privateKey = $privateKeyContent;
        return true;
    }

    private function signMessage($message)
    {
        $signature = '';
        if (openssl_sign($message, $signature, $this->privateKey, OPENSSL_ALGO_SHA256)) {
            return base64_encode($signature);
        }
        return false;
    }

    private function buildPayload($action, $clientId, $expiryDate, $timestamp, $signature, $reasonType = 'default')
    {
        if ($action === 'grant') {
            return [
                'client_id' => $clientId,
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