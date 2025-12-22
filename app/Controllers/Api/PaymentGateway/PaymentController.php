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
        $this->paymentModel = new PaymentModel();
        $this->cashfree = new CashfreePayment();
        $this->eventInviteModel = new EventInviteModel();
        $this->transactionModel = new TransactionModel();
        $this->eventBookingModel = new EventBookingModel();
        $this->eventCategorygModel = new EventCategoryModel();

        $this->bookingLibrary = new BookingLibrary();
        $this->categoryLibrary = new CategoryLibrary();
    }

    /**
     * Create payment link only (for direct payment link usage)
     */
    public function createPaymentLink()
    {
        $this->paymentModel->transStart();

        try {
            $input = $this->request->getJSON(true);
            $userId = $this->request->getPost('user_id') ?? $input['user_id'] ?? null;
            if (!$userId) {
                throw new \InvalidArgumentException('User ID is required');
            }
            $userId = (int) $userId;
            $clientIP = $this->request->getIPAddress();

            // Validate authentication
            if (!$userId || !is_numeric($userId) || $userId <= 0) {
                throw new \InvalidArgumentException('Valid user authentication required');
            }

            // Validate input
            if (!is_array($input) || !isset($input['invite_id'])) {
                throw new \InvalidArgumentException('Invalid input data');
            }

            $inviteId = (int) $input['invite_id'];
            if ($inviteId <= 0) {
                throw new \InvalidArgumentException('Valid invite ID is required');
            }

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

                if (!empty($invCategory) && !empty($totalInvite)) {
                    if ($invCategory->balance_seats < $totalInvite['invite_total']) {
                        throw new \InvalidArgumentException('Your booking cannot be completed because the required number of seats is not available.');
                    }
                } else {
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
            $returnUrl = $input['return_url'] ?? base_url('api/payment/link-callback?order_id=' . $orderId);

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

            // Create payment link with same callback as checkout
            $linkCallbackUrl = $input['return_url'] ?? base_url('api/payment/link-callback?order_id=' . urlencode($orderId));
            $linkResult = $this->cashfree->createPaymentLink(
                $orderId,
                $inviteDetails->price,
                $inviteDetails->customer_name,
                $inviteDetails->customer_email,
                $inviteDetails->customer_phone,
                $linkCallbackUrl
            );

            if (!$linkResult['success']) {
                throw new \RuntimeException('Payment link creation failed: ' . ($linkResult['error'] ?? 'Unknown'));
            }

            $this->paymentModel->transCommit();

            log_message('info', 'Payment link created: ' . $orderId);

            return $this->respond([
                'success' => true,
                'order_id' => $orderId,
                'link_id' => $linkResult['data']['link_id'] ?? null,
                'payment_link' => $linkResult['data']['link_url'] ?? null,
                'order_amount' => (float) $inviteDetails->price,
                'order_currency' => 'INR',
                'link_expiry_time' => $linkResult['data']['link_expiry_time'] ?? null
            ]);

        } catch (\Exception $e) {
            $this->paymentModel->transRollback();

            log_message('error', 'Payment link creation failed: ' . $e->getMessage());

            if ($e instanceof \InvalidArgumentException) {
                return $this->fail($e->getMessage(), 400);
            }

            return $this->fail('Payment service temporarily unavailable', 503);
        }
    }

    /**
     * Create payment order
     */
    public function createOrder()
    {
        $this->paymentModel->transStart();

        try {
            $input = $this->request->getJSON(true);
            $userId = $this->request->getPost('user_id') ?? $input['user_id'] ?? null;
            if (!$userId) {
                throw new \InvalidArgumentException('User ID is required');
            }
            $userId = (int) $userId;
            $clientIP = $this->request->getIPAddress();

            // Validate authentication
            if (!$userId || !is_numeric($userId) || $userId <= 0) {
                throw new \InvalidArgumentException('Valid user authentication required');
            }

            // Validate input
            if (!is_array($input) || !isset($input['invite_id'])) {
                throw new \InvalidArgumentException('Invalid input data');
            }

            $inviteId = (int) $input['invite_id'];
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

                if (!empty($invCategory) && !empty($totalInvite)) {
                    if ($invCategory->balance_seats < $totalInvite['invite_total']) {
                        throw new \InvalidArgumentException('Your booking cannot be completed because the required number of seats is not available.');
                    }
                } else {
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
                'customer_id' => (string) $userId,
                'customer_name' => $inviteDetails->customer_name ?? '',
                'customer_email' => $inviteDetails->customer_email ?? '',
                'customer_phone' => $inviteDetails->customer_phone ?? '',
                'return_url' => base_url('api/payment/link-callback?order_id=' . $orderId),
                'notify_url' => base_url('api/payment/webhook'),
                'invite_id' => $inviteId
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

            // Create payment link with same callback as checkout
            $paymentLink = null;
            $returnUrl = $input['return_url'] ?? base_url('api/payment/link-callback?order_id=' . $orderId);
            $linkResult = $this->cashfree->createPaymentLink($orderId, $inviteDetails->price, $inviteDetails->customer_name, $inviteDetails->customer_email, $inviteDetails->customer_phone, $returnUrl);
            if ($linkResult['success']) {
                $paymentLink = $linkResult['data']['link_url'] ?? null;
            }

            $this->paymentModel->transCommit();

            log_message('info', 'Payment order created: ' . $orderId);

            return $this->respond([
                'success' => true,
                'order_id' => $orderId,
                'order_amount' => (float) $inviteDetails->price,
                'order_currency' => 'INR',
                'payment_session_id' => $result['data']['payment_session_id'] ?? null,
                'payment_link' => $paymentLink,
                'order_status' => $result['data']['order_status'] ?? 'ACTIVE',
                'order_expiry_time' => $result['data']['order_expiry_time'] ?? null
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

            log_message('info', 'Verify payment API response: ' . json_encode($result));

            if (!$result['success']) {
                throw new \RuntimeException('Payment verification failed: ' . ($result['error'] ?? 'Unknown error'));
            }

            $orderData = $result['data'] ?? [];
            $status = strtolower($orderData['order_status'] ?? 'unknown');

            log_message('info', 'Order status for ' . $orderId . ': ' . $status);

            $transaction = $this->transactionModel->where('transaction_id', $orderId)->first();
            if (!$transaction) {
                throw new \RuntimeException('Transaction not found');
            }

            $payment = $this->paymentModel->find($transaction['payment_id']);
            if (!$payment) {
                throw new \RuntimeException('Payment not found');
            }

            // Get payment method details - try Payment Link orders first
            $paymentMethodData = null;
            $paymentGroup = null;

            if ($status === 'paid') {
                // Try Payment Link orders API first
                $linkId = 'link_' . $orderId;
                $linkOrders = $this->cashfree->getLinkOrders($linkId);
                if ($linkOrders['success'] && !empty($linkOrders['data'])) {
                    $latestOrder = $linkOrders['data'][0];
                    $paymentMethodData = $latestOrder['payment_method'] ?? null;
                    $paymentGroup = $latestOrder['payment_group'] ?? null;
                } else {
                    // Fallback to regular payment details
                    $paymentDetails = $this->cashfree->getPaymentDetails($orderId);
                    if ($paymentDetails['success'] && isset($paymentDetails['data'])) {
                        $paymentMethodData = $paymentDetails['data']['payment_method'] ?? null;
                        $paymentGroup = $paymentDetails['data']['payment_group'] ?? null;
                    }
                }
            }

            $paymentMethod = $this->extractPaymentMethod($paymentMethodData);
            $isSuccess = $status === 'paid';

            $updateData = [
                'status' => $isSuccess ? TransactionModel::SUCCESS : TransactionModel::FAILED,
                'gateway_transaction_id' => $orderData['cf_order_id'] ?? $orderId,
                'payment_method' => $paymentMethod['type'] ?? $paymentGroup ?? 'unknown',
                'payment_details' => $paymentMethod['details'],
                'completed_at' => date('Y-m-d H:i:s'),
                'raw_response' => json_encode($orderData)
            ];

            if ($transaction && $transaction['status'] == TransactionModel::SUCCESS) {
                $this->paymentModel->update($payment['payment_id'], ['payment_status' => PaymentModel::SUCCESS]);
                $this->eventInviteModel->update($payment['invite_id'], ['status' => EventInviteModel::PAID]);
                $this->createBookingRecord($transaction);
            } else {
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
     * Handle payment callback - saves response and redirects
     */


    /**
     * Handle payment callback
     */
    public function callback()
    {
        // Log all received parameters
        $allParams = $this->request->getGet();
        log_message('info', 'Callback received params: ' . json_encode($allParams));

        $orderId = $this->request->getGet('order_id');
        $linkId = $this->request->getGet('link_id');

        if (!$orderId || !preg_match('/^[A-Za-z0-9_-]+$/', $orderId)) {
            log_message('error', 'Invalid order ID in callback: ' . ($orderId ?? 'NULL'));
            return redirect()->to(base_url('api/payment/failed'));
        }

        log_message('info', 'Processing callback for order: ' . $orderId . ($linkId ? ' (Payment Link: ' . $linkId . ')' : ''));

        $this->transactionModel->transStart();

        try {
            // Find transaction first
            $transaction = $this->transactionModel->where('transaction_id', $orderId)->first();
            if (!$transaction) {
                log_message('error', 'Transaction not found for order: ' . $orderId);
                return redirect()->to(base_url('api/payment/failed?order_id=' . $orderId));
            }

            $payment = $this->paymentModel->find($transaction['payment_id']);
            if (!$payment) {
                log_message('error', 'Payment not found for transaction: ' . $transaction['id']);
                return redirect()->to(base_url('api/payment/failed?order_id=' . $orderId));
            }

            // Try Payment Link orders first
            $linkIdToCheck = 'link_' . $orderId;
            $linkOrders = $this->cashfree->getLinkOrders($linkIdToCheck);

            $orderData = [];
            $status = 'unknown';
            $paymentMethodData = null;
            $paymentGroup = null;

            if ($linkOrders['success'] && !empty($linkOrders['data'])) {
                $latestOrder = $linkOrders['data'][0];
                $status = strtolower($latestOrder['order_status'] ?? 'unknown');
                $orderData = $latestOrder;

                log_message('info', 'Payment Link order found - Status: ' . $status);

                // Get payment method from the Cashfree order ID
                $cfOrderId = $latestOrder['order_id'] ?? null; // Use order_id from response, not cf_order_id
                $paymentMethodData = null;
                $paymentGroup = null;

                if ($cfOrderId && $status === 'paid') {
                    log_message('info', 'Fetching payment details for order_id: ' . $cfOrderId);
                    $paymentDetails = $this->cashfree->getPaymentDetails($cfOrderId);
                    if ($paymentDetails['success'] && isset($paymentDetails['data'])) {
                        $paymentMethodData = $paymentDetails['data']['payment_method'] ?? null;
                        $paymentGroup = $paymentDetails['data']['payment_group'] ?? null;
                        log_message('info', 'Payment method from API: ' . json_encode($paymentMethodData));
                        log_message('info', 'Payment group from API: ' . ($paymentGroup ?? 'NULL'));
                    } else {
                        log_message('warning', 'Failed to get payment details for order_id: ' . $cfOrderId);
                    }
                }
            } else {
                log_message('warning', 'Payment link orders not found, trying regular order verification');
                // Fallback to regular order verification
                $result = $this->cashfree->verifyPayment($orderId);
                if ($result['success']) {
                    $orderData = $result['data'] ?? [];
                    $status = strtolower($orderData['order_status'] ?? 'unknown');

                    if (in_array($status, ['active', 'paid'])) {
                        $paymentDetails = $this->cashfree->getPaymentDetails($orderId);
                        if ($paymentDetails['success'] && isset($paymentDetails['data'])) {
                            $paymentData = $paymentDetails['data'];
                            if ($status === 'active') {
                                $status = strtolower($paymentData['payment_status'] ?? 'failed');
                            }
                            $paymentMethodData = $paymentData['payment_method'] ?? null;
                            $paymentGroup = $paymentData['payment_group'] ?? null;
                        }
                    }
                }
            }

            log_message('info', 'Final payment status for order ' . $orderId . ': ' . $status);

            // Enhanced status checking - include more success statuses
            $successStatuses = ['paid', 'success', 'successful', 'completed', 'settled'];
            $isSuccess = in_array($status, $successStatuses);

            log_message('info', 'Is payment successful? ' . ($isSuccess ? 'YES' : 'NO') . ' (Status: ' . $status . ')');

            $updateData = [
                'status' => $isSuccess ? TransactionModel::SUCCESS : TransactionModel::FAILED,
                'gateway_transaction_id' => $orderData['cf_order_id'] ?? $orderData['link_id'] ?? $orderId,
                'completed_at' => date('Y-m-d H:i:s'),
                'raw_response' => json_encode($orderData)
            ];

            // Add payment method details if available
            if ($paymentMethodData || $paymentGroup) {
                $paymentMethod = $this->extractPaymentMethod($paymentMethodData);
                $finalPaymentMethod = $paymentMethod['type'] ?? $paymentGroup ?? 'unknown';
                $updateData['payment_method'] = $finalPaymentMethod;
                $updateData['payment_details'] = $paymentMethod['details'];
                log_message('info', 'Saving payment method: ' . $finalPaymentMethod . ' (extracted: ' . ($paymentMethod['type'] ?? 'NULL') . ', group: ' . ($paymentGroup ?? 'NULL') . ')');
            } else {
                log_message('warning', 'No payment method data found for order: ' . $orderId);
                $updateData['payment_method'] = 'unknown';
            }

            // Update records
            if ($transaction && $transaction['status'] == TransactionModel::SUCCESS) {
                $this->paymentModel->update($payment['payment_id'], ['payment_status' => PaymentModel::SUCCESS]);
                $this->eventInviteModel->update($payment['invite_id'], ['status' => EventInviteModel::PAID]);
                $this->createBookingRecord($transaction);
                log_message('info', 'Updated invite status to PAID (5) for invite_id: ' . $payment['invite_id']);
            } else {
                $this->paymentModel->update($payment['payment_id'], ['payment_status' => PaymentModel::FAILED]);
                $this->eventInviteModel->update($payment['invite_id'], ['status' => EventInviteModel::PAYMENT_PENDING]);
                log_message('info', 'Updated invite status to PAYMENT_PENDING (4) for invite_id: ' . $payment['invite_id']);
            }

            $this->transactionModel->update($transaction['id'], $updateData);
            $this->transactionModel->transCommit();

            log_message('info', 'Transaction updated - Status: ' . ($isSuccess ? 'SUCCESS (1)' : 'FAILED (2)') . ', Payment Method: ' . ($updateData['payment_method'] ?? 'unknown'));

            if ($isSuccess) {
                log_message('info', 'Payment successful for order: ' . $orderId);
                return redirect()->to(base_url('api/payment/success?order_id=' . $orderId));
            }

        } catch (\Exception $e) {
            $this->transactionModel->transRollback();
            log_message('error', 'Callback processing failed for order ' . $orderId . ': ' . $e->getMessage());
        }

        log_message('info', 'Payment failed/cancelled for order: ' . $orderId);
        return redirect()->to(base_url('api/payment/failed?order_id=' . $orderId));
    }



    /* Handle webhook notifications
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

        if (!$timestamp || abs(time() - (int) $timestamp) > 300) {
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

            if ($transaction && $transaction['status'] == TransactionModel::SUCCESS) {
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
                    'error_description' => (isset($paymentData['error_details']) ? $paymentData['error_details']['error_description'] : 'Payment cancelled by user') ?? 'Payment cancelled by user',
                    'failed_at' => date('Y-m-d H:i:s'),
                    'payment_method' => $paymentMethodData
                ];

                $this->transactionModel->update($transaction['id'], [
                    'status' => TransactionModel::FAILED,
                    'payment_method' => $paymentGroup ?? $extractedMethod['type'],
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

    /**
     * Handle payment link specific callback
     */
    public function linkCallback()
    {
        $orderId = $this->request->getGet('order_id');
        $linkId = $this->request->getGet('link_id');

        if (!$orderId) {
            log_message('error', 'No order ID in payment link callback');
            return redirect()->to(base_url('api/payment/failed'));
        }

        log_message('info', 'Processing payment link callback for order: ' . $orderId . ' link: ' . $linkId);

        // Use DB connection transactions (safer)
        $db = $this->transactionModel->db ?? \Config\Database::connect();
        $db->transStart();

        try {
            $linkIdToCheck = $linkId ?: 'link_' . $orderId;
            $linkOrders = $this->cashfree->getLinkOrders($linkIdToCheck);

            if (empty($linkOrders) || !$linkOrders['success'] || empty($linkOrders['data'])) {
                log_message('error', 'No payment link orders found for: ' . $linkIdToCheck . ' response: ' . json_encode($linkOrders));
                // rollback via transComplete (if needed) then redirect
                $db->transRollback();
                return redirect()->to(base_url('api/payment/failed?order_id=' . $orderId));
            }

            $latestOrder = $linkOrders['data'][0];
            $status = strtolower($latestOrder['order_status'] ?? 'unknown');
            log_message('info', 'Payment link order status: ' . $status);

            $cfOrderId = $latestOrder['order_id'] ?? null;
            $paymentMethodData = null;
            $paymentGroup = null;

            if ($cfOrderId && $status === 'paid') {
                log_message('info', 'Fetching payment details for order_id: ' . $cfOrderId);
                $paymentDetails = $this->cashfree->getPaymentDetails($cfOrderId);
                log_message('info', 'Payment details response: ' . json_encode($paymentDetails));
                if (!empty($paymentDetails['success']) && isset($paymentDetails['data'])) {
                    $paymentMethodData = $paymentDetails['data']['payment_method'] ?? null;
                    $paymentGroup = $paymentDetails['data']['payment_group'] ?? null;
                    log_message('info', 'Payment method from API: ' . json_encode($paymentMethodData));
                    log_message('info', 'Payment group from API: ' . ($paymentGroup ?? 'NULL'));
                } else {
                    log_message('warning', 'Failed to get payment details or empty: ' . json_encode($paymentDetails));
                }
            }

            // Find transaction by transaction_id (not primary key)
            $transaction = $this->transactionModel->where('transaction_id', $orderId)->first();
            if (!$transaction) {
                log_message('error', 'Transaction not found for order: ' . $orderId);
                $db->transRollback();
                return redirect()->to(base_url('api/payment/failed?order_id=' . $orderId));
            }

            // Find payment using payment_id from transaction
            $payment = $this->paymentModel->where('payment_id', $transaction['payment_id'])->first();
            if (!$payment) {
                log_message('error', 'Payment not found for transaction payment_id: ' . ($transaction['payment_id'] ?? 'NULL'));
                $db->transRollback();
                return redirect()->to(base_url('api/payment/failed?order_id=' . $orderId));
            }

            $isSuccess = in_array($status, ['paid', 'success']);
            log_message('info', 'Is payment successful? ' . ($isSuccess ? 'YES' : 'NO') . ' (Status: ' . $status . ')');

            $updateData = [
                'status' => $isSuccess ? TransactionModel::SUCCESS : TransactionModel::FAILED,
                'gateway_transaction_id' => $latestOrder['link_id'] ?? $linkIdToCheck,
                'completed_at' => date('Y-m-d H:i:s'),
                'raw_response' => json_encode($latestOrder)
            ];

            // extract payment method details if available
            if ($paymentMethodData || $paymentGroup) {
                $paymentMethod = $this->extractPaymentMethod($paymentMethodData);
                $finalPaymentMethod = $paymentMethod['type'] ?? $paymentGroup ?? 'unknown';
                $updateData['payment_method'] = $finalPaymentMethod;
                $updateData['payment_details'] = $paymentMethod['details'] ?? null;
                log_message('info', 'Saving payment method: ' . $finalPaymentMethod);
            } else {
                log_message('warning', 'No payment method data found for order: ' . $orderId);
                $updateData['payment_method'] = 'unknown';
            }

            // Update payment_status (use where()->update to avoid PK mismatch)
            $paymentUpdateOk = $this->paymentModel
                ->where('payment_id', $payment['payment_id'])
                ->set(['payment_status' => $isSuccess ? PaymentModel::SUCCESS : PaymentModel::FAILED])
                ->update();
            log_message('info', 'Payment table update ok? ' . ($paymentUpdateOk ? 'YES' : 'NO'));
            if (!$paymentUpdateOk) {
                log_message('error', 'Payment update failed: ' . json_encode($this->paymentModel->errors()) . ' DB error: ' . json_encode($this->paymentModel->db->error()));
                // don't throw yet — continue so we capture more logs; optionally throw to force rollback
            }

            // Update invite status
            $inviteStatus = $isSuccess ? EventInviteModel::PAID : EventInviteModel::PAYMENT_PENDING;
            $inviteUpdateOk = $this->eventInviteModel->where('invite_id', $payment['invite_id'])->set(['status' => $inviteStatus])->update();
            log_message('info', 'Event invite update ok? ' . ($inviteUpdateOk ? 'YES' : 'NO'));
            if (!$inviteUpdateOk) {
                log_message('error', 'Event invite update failed. Errors: ' . json_encode($this->eventInviteModel->errors()) . ' DB error: ' . json_encode($this->eventInviteModel->db->error()));
            }

            // Create booking record only after marking paid
            if ($transaction && $transaction['status'] == TransactionModel::SUCCESS) {
                $this->createBookingRecord($transaction);
                log_message('info', 'Created booking record for transaction id: ' . ($transaction['id'] ?? 'NULL'));
            }

            // Update transaction row using where (avoid relying on PK name)
            $transactionUpdateOk = $this->transactionModel
                ->where('transaction_id', $orderId)
                ->set($updateData)
                ->update();
            log_message('info', 'Transaction update ok? ' . ($transactionUpdateOk ? 'YES' : 'NO'));
            if (!$transactionUpdateOk) {
                log_message('error', 'Transaction update failed: ' . json_encode($this->transactionModel->errors()) . ' DB error: ' . json_encode($this->transactionModel->db->error()));
                // optionally throw new \RuntimeException('Transaction update failed');
            }

            // Force commit regardless of individual update results
            $db->transCommit();
            log_message('info', 'Database transaction committed successfully');

            if ($isSuccess) {
                log_message('info', 'Payment link successful for order: ' . $orderId);
                return redirect()->to(base_url('api/payment/success?order_id=' . $orderId));
            }

        } catch (\Exception $e) {
            // ensure rollback
            if ($db->transStatus() !== null) {
                $db->transRollback();
            }
            log_message('error', 'Payment link callback failed for order ' . $orderId . ': ' . $e->getMessage());
        }

        return redirect()->to(base_url('api/payment/failed?order_id=' . $orderId));
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
        log_message('info', 'Extracting payment method from: ' . json_encode($paymentMethodData));

        if (is_array($paymentMethodData) && !empty($paymentMethodData)) {
            $paymentType = null;
            $paymentDetails = json_encode($paymentMethodData);

            // Handle Cashfree payment method structure: {"upi": {...}}
            if (isset($paymentMethodData['upi'])) {
                $paymentType = 'upi';
            } elseif (isset($paymentMethodData['card'])) {
                $paymentType = 'card';
            } elseif (isset($paymentMethodData['netbanking'])) {
                $paymentType = 'netbanking';
            } elseif (isset($paymentMethodData['wallet'])) {
                $paymentType = 'wallet';
            } elseif (isset($paymentMethodData['type'])) {
                $paymentType = $paymentMethodData['type'];
            } elseif (isset($paymentMethodData['method'])) {
                $paymentType = $paymentMethodData['method'];
            } else {
                // Get first key as payment type
                $paymentType = array_keys($paymentMethodData)[0] ?? null;
            }

            log_message('info', 'Extracted payment type: ' . ($paymentType ?? 'NULL'));
        } elseif (is_string($paymentMethodData) && !empty($paymentMethodData)) {
            $paymentType = $paymentMethodData;
            $paymentDetails = json_encode(['method' => $paymentMethodData]);
            log_message('info', 'String payment method: ' . $paymentType);
        } else {
            $paymentType = null;
            $paymentDetails = null;
            log_message('warning', 'No valid payment method data found');
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


}