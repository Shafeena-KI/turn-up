<?php

namespace App\Libraries;

use Config\Cashfree;
use CodeIgniter\HTTP\CURLRequest;

class CashfreePayment
{
    protected $config;
    protected $client;

    public function __construct()
    {
        $this->config = new Cashfree();
        $this->client = \Config\Services::curlrequest();
    }

    /**
     * Create payment order
     */
    public function createOrder($orderData)
    {
        $url = $this->config->getApiUrl() . '/orders';
        
        $payload = [
            'order_id' => $orderData['order_id'],
            'order_amount' => (float)$orderData['amount'],
            'order_currency' => 'INR',
            'customer_details' => [
                'customer_id' => $orderData['customer_id'],
                'customer_name' => $orderData['customer_name'],
                'customer_email' => $orderData['customer_email'],
                'customer_phone' => $orderData['customer_phone']
            ],
            'order_meta' => [
                'return_url' => $orderData['return_url'] ?? base_url('api/payment/callback'),
                'notify_url' => $orderData['notify_url'] ?? base_url('api/payment/webhook')
            ],
            'order_expiry_time' => date('c', strtotime('+1 hour')),
            'order_note' => 'Event booking payment for invite ID: ' . ($orderData['invite_id'] ?? '')
        ];

        try {
            log_message('debug', 'Cashfree API URL: ' . $url);
            log_message('debug', 'Cashfree Headers: ' . json_encode($this->config->getHeaders()));
            log_message('debug', 'Cashfree Payload: ' . json_encode($payload));
            
            $response = $this->client->request('POST', $url, [
                'headers' => $this->config->getHeaders(),
                'json' => $payload,
                'http_errors' => false
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody();
            
            log_message('debug', 'Cashfree Response Status: ' . $statusCode);
            log_message('debug', 'Cashfree Response Body: ' . $responseBody);

            if ($statusCode >= 200 && $statusCode < 300) {
                return [
                    'success' => true,
                    'data' => json_decode($responseBody, true)
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'HTTP ' . $statusCode . ': ' . $responseBody
                ];
            }
        } catch (\Exception $e) {
            log_message('error', 'Cashfree API Exception: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Create payment link for existing order
     */
    public function createPaymentLink($orderId)
    {
        $url = $this->config->getApiUrl() . '/links';
        
        try {
            $payload = [
                'link_id' => 'link_' . $orderId,
                'link_amount' => null, // Will use order amount
                'link_currency' => 'INR',
                'link_purpose' => 'Payment for Order: ' . $orderId,
                'order_id' => $orderId
            ];
            
            $response = $this->client->request('POST', $url, [
                'headers' => $this->config->getHeaders(),
                'json' => $payload
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody();
            
            if ($statusCode >= 200 && $statusCode < 300) {
                return [
                    'success' => true,
                    'data' => json_decode($responseBody, true)
                ];
            } else {
                return [
                    'success' => false,
                    'error' => 'HTTP ' . $statusCode . ': ' . $responseBody
                ];
            }
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get payment link (alias for createPaymentLink)
     */
    public function getPaymentLink($orderId)
    {
        return $this->createPaymentLink($orderId);
    }

    /**
     * Verify payment
     */
    public function verifyPayment($orderId)
    {
        $url = $this->config->getApiUrl() . '/orders/' . $orderId;
        
        try {
            $response = $this->client->request('GET', $url, [
                'headers' => $this->config->getHeaders()
            ]);

            return [
                'success' => true,
                'data' => json_decode($response->getBody(), true)
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Get payment details by order ID
     */
    public function getPaymentDetails($orderId)
    {
        $url = $this->config->getApiUrl() . '/orders/' . $orderId . '/payments';
        
        try {
            $response = $this->client->request('GET', $url, [
                'headers' => $this->config->getHeaders()
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody();
            
            if ($statusCode >= 200 && $statusCode < 300) {
                $data = json_decode($responseBody, true);
                // Return the first payment if multiple payments exist
                if (isset($data[0])) {
                    return [
                        'success' => true,
                        'data' => $data[0]
                    ];
                }
            }
            
            return [
                'success' => false,
                'error' => 'No payment details found'
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Verify webhook signature
     */
    public function verifyWebhookSignature($rawBody, $signature, $timestamp)
    {
        $signedPayload = $timestamp . $rawBody;
        $expectedSignature = base64_encode(hash_hmac('sha256', $signedPayload, $this->config->secretKey, true));
        
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Generate order ID
     */
    public function generateOrderId()
    {
        $prefix = env('RECEIPT_PREFIX') ?? 'ORDER';
        return $prefix . '_' . time() . '_' . rand(1000, 9999);
    }
}