<?php

namespace App\Controllers\Api\PaymentGateway;

use App\Libraries\BookingLibrary;
use App\Libraries\CategoryLibrary;

use App\Models\Api\EventCategoryModel;
use App\Models\Api\PaymentModel;
use App\Libraries\CashfreePayment;
use App\Models\Api\EventInviteModel;
use App\Models\Api\TransactionModel;
use App\Models\Api\EventBookingModel;
use CodeIgniter\RESTful\ResourceController;

class PaymentController extends ResourceController
{
    protected $cashfree;
    protected $paymentModel;
    protected $bookingLibrary;
    protected $categoryLibrary;
    protected $eventInviteModel;
    protected $transactionModel;
    protected $eventBookingModel;
    protected $eventCategorygModel;


    public function __construct()
    {
        $this->paymentModel         = new PaymentModel();
        $this->cashfree             = new CashfreePayment();
        $this->eventInviteModel     = new EventInviteModel();
        $this->transactionModel     = new TransactionModel();
        $this->eventBookingModel    = new EventBookingModel();
        $this->eventCategorygModel  = new EventCategoryModel();

        $this->bookingLibrary       = new BookingLibrary();
        $this->categoryLibrary      = new CategoryLibrary();
    }

    /**
     * Create payment order
     */
    public function createOrder()
    {
        $this->paymentModel->transStart();
        
        try {
            $input = $this->request->getJSON(true);
            $userId = $this->request->getPost('user_id');
            $clientIP = $this->request->getIPAddress();
            
            // Validate authentication
            if (!$userId || !is_numeric($userId) || $userId <= 0) {
                throw new \InvalidArgumentException('Valid user authentication required');
            }

            // Validate input
            if (!is_array($input) || !isset($input['invite_id'])) {
                throw new \InvalidArgumentException('Invalid input data');
            }
            
            $inviteId = (int)$input['invite_id'];
            if ($inviteId <= 0) {
                throw new \InvalidArgumentException('Valid invite ID is required');
            }
            // Rate limiting
            $this->enforceRateLimit($userId, $clientIP);
            
            // Check for duplicate payments
            $this->validateNoDuplicatePayment($inviteId, $userId);

            // Get and validate invite
            $invite = $this->eventInviteModel->find($inviteId);
            if (!$invite || $invite['user_id'] != $userId) {
                throw new \InvalidArgumentException('Invite not found or unauthorized');
            }

            // Only allow payments for APPROVED invites
            if ($invite['status'] == EventInviteModel::PAYMENT_PENDING) {

                $invCategory = $this->eventCategorygModel->getInviteCategory($inviteId);
                $totalInvite = $this->categoryLibrary->count($invCategory->entry_type);

                if(!empty($invCategory) && !empty($totalInvite))
                {
                    if($invCategory->balance_seats < $totalInvite['invite_total'])
                    {
                        throw new \InvalidArgumentException('Your booking cannot be completed because the required number of seats is not available.');
                    }
                }
                else
                {
                    throw new \InvalidArgumentException('Your initial payment attempt has failed. Please request admin approval to retry the payment.');
                }
            }

            // Only allow payments for APPROVED invites
            if ($invite['status'] != EventInviteModel::APPROVED && $invite['status'] != EventInviteModel::PAYMENT_PENDING) {
                throw new \InvalidArgumentException('Invite must be approved for payment');
            }

            $inviteDetails = $this->eventInviteModel->getInviteDetails($inviteId, $userId);
            if (!$inviteDetails || $inviteDetails->price <= 0) {
                throw new \InvalidArgumentException('Invalid event or price');
            }

            // Generate order and create records
            $orderId = $this->cashfree->generateOrderId();
            

            $orderData = [
                'order_id' => $orderId,
                'amount' => $inviteDetails->price,
                'customer_id' => $userId,
                'customer_name' => $inviteDetails->customer_name ?? '',
                'customer_email' => $inviteDetails->customer_email ?? '',
                'customer_phone' => $inviteDetails->customer_phone ?? '',
                'return_url' => base_url('api/payment/callback'),
                'notify_url' => base_url('api/payment/webhook')
            ];

            // Create payment record
            $paymentId = $this->paymentModel->insert([
                'invite_id' => $inviteId,
                'user_id' => $userId,
                'event_id' => $inviteDetails->event_id,
                'amount' => $inviteDetails->price,
                'payment_status' => PaymentModel::PENDING,
                'payment_gateway' => 'cashfree',
                'payment_date' => date('Y-m-d H:i:s')
            ]);
            
            if (!$paymentId) {
                throw new \RuntimeException('Failed to create payment record');
            }
            
            // Create transaction record
            $transactionId = $this->transactionModel->insert([
                'payment_id' => $paymentId,
                'transaction_id' => $orderId,
                'amount' => $inviteDetails->price,
                'status' => TransactionModel::INITIATED
            ]);
            
            if (!$transactionId) {
                throw new \RuntimeException('Failed to create transaction record');
            }

            // Update invite status to PAYMENT_PENDING
            $this->eventInviteModel->update($inviteId, ['status' => EventInviteModel::PAYMENT_PENDING]);

            // Create Cashfree order
            $result = $this->cashfree->createOrder($orderData);
            if (!$result['success']) {
                throw new \RuntimeException('Payment gateway error: ' . ($result['error'] ?? 'Unknown'));
            }
            
            $this->paymentModel->transCommit();
            
            log_message('info', 'Payment order created: ' . $orderId);

            return $this->respond([
                'success' => true,
                'order_id' => $orderId,
                'payment_session_id' => $result['data']['payment_session_id'] ?? null,
                'order_token' => $result['data']['order_token'] ?? null,
                'amount' => $inviteDetails->price,
                'currency' => 'INR'
            ]);
            
        } catch (\Exception $e) {
            $this->paymentModel->transRollback();
            
            log_message('error', 'Payment creation failed: ' . $e->getMessage());
            
            if ($e instanceof \InvalidArgumentException) {
                return $this->fail($e->getMessage(), 400);
            }
            
            return $this->fail('Payment service temporarily unavailable', 503);
        }
    }

