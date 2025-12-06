<?php

namespace App\Models\Api;

use CodeIgniter\Model;

class TransactionModel extends Model
{
    const INITIATED = 0;
    const SUCCESS = 1;
    const FAILED = 2;
    const REFUNDED = 3;

    protected $table = 'transactions';
    protected $primaryKey = 'id';
    protected $useAutoIncrement = true;
    protected $returnType = 'array';
    protected $useSoftDeletes = false;
    protected $protectFields = true;
    protected $allowedFields = [
        'transaction_id',
        'payment_id', 
        'payment_method',
        'payment_details',
        'gateway_transaction_id',
        'amount',
        'commission',
        'status',
        'initiated_at',
        'completed_at',
        'raw_response'
    ];

    protected $useTimestamps = false;
    protected $dateFormat = 'datetime';
    protected $createdField = 'initiated_at';
    protected $updatedField = 'completed_at';

    protected $validationRules = [
        'payment_id' => 'required|integer',
        'amount' => 'required|decimal',
        'status' => 'required|in_list[0,1,2,3]'
    ];

    protected $validationMessages = [
        'amount' => [
            'required' => 'Amount is required'
        ]
    ];

    protected $skipValidation = false;
    protected $cleanValidationRules = true;

    protected $allowCallbacks = true;
    protected $beforeInsert = ['setInitiatedAt'];
    protected $afterInsert = [];
    protected $beforeUpdate = [];
    protected $afterUpdate = [];
    protected $beforeFind = [];
    protected $afterFind = [];
    protected $beforeDelete = [];
    protected $afterDelete = [];

    protected function setInitiatedAt(array $data)
    {
        if (!isset($data['data']['initiated_at'])) {
            $data['data']['initiated_at'] = date('Y-m-d H:i:s');
        }
        return $data;
    }
}