<?php
namespace App\Models\Api;

use CodeIgniter\Model;

class EventBookingModel extends Model
{
    protected $table = 'event_booking';
    protected $primaryKey = 'booking_id';

    protected $allowedFields = [
        'user_id',
        'event_id',
        'category_id',
        'invite_id',
        'total_price',
        'quantity',
        'status',
        'payment_id',
        'created_at',
        'updated_at'
    ];

    protected $useTimestamps = false;
}