    /**
     * Verify payment status
     */
    public function verifyPayment($orderId = null)
    {
        if (!$orderId || !preg_match('/^[A-Za-z0-9_-]+$/', $orderId)) {
            return $this->fail('Invalid order ID', 400);
        }

        $this->transactionModel->transStart();
        
        try {
            $result = $this->cashfree->verifyPayment($orderId);
            if (!$result['success']) {
                throw new \RuntimeException('Payment verification failed');
            }

            $orderData = $result['data'];
            $status = strtolower($orderData['order_status'] ?? 'unknown');
            
            $transaction = $this->transactionModel->where('transaction_id', $orderId)->first();
            if (!$transaction) {
                throw new \RuntimeException('Transaction not found');
            }

            $payment = $this->paymentModel->find($transaction['payment_id']);
            if (!$payment) {
                throw new \RuntimeException('Payment not found');
            }

            // Get payment method details from Cashfree API
            $paymentMethodData = null;
            $paymentGroup = null;
            if ($status === 'paid') {
                $paymentDetails = $this->cashfree->getPaymentDetails($orderId);
                if ($paymentDetails['success'] && isset($paymentDetails['data'])) {
                    $paymentMethodData = $paymentDetails['data']['payment_method'] ?? null;
                    $paymentGroup = $paymentDetails['data']['payment_group'] ?? null;
                    $orderData['payment_method'] = $paymentGroup;
                    $orderData['payment_group']  = $paymentMethodData;
                }
            }
            
            $paymentMethod = $this->extractPaymentMethod($paymentMethodData);
            
            $updateData = [
                'status' => $status === 'paid' ? TransactionModel::SUCCESS : TransactionModel::FAILED,
                'gateway_transaction_id' => $orderData['cf_order_id'] ?? '',
                'payment_method' => $paymentMethod['type'] ?? $paymentGroup,
                'payment_details' => $paymentMethod['details'],
                'completed_at' => date('Y-m-d H:i:s'),
                'raw_response' => json_encode($orderData)
            ];
            
            if ($status === 'paid') {
                // Payment successful
                $this->paymentModel->update($payment['payment_id'], ['payment_status' => PaymentModel::SUCCESS]);
                $this->eventInviteModel->update($payment['invite_id'], ['status' => EventInviteModel::PAID]);
                $this->createBookingRecord($transaction);
            } else {
                // Payment failed
                $this->paymentModel->update($payment['payment_id'], ['payment_status' => PaymentModel::FAILED]);
                $this->eventInviteModel->update($payment['invite_id'], ['status' => EventInviteModel::PAYMENT_PENDING]);
            }
            
            $this->transactionModel->update($transaction['id'], $updateData);
            $this->transactionModel->transCommit();

            return $this->respond([
                'success' => true,
                'status' => $status,
                'order_data' => $orderData
            ]);
            
        } catch (\Exception $e) {
            $this->transactionModel->transRollback();
            log_message('error', 'Payment verification failed: ' . $e->getMessage());
            return $this->fail('Payment verification failed', 500);
        }
    }

