<?php
namespace App\Models\Api;

use CodeIgniter\Model;

class EventInviteModel extends Model
{
    protected $table = 'event_invites';
    protected $primaryKey = 'invite_id';

    protected $allowedFields = [
        'event_id',
        'user_id',
        'category_id',
        'entry_type',
        'invite_code',
        'partner',
        'status',
        'approval_type',
        'requested_at',
        'approved_at'
    ];

    protected $useTimestamps = false;

    /*
    # Invite Status :
    */
    const PENDING = 0;
    const APPROVED = 1;
    const REJECETD = 2;
    const EXPIRED = 3;

    public function getInvitesByEvent($event_id)
    {
        return $this->where('event_id', $event_id)->findAll();
    }

    public function getInvitesByUser($user_id)
    {
        $data = $this->db->table('event_invites')
            ->select("
            event_invites.*,
            event_ticket_category.category_name,

            event_booking.booking_id,
            event_booking.booking_code,
            event_booking.qr_code,
            event_booking.total_price,
            event_booking.payment_id
        ")
            ->join('event_ticket_category', 'event_ticket_category.category_id = event_invites.category_id', 'left')
            ->join('event_booking', 'event_booking.invite_id = event_invites.invite_id', 'left')
            ->where('event_invites.user_id', $user_id)
            ->get()
            ->getResultArray();

        // Convert entry_type number → NAME (overwrite original field)
        foreach ($data as &$inv) {

            switch ($inv['entry_type']) {
                case '1':
                    $inv['entry_type'] = 'Male';
                    break;

                case '2':
                    $inv['entry_type'] = 'Female';
                    break;

                case '3':
                    $inv['entry_type'] = 'Other';
                    break;

                case '4':
                    $inv['entry_type'] = 'Couple';
                    break;

                default:
                    $inv['entry_type'] = 'Unknown';
            }
        }

        return $data;
    }

}
