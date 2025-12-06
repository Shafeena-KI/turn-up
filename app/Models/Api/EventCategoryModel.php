<?php
namespace App\Models\Api;

use CodeIgniter\Model;

class EventCategoryModel extends Model
{
    protected $table = 'event_ticket_category';
    protected $primaryKey = 'category_id';

    protected $allowedFields = [
        'event_id',
        'category_name',
        'total_seats',
        'actual_booked_seats',
        'dummy_booked_seats',
        'dummy_invites',
        'balance_seats',
        'couple_price',
        'price',
        'status'
    ];

    protected $useTimestamps = false;

    const VIP_CATEGORY_CODE = 1;
    const NORMAL_CATEGORY_CODE = 2;

}