    /**
     * Handle payment callback
     */
    public function callback()
    {
        $orderId = $this->request->getGet('order_id');
        
        if (!$orderId || !preg_match('/^[A-Za-z0-9_-]+$/', $orderId)) {
            return redirect()->to(base_url('api/payment/failed'));
        }

        $result = $this->cashfree->verifyPayment($orderId);
        
        if ($result['success'] && isset($result['data']['order_status'])) {
            $status = strtolower($result['data']['order_status']);
            
            $transaction = $this->transactionModel->where('transaction_id', $orderId)->first();
            if ($transaction) {
                $payment = $this->paymentModel->find($transaction['payment_id']);
                
                // Get payment method details for successful payments
                $paymentMethodData = null;
                $paymentGroup = null;
                if ($status === 'paid') {
                    $paymentDetails = $this->cashfree->getPaymentDetails($orderId);
                    if ($paymentDetails['success'] && isset($paymentDetails['data'])) {
                        $paymentMethodData = $paymentDetails['data']['payment_method'] ?? null;
                        $paymentGroup = $paymentDetails['data']['payment_group'] ?? null;
                    }
                }
                
                $paymentMethod = $this->extractPaymentMethod($paymentMethodData);
                
                $updateData = [
                    'status' => $status === 'paid' ? TransactionModel::SUCCESS : TransactionModel::FAILED,
                    'gateway_transaction_id' => $result['data']['cf_order_id'] ?? '',
                    'payment_method' => $paymentMethod['type'] ?? $paymentGroup,
                    'payment_details' => $paymentMethod['details'],
                    'completed_at' => date('Y-m-d H:i:s'),
                    'raw_response' => json_encode($result['data'])
                ];
                
                if ($status === 'paid') {
                    $this->paymentModel->update($payment['payment_id'], ['payment_status' => PaymentModel::SUCCESS]);
                    $this->eventInviteModel->update($payment['invite_id'], ['status' => EventInviteModel::PAID]);
                    $this->createBookingRecord($transaction);
                } else {
                    $this->paymentModel->update($payment['payment_id'], ['payment_status' => PaymentModel::FAILED]);
                    $this->eventInviteModel->update($payment['invite_id'], ['status' => EventInviteModel::PAYMENT_PENDING]);
                }
                
                $this->transactionModel->update($transaction['id'], $updateData);
            }
            
            if ($status === 'paid') {
                return redirect()->to(base_url('api/payment/success?order_id=' . $orderId));
            }
        }
        
        return redirect()->to(base_url('api/payment/failed?order_id=' . $orderId));
    }

