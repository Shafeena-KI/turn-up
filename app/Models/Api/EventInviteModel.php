<?php
namespace App\Models\Api;

use CodeIgniter\Model;
use App\Models\Api\EventModel;

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
    const PAYMENT_PENDING = 4;
    const PAID = 5;

    public function getInvitesByEvent($event_id)
    {
        return $this->where('event_id', $event_id)->findAll();
    }

    public function getInvitesByUser($user_id)
    {
        // Subquery to get total bookings per event
        $bookingCountSubquery = "
        (SELECT event_id, COUNT(booking_id) AS total_bookings
         FROM event_booking
         GROUP BY event_id) AS bc
    ";

        $data = $this->db->table('event_invites')
            ->select("
            event_invites.*,
            event_ticket_category.category_name,
            event_ticket_category.price,
            event_ticket_category.couple_price,

            event_booking.booking_id,
            event_booking.booking_code,
            event_booking.qr_code,
            event_booking.total_price,
            event_booking.payment_id,

            events.total_seats,
            bc.total_bookings
        ")
            ->join('event_ticket_category', 'event_ticket_category.category_id = event_invites.category_id', 'left')
            ->join('event_booking', 'event_booking.invite_id = event_invites.invite_id', 'left')
            ->join('events', 'events.event_id = event_invites.event_id', 'left')
            ->join($bookingCountSubquery, 'bc.event_id = event_invites.event_id', 'left')
            ->where('event_invites.user_id', $user_id)
            ->get()
            ->getResultArray();

        foreach ($data as &$inv) {

            // Convert entry_type number → text
            switch ($inv['entry_type']) {
                case '1':
                    $inv['entry_type_name'] = 'Male';
                    break;
                case '2':
                    $inv['entry_type_name'] = 'Female';
                    break;
                case '3':
                    $inv['entry_type_name'] = 'Other';
                    break;
                case '4':
                    $inv['entry_type_name'] = 'Couple';
                    break;
                default:
                    $inv['entry_type_name'] = 'Unknown';
            }

            // Paid amount
            $inv['paid_amount'] = ($inv['entry_type'] == '4')
                ? $inv['couple_price']
                : $inv['price'];

            // Balance seats
            $total_seats = (int) ($inv['total_seats'] ?? 0);
            $total_bookings = (int) ($inv['total_bookings'] ?? 0);

            $inv['balance_seats'] = max($total_seats - $total_bookings, 0);
        }

        return $data;
    }

    public function findUserInvite($inviteId, $userId)
    {
        return (bool) $this->db->table('event_invites')
            ->where('user_id', $userId)
            ->where('invite_id', $inviteId)
            ->countAllResults();
    }


    public function getInviteDetails($inviteId, $userId)
    {
        return $this->db->table('event_invites')
            ->select("
                event_invites.invite_id,
                event_invites.event_id,
                event_invites.user_id,
                event_invites.category_id,
                event_invites.invite_code,
                event_invites.entry_type,
                event_invites.status,
                events.event_name,
                app_users.name AS customer_name,
                app_users.email AS customer_email,
                app_users.phone AS customer_phone
            ")
            ->select("
                CASE 
                    WHEN event_invites.entry_type = 4 
                        THEN event_ticket_category.couple_price
                    ELSE event_ticket_category.price
                END AS price
            ", false)
            ->join('events', 'events.event_id = event_invites.event_id', 'left')
            ->join('event_ticket_category', 'event_ticket_category.category_id = event_invites.category_id', 'left')
            ->join('app_users', 'app_users.user_id = event_invites.user_id', 'left')
            ->where('event_invites.user_id', $userId)
            ->where('event_invites.invite_id', $inviteId)
            ->where('events.status', EventModel::UPCOMING)
            ->get()
            ->getRow();
    }


    public function getInvitesByEventDetails($event_id)
    {
        $data = $this->db->table('event_invites')
            ->select("
            event_invites.invite_id,
            event_invites.invite_code,
            event_invites.entry_type,
            event_invites.partner,
            event_invites.status,
            event_invites.requested_at,
            event_invites.approved_at,

            event_ticket_category.category_name,

            app_users.name AS guest_name,
            app_users.email AS guest_email,
            app_users.phone AS guest_phone,
            app_users.profile_status
        ")
            ->join('app_users', 'app_users.user_id = event_invites.user_id', 'left')
            ->join(
                'event_ticket_category',
                'event_ticket_category.category_id = event_invites.category_id',
                'left'
            )
            ->where('event_invites.event_id', $event_id)
            ->get()
            ->getResultArray();

        // MAP VALUES
        $entryTypes = [
            1 => 'Male',
            2 => 'Female',
            3 => 'Other',
            4 => 'Couple',
        ];

        $statuses = [
            0 => 'Pending',
            1 => 'Approved',
            2 => 'Rejected',
            3 => 'Expired',
        ];

        $profileStatuses = [
            0 => 'Incomplete',
            1 => 'Pending',
            2 => 'Verified',
            3 => 'Rejected',
        ];

        // ✅ Ticket Type mapping
        $ticketTypes = [
            1 => 'VIP',
            2 => 'NORMAL',
        ];

        foreach ($data as &$invite) {

            $invite['entry_type'] =
                $entryTypes[(int) ($invite['entry_type'] ?? -1)] ?? 'N/A';

            $invite['status'] =
                $statuses[(int) ($invite['status'] ?? -1)] ?? 'N/A';

            $invite['profile_status'] =
                $profileStatuses[(int) ($invite['profile_status'] ?? -1)] ?? 'N/A';

            // ✅ Ticket Type
            $invite['ticket_type'] =
                $ticketTypes[(int) ($invite['category_name'] ?? -1)] ?? 'N/A';
        }

        return $data;
    }

}
