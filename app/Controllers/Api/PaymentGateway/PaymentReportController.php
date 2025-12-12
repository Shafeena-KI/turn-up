<?php

namespace App\Controllers\Api\PaymentGateway;

use App\Models\Api\TransactionModel;
use CodeIgniter\RESTful\ResourceController;

class PaymentReportController extends ResourceController
{
    protected $transactionModel;

    public function __construct()
    {
        $this->transactionModel     = new TransactionModel();
    }

    /**
     * Get all transactions (admin)
     */
    public function getAllTransactions()
    {
        $page = $this->request->getGet('page') ?? 1;
        $limit = $this->request->getGet('limit') ?? 20;
        $status = $this->request->getGet('status');

        $order = strtoupper($this->request->getGet('order') ?? '') ?: 'DESC';
        $order = in_array($order, ['ASC', 'DESC']) ? $order : 'DESC';
        
        $query = $this->transactionModel
            ->select('
            transactions.id,
            transactions.payment_id,
            transactions.transaction_id,
            transactions.payment_method,
            transactions.gateway_transaction_id,
            transactions.amount,
            transactions.commission,
            transactions.net_credited,
            transactions.status, 
            transactions.initiated_at,
            transactions.completed_at,
            events.event_name,
            payments.invite_id, 
            payments.event_id,  
            event_booking.booking_id,
            event_booking.booking_code, 
            app_users.user_id,
            app_users.name as user_name, 
            app_users.profile_image, 
            app_users.profile_status, 
            app_users.email')
            ->join('payments', 'payments.payment_id = transactions.payment_id')
            ->join('app_users', 'app_users.user_id = payments.user_id')
            ->join('events', 'events.event_id = payments.event_id')
            ->join('event_booking', 'event_booking.booking_id = payments.booking_id', 'left')
            ->orderBy('transactions.initiated_at', $order);
            
        if ($status) {
            $query->where('transactions.status', $status);
        }
        
        $transactions = $query->paginate($limit, 'default', $page);
        
        // Transform profile_image to full URL
        foreach ($transactions as &$transaction) {
            if (!empty($transaction['profile_image'])) {
                $transaction['profile_image'] = base_url('uploads/profile_images/' . $transaction['profile_image']);
            }
        }
        
        return $this->respond([
            'success' => true,
            'data' => $transactions,
            'pager' => $this->transactionModel->pager->getDetails()
        ]);
    }

    /**
     * Get transaction details by ID
     */
    public function getTransactionDetails($transactionId = null)
    {
        if (!$transactionId) {
            return $this->fail('Transaction ID is required', 400);
        }
        
        $transaction = $this->transactionModel
            ->select('
            transactions.id,
            transactions.payment_id,
            transactions.transaction_id,
            transactions.payment_method,
            transactions.gateway_transaction_id,
            transactions.amount,
            transactions.commission,
            transactions.net_credited,
            transactions.status, 
            transactions.initiated_at,
            transactions.completed_at,
            payments.invite_id, 
            payments.event_id,  
            payments.booking_id,
            event_booking.booking_code, 
            event_invites.invite_code,
            app_users.user_id,
            app_users.name as user_name, 
            app_users.profile_image, 
            app_users.profile_status, 
            app_users.email,
            events.event_name,
            events.event_location,
            events.event_date_start,
            events.event_date_end')
            ->join('payments', 'payments.payment_id = transactions.payment_id')
            ->join('app_users', 'app_users.user_id = payments.user_id')
            ->join('events', 'events.event_id = payments.event_id')
            ->join('event_invites', 'event_invites.invite_id = payments.invite_id')
            ->join('event_booking', 'event_booking.booking_id = payments.booking_id', 'left')
            ->where('transactions.transaction_id', $transactionId)
            ->first();
            
        if (!$transaction) {
            return $this->failNotFound('Transaction not found');
        }
        
        // Transform profile_image to full URL
        if (!empty($transaction['profile_image'])) {
            $transaction['profile_image'] = base_url('uploads/profile_images/' . $transaction['profile_image']);
        }
        
        return $this->respond([
            'success' => true,
            'data' => $transaction
        ]);
    }

    /**
     * Get event details with transaction status counts
     */
    public function getAllEventTransaction() 
    {
        $page = $this->request->getGet('page') ?? 1;
        $limit = $this->request->getGet('limit') ?? 20;
        $filterStatus = $this->request->getGet('status');
        $order = strtoupper($this->request->getGet('order') ?? '') ?: 'DESC';
        $order = in_array($order, ['ASC', 'DESC']) ? $order : 'DESC';
        
        // Get event details with transaction counts
        $eventDetails = $this->transactionModel
            ->select('events.event_id, 
                events.event_name, 
                events.event_location, 
                events.event_date_start, 
                events.event_date_end, 
                events.total_seats, 
                events.event_code,
                events.status as event_status
            ')
            ->join('payments', 'payments.payment_id = transactions.payment_id')
            ->join('events', 'events.event_id = payments.event_id')
            ->groupBy('events.event_id')
            ->findAll();

        // Get status counts grouped by event
        $statusCounts = $this->transactionModel
            ->select('payments.event_id, transactions.status, COUNT(*) as count, SUM(transactions.amount) as total_amount')
            ->join('payments', 'payments.payment_id = transactions.payment_id')
            ->groupBy('payments.event_id, transactions.status')
            ->findAll();

        // Format event stats
        $eventStats = [];
        foreach ($eventDetails as $event) {
            $eventId = $event['event_id'];
            $eventStats[$eventId] = [
                'event_id' => $eventId,
                'event_name' => $event['event_name'],
                'event_location' => $event['event_location'],
                'event_date_start' => $event['event_date_start'],
                'event_date_end' => $event['event_date_end'],
                'total_seats' => $event['total_seats'],
                'event_code' => $event['event_code'],
                'event_status' => $event['event_status'],
                'transaction_counts' => [
                    'Initiated' => 0,
                    'Success' => 0,
                    'Failed' => 0,
                    'Refunded' => 0
                ],
                'revenue' => [
                    'Initiated' => 0,
                    'Success' => 0,
                    'Failed' => 0,
                    'Refunded' => 0
                ],
                'total_transactions' => 0,
                'total_revenue' => 0
            ];
        }

        // Populate status counts and revenue
        foreach ($statusCounts as $count) {
            $eventId = $count['event_id'];
            $status = $count['status'];
            $statusLabel = ""; 
            switch($status) {
                case '0':
                    $statusLabel = 'Initiated';
                    break;
                case '1':
                    $statusLabel = 'Success';
                    break;
                case '2':
                    $statusLabel = 'Failed';
                    break;
                case '3':
                    $statusLabel = 'Refunded';
                    break;
            }

            
            if (isset($eventStats[$eventId])) {
                $eventStats[$eventId]['transaction_counts'][$statusLabel] = (int)$count['count'];
                $eventStats[$eventId]['revenue'][$statusLabel] = (float)$count['total_amount'];
                $eventStats[$eventId]['total_transactions'] += (int)$count['count'];
                if ((int)$status === TransactionModel::SUCCESS) {
                    $eventStats[$eventId]['total_revenue'] += (float)$count['total_amount'];
                }
            }
        }

        // Sort event stats by event_id
        if ($order === 'ASC') {
            ksort($eventStats);
        } else {
            krsort($eventStats);
        }
        
        // Apply pagination to event stats
        $totalEvents = count($eventStats);
        $offset = ($page - 1) * $limit;
        $paginatedStats = array_slice(array_values($eventStats), $offset, $limit);

        $totalPages = ceil($totalEvents / $limit);
        $hasMore = $page < $totalPages;
        
        return $this->respond([
            'success' => true,
            'data' => $paginatedStats,
            'summary' => [
                'total_events' => $totalEvents,
                'total_transactions' => array_sum(array_column($eventStats, 'total_transactions')),
                'total_revenue' => array_sum(array_column($eventStats, 'total_revenue'))
            ],
            'pager' => [
                'currentUri' => (object)[],
                'uri' => (object)[],
                'hasMore' => $hasMore,
                'total' => $totalEvents,
                'perPage' => (int)$limit,
                'pageCount' => $totalPages,
                'pageSelector' => 'page',
                'currentPage' => (int)$page,
                'next' => $hasMore ? $page + 1 : null,
                'previous' => $page > 1 ? $page - 1 : null,
                'segment' => 0
            ]
        ]);
    }

    /**
     * Get transactions for specific user
     */
    public function getUserTransactions($userId = null)
    {
        if (!$userId) {
            return $this->fail('User ID is required', 400);
        }
        
        $page = $this->request->getGet('page') ?? 1;
        $limit = $this->request->getGet('limit') ?? 20;
        $order = strtoupper($this->request->getGet('order') ?? '') ?: 'DESC';
        $order = in_array($order, ['ASC', 'DESC']) ? $order : 'DESC';
        
        $transactions = $this->transactionModel
            ->select('
            transactions.id,
            transactions.payment_id,
            transactions.transaction_id,
            transactions.payment_method,
            transactions.gateway_transaction_id,
            transactions.amount,
            transactions.commission,
            transactions.net_credited,
            transactions.status, 
            transactions.initiated_at,
            transactions.completed_at,
            payments.invite_id, 
            payments.event_id, 
            events.event_name, 
            event_invites.invite_code,
            event_booking.booking_code, 
            event_booking.booking_id,
            app_users.user_id,
            app_users.name as user_name, 
            app_users.profile_image, 
            app_users.profile_status, 
            app_users.email
            ')
            ->join('payments', 'payments.payment_id = transactions.payment_id')
            ->join('app_users', 'app_users.user_id = payments.user_id')
            ->join('events', 'events.event_id = payments.event_id')
            ->join('event_invites', 'event_invites.invite_id = payments.invite_id')
            ->join('event_booking', 'event_booking.booking_id = payments.booking_id', 'left')
            ->where('payments.user_id', $userId)
            ->orderBy('transactions.initiated_at', $order)
            ->paginate($limit, 'default', $page);
            
        // Transform profile_image to full URL
        foreach ($transactions as &$transaction) {
            if (!empty($transaction['profile_image'])) {
                $transaction['profile_image'] = base_url('uploads/profile_images/' . $transaction['profile_image']);
            }
        }
            
        return $this->respond([
            'success' => true,
            'data' => $transactions,
            'pager' => $this->transactionModel->pager->getDetails()
        ]);
    }

    /**
     * Get transactions for specific event
     */
    public function getEventTransactions($eventId = null)
    {
        if (!$eventId) {
            return $this->fail('Event ID is required', 400);
        }
        
        $page = $this->request->getGet('page') ?? 1;
        $limit = $this->request->getGet('limit') ?? 20;
        $status = $this->request->getGet('status');
        $order = strtoupper($this->request->getGet('order') ?? '') ?: 'DESC';
        $order = in_array($order, ['ASC', 'DESC']) ? $order : 'DESC';
        
        $query = $this->transactionModel
            ->select('
            transactions.id,
            transactions.payment_id,
            transactions.transaction_id,
            transactions.payment_method,
            transactions.gateway_transaction_id,
            transactions.amount,
            transactions.commission,
            transactions.net_credited,
            transactions.status,
            transactions.initiated_at,
            transactions.completed_at,
            event_invites.invite_code,
            event_booking.booking_id,
            event_booking.booking_code, 
            app_users.user_id,
            app_users.name as user_name, 
            app_users.profile_image, 
            app_users.profile_status, 
            app_users.email,
            events.event_id,
            events.event_name
            ')
            ->join('payments', 'payments.payment_id = transactions.payment_id')
            ->join('app_users', 'app_users.user_id = payments.user_id')
            ->join('event_invites', 'event_invites.invite_id = payments.invite_id')
            ->join('events', 'events.event_id = event_invites.event_id')
            ->join('event_booking', 'event_booking.booking_id = payments.booking_id', 'left')
            ->where('payments.event_id', $eventId)
            ->orderBy('transactions.initiated_at', $order);
            
        if ($status) {
            $query->where('transactions.status', $status);
        }
        
        $transactions = $query->paginate($limit, 'default', $page);
        
        // Transform profile_image to full URL
        foreach ($transactions as &$transaction) {
            if (!empty($transaction['profile_image'])) {
                $transaction['profile_image'] = base_url('uploads/profile_images/' . $transaction['profile_image']);
            }
        }
        
        return $this->respond([
            'success' => true,
            'data' => $transactions,
            'pager' => $this->transactionModel->pager->getDetails()
        ]);
    }
}