    /**
     * Handle webhook notifications
     */
    public function webhook()
    {
        $rawBody = $this->request->getBody();
        $signature = $this->request->getHeaderLine('x-webhook-signature');
        $timestamp = $this->request->getHeaderLine('x-webhook-timestamp');
        
        // Validate webhook
        if (empty($rawBody) || strlen($rawBody) > 10000) {
            return $this->fail('Invalid webhook data', 400);
        }
        
        if (!$timestamp || abs(time() - (int)$timestamp) > 300) {
            return $this->fail('Invalid webhook timestamp', 401);
        }
        
        if (!$this->cashfree->verifyWebhookSignature($rawBody, $signature, $timestamp)) {
            return $this->fail('Invalid webhook signature', 401);
        }
        
        $this->transactionModel->transStart();

        try {
            $data = json_decode($rawBody, true);
            
            if (!isset($data['type']) || !in_array($data['type'], ['PAYMENT_SUCCESS_WEBHOOK', 'PAYMENT_FAILED_WEBHOOK'])) {
                return $this->respond(['status' => 'ignored']);
            }

            $orderData = $data['data']['order'] ?? [];
            $orderId = $orderData['order_id'] ?? null;
            
            if (!$orderId) {
                throw new \RuntimeException('Order ID not found');
            }

            $transaction = $this->transactionModel->where('transaction_id', $orderId)->first();
            if (!$transaction) {
                throw new \RuntimeException('Transaction not found');
            }

            $payment = $this->paymentModel->find($transaction['payment_id']);
            $isSuccess = $data['type'] === 'PAYMENT_SUCCESS_WEBHOOK';

            if ($isSuccess) {
                // Get payment method details for successful payments
                $paymentMethodData = null;
                $paymentGroup = null;
                $paymentDetails = $this->cashfree->getPaymentDetails($orderId);
                if ($paymentDetails['success'] && isset($paymentDetails['data'])) {
                    $paymentMethodData = $paymentDetails['data']['payment_method'] ?? null;
                    $paymentGroup = $paymentDetails['data']['payment_group'] ?? null;
                }
                
                $extractedMethod = $this->extractPaymentMethod($paymentMethodData);
                
                $updateData = [
                    'status' => TransactionModel::SUCCESS,
                    'gateway_transaction_id' => $orderData['cf_order_id'] ?? '',
                    'payment_method' => $extractedMethod['type'] ?? $paymentGroup,
                    'payment_details' => $extractedMethod['details'],
                    'completed_at' => date('Y-m-d H:i:s'),
                    'raw_response' => $rawBody
                ];
                
                $this->paymentModel->update($payment['payment_id'], ['payment_status' => PaymentModel::SUCCESS]);
                $this->eventInviteModel->update($payment['invite_id'], ['status' => EventInviteModel::PAID]);
                $this->createBookingRecord($transaction);
            } else {
                $errorDetails = $data['data']['error_details'] ?? [];
                $failureData = [
                    'error_code' => $errorDetails['error_code'] ?? null,
                    'error_description' => $errorDetails['error_description'] ?? null,
                    'failed_at' => date('Y-m-d H:i:s')
                ];
                
                // Try to get payment method details for failed payments
                $paymentMethodData = null;
                $paymentGroup = null;
                $paymentDetails = $this->cashfree->getPaymentDetails($orderId);
                if ($paymentDetails['success'] && isset($paymentDetails['data'])) {
                    $paymentMethodData = $paymentDetails['data']['payment_method'] ?? null;
                    $paymentGroup = $paymentDetails['data']['payment_group'] ?? null;
                }
                
                $extractedMethod = $this->extractPaymentMethod($paymentMethodData);
                
                $updateData = [
                    'status' => TransactionModel::FAILED,
                    'gateway_transaction_id' => $orderData['cf_order_id'] ?? '',
                    'payment_method' => $extractedMethod['type'] ?? $paymentGroup,
                    'payment_details' => json_encode(array_merge($failureData, ['payment_method' => $paymentMethodData])),
                    'completed_at' => date('Y-m-d H:i:s'),
                    'raw_response' => $rawBody
                ];
                
                $this->paymentModel->update($payment['payment_id'], ['payment_status' => PaymentModel::FAILED]);
                $this->eventInviteModel->update($payment['invite_id'], ['status' => EventInviteModel::PAYMENT_PENDING]);
            }
            
            $this->transactionModel->update($transaction['id'], $updateData);
            $this->transactionModel->transCommit();
            
            return $this->respond(['status' => 'success']);
            
        } catch (\Exception $e) {
            $this->transactionModel->transRollback();
            log_message('error', 'Webhook processing failed: ' . $e->getMessage());
            return $this->fail('Webhook processing failed', 500);
        }
    }

