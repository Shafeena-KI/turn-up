<?php

namespace App\Models\Api;

use CodeIgniter\Model;

class PaymentModel extends Model
{
    const PENDING = 1;
    const SUCCESS = 2;
    const FAILED = 3;

    protected $table = 'payments';
    protected $primaryKey = 'payment_id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'invite_id',
        'user_id',
        'event_id',
        'amount',
        'booking_id',
        'payment_status',
        'payment_gateway',
        'payment_date'
    ];

    protected $useTimestamps = false;
    protected $dateFormat = 'datetime';

    protected $validationRules = [
        'invite_id' => 'required|integer',
        'user_id' => 'required|integer',
        'event_id' => 'required|integer',
        'amount' => 'required|decimal',
        'payment_status' => 'required|integer',
        'payment_gateway' => 'required|max_length[150]'
    ];

    protected $validationMessages = [
        'invite_id' => [
            'required' => 'Invite ID is required'
        ],
        'user_id' => [
            'required' => 'User ID is required'
        ],
        'amount' => [
            'required' => 'Amount is required'
        ]
    ];

    protected $skipValidation = false;
    protected $cleanValidationRules = true;

    protected $allowCallbacks = true;
    protected $beforeInsert = ['setPaymentDate'];
    protected $afterInsert = [];
    protected $beforeUpdate = [];
    protected $afterUpdate = [];
    protected $beforeFind = [];
    protected $afterFind = [];
    protected $beforeDelete = [];
    protected $afterDelete = [];

    protected function setPaymentDate(array $data)
    {
        if (!isset($data['data']['payment_date'])) {
            $data['data']['payment_date'] = date('Y-m-d H:i:s');
        }
        return $data;
    }

    public function getPayment($payment) {
        
        return $this->db->table('payments')
                        ->where('payment_id', $payment)
                        ->get()
                        ->getRowArray();
    }
}