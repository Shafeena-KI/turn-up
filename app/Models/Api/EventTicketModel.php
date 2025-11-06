<?php
namespace App\Models\Api;

use CodeIgniter\Model;

class EventTicketModel extends Model
{
    protected $table = 'event_tickets';
    protected $primaryKey = 'ticket_id';

    protected $allowedFields = [
        'event_id',
        'ticket_category',
        'total_seats',
        'actual_booked_seats',
        'dummy_booked_seats',
        'balance_seats',
        'ticket_price',
        'status'
    ];

    protected $useTimestamps = false;
}