    public function failed() 
    {
        $orderId = $this->request->getGet('order_id');
        $paymentData = null;

        if ($orderId) {
            $transaction = $this->transactionModel->where('transaction_id', $orderId)->first();
            if ($transaction) {
                $payment = $this->paymentModel->find($transaction['payment_id']);
                
                // Try to get payment method details for failed payments
                $paymentMethodData = null;
                $paymentGroup = null;
                $paymentDetails = $this->cashfree->getPaymentDetails($orderId);
                if ($paymentDetails['success'] && isset($paymentDetails['data'])) {
                    $paymentMethodData = $paymentDetails['data']['payment_method'] ?? null;
                    $paymentGroup = $paymentDetails['data']['payment_group'] ?? null;
                    $paymentData = $paymentDetails['data'];
                }

                $extractedMethod = $this->extractPaymentMethod($paymentMethodData);
                
                $failureData = [
                    'error_code' => (isset($paymentData['error_details']) ? $paymentData['error_details']['error_code'] : 'USER_CANCELLED') ?? 'USER_CANCELLED',
                    'error_reason' => (isset($paymentData['error_details']) ? $paymentData['error_details']['error_reason'] : 'Payment is cancelled') ?? 'Payment is cancelled',
                    'error_description' =>  (isset($paymentData['error_details']) ? $paymentData['error_details']['error_description'] : 'Payment cancelled by user') ?? 'Payment cancelled by user',
                    'failed_at' => date('Y-m-d H:i:s'),
                    'payment_method' => $paymentMethodData
                ];

                $this->transactionModel->update($transaction['id'], [
                    'status' => TransactionModel::FAILED,
                    'payment_method' =>  $paymentGroup ?? $extractedMethod['type'],
                    'payment_details' => json_encode($failureData),
                    'completed_at' => date('Y-m-d H:i:s')
                ]);
                
                $this->paymentModel->update($payment['payment_id'], ['payment_status' => PaymentModel::FAILED]);
                $this->eventInviteModel->update($payment['invite_id'], ['status' => EventInviteModel::PAYMENT_PENDING]);
            }
        }
        
        // Check if request is from browser (has Accept header with text/html)
        $acceptHeader = $this->request->getHeaderLine('Accept');
        if (strpos($acceptHeader, 'text/html') !== false) {
            // Redirect to PHP page for browser requests
            return redirect()->to(base_url('payment_failed.php?order_id=' . $orderId));
        }
        
        // Return JSON for API calls (Flutter)
        return $this->respond([
            'status' => 'failed',
            'message' => 'Payment failed or cancelled',
            'order_id' => $orderId
        ]);
    }

    public function success() 
    {
        $orderId = $this->request->getGet('order_id');
        
        // Check if request is from browser (has Accept header with text/html)
        $acceptHeader = $this->request->getHeaderLine('Accept');
        if (strpos($acceptHeader, 'text/html') !== false) {
            // Redirect to PHP page for browser requests
            return redirect()->to(base_url('payment_success.php?order_id=' . $orderId));
        }
        
        // Return JSON for API calls (Flutter)
        if ($orderId) {
            $transaction = $this->transactionModel->where('transaction_id', $orderId)->first();
            
            return $this->respond([
                'status' => 'success',
                'message' => 'Payment completed successfully',
                'order_id' => $orderId,
                'transaction' => $transaction
            ]);
        }
        
        return $this->respond([
            'status' => 'success',
            'message' => 'Payment completed successfully'
        ]);
    }

    private function enforceRateLimit($userId, $clientIP)
    {
        $recentUserOrders = $this->paymentModel
            ->where('user_id', $userId)
            ->where('payment_date >', date('Y-m-d H:i:s', strtotime('-5 minutes')))
            ->countAllResults();
            
        if ($recentUserOrders >= 3) {
            throw new \InvalidArgumentException('Too many payment attempts. Please try again later.');
        }
        
        $cache = \Config\Services::cache();
        $ipKey = 'payment_attempts_' . md5($clientIP);
        $ipAttempts = $cache->get($ipKey) ?? 0;
        
        if ($ipAttempts >= 10) {
            throw new \InvalidArgumentException('Too many payment attempts from this IP. Please try again later.');
        }
        
        $cache->save($ipKey, $ipAttempts + 1, 300);
    }
    
    private function validateNoDuplicatePayment($inviteId, $userId)
    {
        $existingPayment = $this->paymentModel
            ->where('invite_id', $inviteId)
            ->where('user_id', $userId)
            ->where('payment_status', PaymentModel::SUCCESS)
            ->first();
            
        if ($existingPayment) {
            throw new \InvalidArgumentException('Payment already exists for this invite');
        }
    }

