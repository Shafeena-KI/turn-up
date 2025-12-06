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
    const CATEGORY_ACTIVE = 1;
    const CATEGORY_INACTIVE = 2;
    const CATEGORY_SOLDOUT = 3;
    const CATEGORY_DELETED = 4;


    // Function to get Category 
    public function getInviteCategory($invite) {

        return $this->db->table('event_invites')
            ->select('event_invites.entry_type, event_ticket_category.balance_seats')
            ->join('event_ticket_category', 'event_invites.category_id = event_ticket_category.category_id', 'left')
            ->where('event_invites.invite_id', $invite)
            ->where('event_ticket_category.status', self::CATEGORY_ACTIVE)
            ->get()
            ->getRow();
    }
}
