<?php

namespace App\Controllers;

class Home extends BaseController
{
    public function index(): string
    {
        return view('welcome_message');
    }

    public function licenseManager(): string
    {
        return view('license_manager');
    }

    public function verifyPrivateKey()
    {
        // Set CORS headers
        $this->response
            ->setHeader('Access-Control-Allow-Origin', '*')
            ->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With');
        
        $data = $this->request->getJSON(true);
        $privateKey = $data['private_key'] ?? '';
        
        if (empty($privateKey)) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Private key is required'
            ]);
        }
        
        // Verify private key format and validity
        if (strpos($privateKey, '-----BEGIN PRIVATE KEY-----') === false) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Invalid private key format'
            ]);
        }
        
        // Test if private key can be loaded
        $key = openssl_pkey_get_private($privateKey);
        if (!$key) {
            return $this->response->setJSON([
                'success' => false,
                'message' => 'Invalid private key'
            ]);
        }
        
        // Store in session for verification
        session()->set('verified_private_key', hash('sha256', $privateKey));
        
        return $this->response->setJSON([
            'success' => true,
            'message' => 'Private key verified successfully'
        ]);
    }
    
    public function options()
    {
        return $this->response
            ->setHeader('Access-Control-Allow-Origin', '*')
            ->setHeader('Access-Control-Allow-Methods', 'GET, POST, OPTIONS')
            ->setHeader('Access-Control-Allow-Headers', 'Content-Type, Authorization, X-Requested-With')
            ->setStatusCode(200);
    }
}