    private function extractPaymentMethod($paymentMethodData)
    {
        if (is_array($paymentMethodData) && !empty($paymentMethodData)) {
            $paymentType = array_keys($paymentMethodData)[0] ?? null;
            $paymentDetails = json_encode($paymentMethodData);
        } else {
            $paymentType = null;
            $paymentDetails = null;
        }
        
        return [
            'type' => $paymentType,
            'details' => $paymentDetails
        ];
    }

    private function createBookingRecord($transaction)
    {
        try {
            $payment = $this->paymentModel->find($transaction['payment_id']);
            if (!$payment) {
                log_message('error', 'Payment not found for transaction: ' . $transaction['id']);
                return false;
            }

            $existingBooking = $this->eventBookingModel
                ->where('invite_id', $payment['invite_id'])
                ->where('user_id', $payment['user_id'])
                ->first();
                
            if ($existingBooking) {
                $this->paymentModel->update($payment['payment_id'], ['booking_id' => $existingBooking['booking_id']]);
                log_message('info', 'Booking already exists for invite_id: ' . $payment['invite_id']);
                return true;
            }
            
            // Call BookEvent from BookingLibrary
            $bookingResult = $this->bookingLibrary->BookEvent($payment['invite_id'], $payment['user_id']);
            
            if ($bookingResult['success']) {
                $this->paymentModel->update($payment['payment_id'], ['booking_id' => $bookingResult['booking_id']]);
                
                log_message('info', 'Booking created successfully via BookingLibrary', [
                    'booking_id' => $bookingResult['booking_id'],
                    'booking_code' => $bookingResult['booking_code'],
                    'user_id' => $payment['user_id'],
                    'invite_id' => $payment['invite_id']
                ]);
                
                return true;
            }
            
            log_message('error', 'BookEvent failed: ' . ($bookingResult['message'] ?? 'Unknown error'));
            return false;
            
        } catch (\Exception $e) {
            log_message('error', 'Booking creation failed: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Get failure details for an order
     */
    public function getFailureDetails($orderId = null)
    {

        if (!$orderId || !preg_match('/^[A-Za-z0-9_-]+$/', $orderId)) {
            return $this->fail('Invalid order ID', 400);
        }

        $transaction = $this->transactionModel->where('transaction_id', $orderId)->first();
        if (!$transaction) {
            return $this->fail('Transaction not found', 404);
        }

        $payment = $this->paymentModel->find($transaction['payment_id']);
        if (!$payment) {
            return $this->fail('Payment not found', 404);
        }

        $failureDetails = [];
        if ($transaction['payment_details']) {
            $paymentDetails = json_decode($transaction['payment_details'], true);
            $failureDetails = [
                'amount' => $payment['amount'],
                'error_code' => $paymentDetails['error_code'] ?? 'UNKNOWN',
                'error_description' => $paymentDetails['error_description'] ?? 'Payment failed',
                'payment_method' => $transaction['payment_method']
            ];
        }

        return $this->respond([
            'success' => true,
            'order_id' => $orderId,
            'failure_details' => $failureDetails,
            'transaction' => $transaction,
            'failed_at' => $transaction['completed_at']
        ]);
    }

    /**
     * Test payment method fetching
     */
    public function testPaymentMethod($orderId = null)
    {
        if (!$orderId) {
            return $this->fail('Order ID required', 400);
        }

        // Get payment details from Cashfree
        $paymentData = $this->cashfree->getPaymentDetails($orderId);
        
        if (!$paymentData['success']) {
            return $this->respond([
                'success' => false,
                'error' => $paymentData['error'],
                'message' => 'Failed to fetch payment details from Cashfree'
            ]);
        }

        $paymentMethodData = $paymentData['data']['payment_method'] ?? null;
        $extractedMethod = $this->extractPaymentMethod($paymentMethodData);

        return $this->respond([
            'success' => true,
            'order_id' => $orderId,
            'raw_payment_details' => $paymentData['data'],
            'payment_method_data' => $paymentMethodData,
            'extracted_method' => $extractedMethod
        ]);
    }
}