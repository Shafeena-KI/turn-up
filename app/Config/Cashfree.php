<?php

namespace Config;

use CodeIgniter\Config\BaseConfig;

class Cashfree extends BaseConfig
{
    // Cashfree Credentials
    public $appId;
    public $secretKey;
    
    // Environment: 'sandbox' or 'production'
    public $environment;
    
    // API URLs
    public $apiUrls = [];
    
    // Return URLs
    public $returnUrl = '';  // Will be set dynamically
    public $notifyUrl = '';  // Webhook URL
    
    public function __construct()
    {
        parent::__construct();
        
        $this->appId = env('CASHFREE_APP_ID', 'YOUR_CASHFREE_APP_ID');
        $this->secretKey = str_replace(' ', '', env('CASHFREE_SECRET_KEY', 'YOUR_CASHFREE_SECRET_KEY'));
        // Auto-detect environment based on credentials
        $envFromCredentials = strpos($this->appId, 'TEST') !== false || strpos($this->secretKey, 'test') !== false ? 'sandbox' : 'production';
        $this->environment = env('CASHFREE_ENV', $envFromCredentials);

        $this->apiUrls = [ 'sandbox' => env('CASHFREE_SANDBOX_URL'), 'production' => env('CASHFREE_PROD_URL')];
    }
    
    // API Version
    public $apiVersion = '2023-08-01';
    
    /**
     * Get API URL based on environment
     */
    public function getApiUrl(): string
    {
        return $this->apiUrls[$this->environment];
    }
    
    /**
     * Get headers for API requests
     */
    public function getHeaders(): array
    {
        return [
            'x-api-version' => $this->apiVersion,
            'x-client-id' => trim($this->appId),
            'x-client-secret' => trim($this->secretKey),
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ];
    }
}