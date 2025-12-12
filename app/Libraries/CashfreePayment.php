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
                'return_url' => $orderData['return_url'] ?? base_url('api/payment/link-callback?order_id=' . urlencode($orderData['order_id'])),
                'notify_url' => $orderData['notify_url'] ?? base_url('api/payment/webhook'),
                'payment_methods' => 'cc,dc,nb,upi,paylater,emi'
            ],
            'order_expiry_time' => date('c', strtotime('+24 hours')),
            'order_note' => 'Event booking payment',
            'order_tags' => [
                'invite_id' => (string)($orderData['invite_id'] ?? ''),
                'source' => 'web_app'
            ]
        ];

        try {
            $headers = array_merge($this->config->getHeaders(), [
                'x-api-version' => '2025-01-01',
                'x-request-id' => uniqid('order_')
            ]);
            
            log_message('info', 'Creating Cashfree order: ' . $orderData['order_id']);
            
            $response = $this->client->request('POST', $url, [
                'headers' => $headers,
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
     * Create payment link using Cashfree Payment Links API
     */
    public function createPaymentLink($orderId, $amount, $customerName, $customerEmail, $customerPhone, $returnUrl = null)
    {
        $url = $this->config->getApiUrl() . '/links';
        
        try {
            $payload = [
                'link_id' => 'link_' . $orderId,
                'link_amount' => (float)$amount,
                'link_currency' => 'INR',
                'link_purpose' => 'Event booking payment',
                'customer_details' => [
                    'customer_name' => $customerName,
                    'customer_email' => $customerEmail,
                    'customer_phone' => $customerPhone
                ],
                'link_meta' => [
                    'return_url' => $returnUrl ?: base_url('api/payment/link-callback?order_id=' . urlencode($orderId)),
                    'notify_url' => base_url('api/payment/webhook'),
                    'upi_intent' => false,
                    'payment_completion_page' => true
                ],
                'link_expiry_time' => date('c', strtotime('+24 hours')),
                'link_notes' => [
                    'order_id' => $orderId,
                    'purpose' => 'event_booking'
                ]
            ];
            
            $response = $this->client->request('POST', $url, [
                'headers' => array_merge($this->config->getHeaders(), [
                    'x-api-version' => '2025-01-01',
                    'x-request-id' => uniqid('link_')
                ]),
                'json' => $payload,
                'http_errors' => false
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
     * Verify payment using latest API (2025-01-01)
     */
    public function verifyPayment($orderId)
    {
        $url = $this->config->getApiUrl() . '/orders/' . $orderId;
        
        try {
            $response = $this->client->request('GET', $url, [
                'headers' => array_merge($this->config->getHeaders(), [
                    'x-api-version' => '2025-01-01'
                ]),
                'http_errors' => false
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
     * Get order details using latest API (2025-01-01)
     */
    public function getOrderDetails($orderId)
    {
        $url = $this->config->getApiUrl() . '/orders/' . $orderId;
        
        try {
            $response = $this->client->request('GET', $url, [
                'headers' => array_merge($this->config->getHeaders(), [
                    'x-api-version' => '2025-01-01'
                ]),
                'http_errors' => false
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
     * Get payment details using latest API (2025-01-01)
     */
    public function getPaymentDetails($orderId)
    {
        $url = $this->config->getApiUrl() . '/orders/' . $orderId . '/payments';
        
        try {
            $response = $this->client->request('GET', $url, [
                'headers' => array_merge($this->config->getHeaders(), [
                    'x-api-version' => '2025-01-01'
                ]),
                'http_errors' => false
            ]);

            $statusCode = $response->getStatusCode();
            $responseBody = $response->getBody();
            
            if ($statusCode >= 200 && $statusCode < 300) {
                $data = json_decode($responseBody, true);
                if (isset($data[0])) {
                    return [
                        'success' => true,
                        'data' => $data[0]
                    ];
                } else {
                    return [
                        'success' => false,
                        'error' => 'No payment details found'
                    ];
                }
            }
            
            return [
                'success' => false,
                'error' => 'HTTP ' . $statusCode . ': ' . $responseBody
            ];
        } catch (\Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }

    /**
     * Verify webhook signature using latest method
     */
    public function verifyWebhookSignature($rawBody, $signature, $timestamp)
    {
        $secretKey = $this->config->getSecretKey();
        $signedPayload = $timestamp . $rawBody;
        $expectedSignature = base64_encode(hash_hmac('sha256', $signedPayload, $secretKey, true));
        
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Get link details by link ID
     */
    public function getLinkDetails($linkId)
    {
        $url = $this->config->getApiUrl() . '/links/' . $linkId;
        
        try {
            $response = $this->client->request('GET', $url, [
                'headers' => array_merge($this->config->getHeaders(), [
                    'x-api-version' => '2025-01-01'
                ]),
                'http_errors' => false
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
     * Verify payment link status
     */
    public function verifyPaymentLink($linkId)
    {
        return $this->getLinkDetails($linkId);
    }
    
    /**
     * Get orders for a payment link using latest API (2025-01-01)
     */
    public function getLinkOrders($linkId)
    {
        $url = $this->config->getApiUrl() . '/links/' . $linkId . '/orders';
        
        try {
            $response = $this->client->request('GET', $url, [
                'headers' => array_merge($this->config->getHeaders(), [
                    'x-api-version' => '2025-01-01'
                ]),
                'http_errors' => false
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
     * Generate order ID
     */
    public function generateOrderId()
    {
        $prefix = env('RECEIPT_PREFIX') ?? 'ORDER';
        return $prefix . '_' . time() . '_' . random_int(1000, 9999);
    }
}