<?php
namespace App\Models\Api;

use CodeIgniter\Model;

class EventBookingModel extends Model
{

    const BOOKED = 1;
    const CANCELLED = 2;
    const ATTENTED = 3;

    protected $table = 'event_booking';
    protected $primaryKey = 'booking_id';

    protected $allowedFields = [
        'user_id',
        'event_id',
        'category_id',
        'invite_id',
        'total_price',
        'booking_code',
        'qr_code',
        'quantity',
        'status',
        'payment_id',
        'created_at',
        'updated_at'
    ];

    protected $useTimestamps = true;
    protected $dateFormat = 'datetime';
    protected $createdField = 'created_at';
    protected $updatedField = 'updated_at';

    protected $validationRules = [
        'user_id' => 'required|integer',
        'event_id' => 'required|integer',
        'category_id' => 'required|integer',
        'total_price' => 'permit_empty|decimal',
        'booking_code' => 'required|max_length[50]',
        'quantity' => 'permit_empty|integer'
    ];



    protected $skipValidation = false;
    protected $cleanValidationRules = true;

    protected $allowCallbacks = true;
    protected $beforeInsert = [];
    protected $afterInsert = [];
    protected $beforeUpdate = [];
    protected $afterUpdate = [];
    protected $beforeFind = [];
    protected $afterFind = [];
    protected $beforeDelete = [];
    protected $afterDelete = [];

    /**
     * Get booking with event and user details
     */
    public function getBookingWithDetails($bookingId)
    {
        return $this->select('event_booking.*, events.event_name, app_users.name as user_name, app_users.email, app_users.phone')
            ->join('events', 'events.event_id = event_booking.event_id')
            ->join('app_users', 'app_users.user_id = event_booking.user_id')
            ->where('event_booking.booking_id', $bookingId)
            ->first();
    }
    public function getBookingsByEventDetails($event_id)
    {
        $data = $this->db->table('event_booking')
            ->select("
                event_booking.booking_id,
                event_booking.booking_code,
                event_invites.entry_type,
                event_booking.status,
                payments.payment_status,
                event_booking.created_at,

                event_ticket_category.category_name,

                app_users.name AS guest_name,
                app_users.email AS guest_email,
                app_users.phone AS guest_phone,
                app_users.profile_status
            ")
            ->join('app_users', 'app_users.user_id = event_booking.user_id', 'left')
            ->join('event_invites', 'event_invites.invite_id = event_booking.invite_id', 'left')
            ->join('payments', 'payments.payment_id = event_booking.payment_id', 'left')
            ->join(
                'event_ticket_category',
                'event_ticket_category.category_id = event_booking.category_id',
                'left'
            )
            ->where('event_booking.event_id', $event_id)
            ->get()
            ->getResultArray();

        // ENTRY TYPE MAP
        $entryTypes = [
            1 => 'Male',
            2 => 'Female',
            3 => 'Other',
            4 => 'Couple',
        ];

        // BOOKING STATUS MAP
        $statuses = [
            0 => 'Pending',
            1 => 'Confirmed',
            2 => 'Cancelled',
            3 => 'Expired',
        ];

        // PROFILE STATUS MAP
        $profileStatuses = [
            0 => 'Incomplete',
            1 => 'Pending',
            2 => 'Verified',
            3 => 'Rejected',
        ];

        // PAYMENT STATUS MAP
        $paymentStatuses = [
            0 => 'Unpaid',
            1 => 'Paid',
            2 => 'Failed',
            3 => 'Refunded',
        ];

        // TICKET TYPE MAP (using category_id or category_name)
        $ticketTypes = [
            1 => 'VIP',
            2 => 'NORMAL',
        ];

        foreach ($data as &$booking) {

            $booking['entry_type'] =
                $entryTypes[(int) ($booking['entry_type'] ?? -1)] ?? 'N/A';

            $booking['status'] =
                $statuses[(int) ($booking['status'] ?? -1)] ?? 'N/A';

            $booking['profile_status'] =
                $profileStatuses[(int) ($booking['profile_status'] ?? -1)] ?? 'N/A';

            $booking['payment_status'] =
                $paymentStatuses[(int) ($booking['payment_status'] ?? -1)] ?? 'N/A';

            // ticket_type based on category_id
            $booking['ticket_type'] =
                $ticketTypes[(int) ($booking['category_name'] ?? -1)] ?? 'N/A';
        }

        return $data;
    }
    public function getCheckinsByEventDetails($event_id)
    {
        $data = $this->db->table('checkin')
            ->select("
            checkin.checkin_id,
            checkin.checkin_time,
            checkin.checkedin_by,
            checkin.partner,
            checkin.category_id,

            event_booking.booking_code,
            event_booking.invite_id,

            event_invites.entry_type,

            app_users.name AS guest_name,
            app_users.email AS guest_email,
            app_users.phone AS guest_phone,

            event_ticket_category.category_name AS ticket_type
        ")
            ->join('event_booking', 'event_booking.booking_id = checkin.booking_id', 'left')
            ->join('event_invites', 'event_invites.invite_id = event_booking.invite_id', 'left')
            ->join('app_users', 'app_users.user_id = event_booking.user_id', 'left')

            // IMPORTANT: join using CHECKIN.category_id
            ->join('event_ticket_category', 'event_ticket_category.category_id = checkin.category_id', 'left')

            ->where('checkin.event_id', $event_id)
            ->get()
            ->getResultArray();

        // ENTRY TYPE MAP
        $entryTypes = [
            1 => 'Male',
            2 => 'Female',
            3 => 'Other',
            4 => 'Couple',
        ];
        $ticketTypes = [
            1 => 'VIP',
            2 => 'NORMAL',
            // add more if needed
        ];
        foreach ($data as &$row) {

            // entry type
            $row['entry_type'] =
                $entryTypes[(int) ($row['entry_type'] ?? -1)] ?? 'N/A';

            $row['ticket_type'] = $ticketTypes[(int) ($row['ticket_type'] ?? -1)] ?? 'N/A';

            // partner name
            $row['partner'] = $row['partner'] ?? '';

            // formatted time
            if (!empty($row['checkin_time'])) {
                $row['checkin_time'] = date('d-m-Y h:i A', strtotime($row['checkin_time']));
            }
        }

        return $data;
    }



}