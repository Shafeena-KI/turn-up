<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\EventBookingModel;
use App\Models\Api\EventInviteModel;
use App\Models\Api\EventCategoryModel;
use CodeIgniter\HTTP\ResponseInterface;

use Endroid\QrCode\QrCode;
use Endroid\QrCode\Writer\PngWriter;




class EventBooking extends BaseController
{
    protected $bookingModel;
    protected $inviteModel;
    protected $categoryModel;
    protected $db;

    public function __construct()
    {
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Headers: Content-Type, Access-Control-Allow-Headers, Authorization, X-Requested-With");
        $this->bookingModel = new EventBookingModel();
        $this->inviteModel = new EventInviteModel();
        $this->categoryModel = new EventCategoryModel();
        $this->db = \Config\Database::connect();
    }
    public function getAllEventBookingCounts()
    {
        $builder = $this->db->table('event_booking eb');

        $builder->select("
        eb.event_id,
        e.event_name,
        e.event_location,
        e.event_city,
        e.event_date_start,
        e.event_time_start,
        e.event_date_end,
        e.event_time_end,

        c.category_id,
        c.category_name,
        c.total_seats,

        COUNT(eb.booking_id) AS total_booking,
        SUM(eb.quantity) AS total_quantity,

        -- Male booking from invite entry_type
        SUM(CASE WHEN ei.entry_type = 'Male' THEN 1 ELSE 0 END) AS total_male_booking,

        -- Female booking
        SUM(CASE WHEN ei.entry_type = 'Female' THEN 1 ELSE 0 END) AS total_female_booking,

        -- Couple booking
        SUM(CASE WHEN ei.entry_type = 'Couple' THEN 1 ELSE 0 END) AS total_couple_booking
    ");

        $builder->join('events e', 'e.event_id = eb.event_id', 'left');
        $builder->join('event_ticket_category c', 'c.category_id = eb.category_id', 'left');
        $builder->join('event_invites ei', 'ei.invite_id = eb.invite_id', 'left');

        $builder->groupBy('eb.event_id, eb.category_id');

        $rows = $builder->get()->getResultArray();

        $finalData = [];

        foreach ($rows as $row) {

            $eventId = $row['event_id'];
            $categoryKey = strtolower($row['category_name']);

            if (!isset($finalData[$eventId])) {
                $finalData[$eventId] = [
                    'event_id' => $eventId,
                    'event_name' => $row['event_name'],
                    'event_location' => $row['event_location'],
                    'event_city' => $row['event_city'],
                    'event_date_start' => $row['event_date_start'],
                    'event_time_start' => $row['event_time_start'],
                    'event_date_end' => $row['event_date_end'],
                    'event_time_end' => $row['event_time_end'],

                    'categories' => [],
                    'overall_total' => [
                        'total_seats' => 0,
                        'total_booking' => 0,
                        'total_quantity' => 0,
                        'total_male_booking' => 0,
                        'total_female_booking' => 0,
                        'total_couple_booking' => 0
                    ]
                ];
            }

            // Category wise
            $finalData[$eventId]['categories'][$categoryKey] = [
                'seats' => (int) $row['total_seats'],
                'total_booking' => (int) $row['total_booking'],
                'total_quantity' => (int) $row['total_quantity'],
                'total_male_booking' => (int) $row['total_male_booking'],
                'total_female_booking' => (int) $row['total_female_booking'],
                'total_couple_booking' => (int) $row['total_couple_booking']
            ];

            // Overall totals
            $finalData[$eventId]['overall_total']['total_seats'] += (int) $row['total_seats'];
            $finalData[$eventId]['overall_total']['total_booking'] += (int) $row['total_booking'];
            $finalData[$eventId]['overall_total']['total_quantity'] += (int) $row['total_quantity'];
            $finalData[$eventId]['overall_total']['total_male_booking'] += (int) $row['total_male_booking'];
            $finalData[$eventId]['overall_total']['total_female_booking'] += (int) $row['total_female_booking'];
            $finalData[$eventId]['overall_total']['total_couple_booking'] += (int) $row['total_couple_booking'];
        }

        return $this->response->setJSON([
            'status' => 200,
            'message' => 'All event booking counts fetched successfully',
            'data' => array_values($finalData)
        ]);
    }
    public function listBookings($event_id = null, $search = '')
    {
        $page = (int) $this->request->getGet('current_page') ?: 1;
        $limit = (int) $this->request->getGet('per_page') ?: 10;
        $search = $search ?: ($this->request->getGet('keyword') ?? $this->request->getGet('search'));
        $offset = ($page - 1) * $limit;

        // Join with events, categories, users and event_counts
        $builder = $this->bookingModel
            ->select("
        event_booking.*,
        events.event_name,
        events.event_city,
        event_ticket_category.category_name,
        app_users.name,
        app_users.phone,
        app_users.email,
        app_users.insta_id,
        app_users.profile_image,
        event_counts.total_booking,
        event_counts.total_male_booking,
        event_counts.total_female_booking,
        event_counts.total_couple_booking
    ")
            ->join('events', 'events.event_id = event_booking.event_id', 'left')
            ->join('event_ticket_category', 'event_ticket_category.category_id = event_booking.category_id', 'left')
            ->join('app_users', 'app_users.user_id = event_booking.user_id', 'left')
            ->join('event_counts', 'event_counts.event_id = event_booking.event_id', 'left')
            ->where('event_booking.status !=', 4);

        // FIX DUPLICATES
        $builder->groupBy('event_booking.booking_id');
        if (!empty($event_id)) {
            $builder->where('event_booking.event_id', $event_id);
        }
        // Search filter
        if (!empty($search)) {
            $builder->groupStart()
                ->like('events.event_name', $search)
                ->orLike('events.event_city', $search)
                ->orLike('app_users.name', $search)
                ->orLike('app_users.phone', $search)
                ->orLike('app_users.email', $search)
                ->orLike('event_ticket_category.category_name', $search)
                ->groupEnd();
        }

        // Total count
        $total = $builder->countAllResults(false);

        // Fetch paginated list
        $bookings = $builder
            ->orderBy('event_booking.booking_id', 'DESC')
            ->findAll($limit, $offset);

        foreach ($bookings as &$booking) {

            // Category text
            $booking['category_text'] = $booking['category_name'] ?? 'No Category';

            // Status text
            $statusMap = [
                1 => 'Booked',
                2 => 'Cancelled',
                3 => 'Attended'
            ];
            $booking['status_text'] = $statusMap[$booking['status']] ?? 'Unknown';


            /** -------------------------------------------------------
             *  FETCH PARTNER ID USING invite_id FROM event_invites
             * ------------------------------------------------------*/
            $invite = $this->db->table('event_invites')
                ->select('partner')
                ->where('invite_id', $booking['invite_id'])
                ->get()
                ->getRow();

            $partnerId = $invite->partner ?? null;


            /** -------------------------------------------------------
             *  FETCH PARTNER USER DETAILS FROM app_users
             * ------------------------------------------------------*/
            $accUser = null;

            if (!empty($partnerId)) {
                $accUser = $this->db->table('app_users')
                    ->select('user_id, name, phone, email, insta_id, profile_image')
                    ->where('user_id', $partnerId)
                    ->get()
                    ->getRow();
            }

            $booking['partner_details'] = $accUser ? [
                'user_id' => $accUser->user_id,
                'name' => $accUser->name,
                'phone' => $accUser->phone,
                'email' => $accUser->email,
                'insta_id' => $accUser->insta_id,
                'profile_image' => $accUser->profile_image
            ] : null;


            // Event booking totals
            $booking['event_counts'] = [
                'total_booking' => (int) $booking['total_booking'],
                'total_male_booking' => (int) $booking['total_male_booking'],
                'total_female_booking' => (int) $booking['total_female_booking'],
                'total_couple_booking' => (int) $booking['total_couple_booking'],
            ];
        }


        $totalPages = ceil($total / $limit);

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'data' => [
                'current_page' => $page,
                'per_page' => $limit,
                'keyword' => $search,
                'total_records' => $total,
                'total_pages' => $totalPages,
                'bookings' => $bookings
            ]
        ]);
    }
    public function getTotalBookingCounts($event_id)
    {
        // Validate event_id
        if (empty($event_id)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'event_id is required'
            ]);
        }

        $db = \Config\Database::connect();

        // Fetch all category rows for this event
        $builder = $db->table('event_counts');
        $builder->where('event_id', $event_id);
        $categoryRows = $builder->get()->getResultArray();

        if (empty($categoryRows)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'No category records found for this event'
            ]);
        }

        // Prepare response
        $categories = [];
        $overall = [
            "total_invites" => 0,
            "total_male_invites" => 0,
            "total_female_invites" => 0,
            "total_couple_invites" => 0,

            "total_booking" => 0,
            "total_male_booking" => 0,
            "total_female_booking" => 0,
            "total_couple_booking" => 0,

            "total_checkin" => 0,
            "total_male_checkin" => 0,
            "total_female_checkin" => 0,
            "total_couple_checkin" => 0
        ];

        foreach ($categoryRows as $row) {

            // Add row to response
            $categories[] = [
                "category_id" => $row['category_id'],
                "total_invites" => $row['total_invites'],
                "total_male_invites" => $row['total_male_invites'],
                "total_female_invites" => $row['total_female_invites'],
                "total_couple_invites" => $row['total_couple_invites'],

                "total_booking" => $row['total_booking'],
                "total_male_booking" => $row['total_male_booking'],
                "total_female_booking" => $row['total_female_booking'],
                "total_couple_booking" => $row['total_couple_booking'],

                "total_checkin" => $row['total_checkin'],
                "total_male_checkin" => $row['total_male_checkin'],
                "total_female_checkin" => $row['total_female_checkin'],
                "total_couple_checkin" => $row['total_couple_checkin'],
            ];

            // Add to overall totals
            $overall["total_invites"] += $row['total_invites'];
            $overall["total_male_invites"] += $row['total_male_invites'];
            $overall["total_female_invites"] += $row['total_female_invites'];
            $overall["total_couple_invites"] += $row['total_couple_invites'];

            $overall["total_booking"] += $row['total_booking'];
            $overall["total_male_booking"] += $row['total_male_booking'];
            $overall["total_female_booking"] += $row['total_female_booking'];
            $overall["total_couple_booking"] += $row['total_couple_booking'];

            $overall["total_checkin"] += $row['total_checkin'];
            $overall["total_male_checkin"] += $row['total_male_checkin'];
            $overall["total_female_checkin"] += $row['total_female_checkin'];
            $overall["total_couple_checkin"] += $row['total_couple_checkin'];
        }

        return $this->response->setJSON([
            'status' => true,
            'event_id' => $event_id,
            'categories' => $categories,
            'overall_totals' => $overall
        ]);
    }
    // Get all bookings by Event
    public function getBookingsByEvent()
    {
        $data = $this->request->getJSON(true);
        $event_id = $data['event_id'] ?? null;

        if (empty($event_id)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'event_id is required.'
            ]);
        }

        $bookings = $this->bookingModel->where('event_id', $event_id)->findAll();

        return $this->response->setJSON([
            'status' => true,
            'data' => $bookings
        ]);
    }
    // Get all bookings by User
    public function getBookingsByUser()
    {
        $data = $this->request->getJSON(true);
        $user_id = $data['user_id'] ?? null;

        if (empty($user_id)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'user_id is required.'
            ]);
        }

        $bookings = $this->bookingModel->where('user_id', $user_id)->findAll();

        return $this->response->setJSON([
            'status' => true,
            'data' => $bookings
        ]);
    }
    // Cancel Booking
    public function cancelBooking()
    {
        $data = $this->request->getJSON(true);
        $booking_id = $data['booking_id'] ?? null;

        if (empty($booking_id)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'booking_id is required.'
            ]);
        }

        $booking = $this->bookingModel->find($booking_id);
        if (!$booking) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Booking not found.'
            ]);
        }

        if ($booking['status'] == 2) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Booking already cancelled.'
            ]);
        }

        // Update booking
        $this->bookingModel->update($booking_id, [
            'status' => 2,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // Restore seat to ticket
        $category = $this->categoryModel->find($booking['category_id']);
        if ($category) {
            $this->categoryModel->update($category['category_id'], [
                'actual_booked_seats' => max(0, $category['actual_booked_seats'] - 1),
                'balance_seats' => $category['balance_seats'] + 1,
            ]);
        }

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Booking cancelled successfully.'
        ]);
    }

    //generating Qr code using booking code 

    public function generateQrCode()
    {
        $data = $this->request->getJSON(true);
        $booking_code = $data['booking_code'] ?? null;

        if (!$booking_code) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'booking_code is required'
            ]);
        }

        $booking = $this->bookingModel->where('booking_code', $booking_code)->first();
        if (!$booking) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Invalid booking code'
            ]);
        }

        $qrFolder = WRITEPATH . 'uploads/qr_codes/';
        if (!is_dir($qrFolder)) {
            mkdir($qrFolder, 0777, true);
        }

        $fileName = $booking_code . '.png';
        $filePath = $qrFolder . $fileName;

        // QR code generation (v6)
        $qrCode = new QrCode($booking_code);
        $writer = new PngWriter();
        $result = $writer->write($qrCode);

        $result->saveToFile($filePath);

        return $this->response->setJSON([
            'status' => true,
            'message' => 'QR Code Generated',
            'qr_url' => base_url('writable/uploads/qr_codes/' . $fileName),
            'booking_code' => $booking_code
        ]);
    }



    // to get the details when scaning the qr code 
    public function scanQr()
    {
        $data = $this->request->getJSON(true);
        $booking_code = $data['booking_code'] ?? null;

        if (!$booking_code) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'booking_code is required'
            ]);
        }

        $booking = $this->bookingModel->where('booking_code', $booking_code)->first();

        if (!$booking) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Invalid booking code'
            ]);
        }

        // Load required tables
        $event = $this->db->table('events')->where('event_id', $booking['event_id'])->get()->getRowArray();
        $category = $this->db->table('event_ticket_category')->where('category_id', $booking['category_id'])->get()->getRowArray();
        $invite = $this->db->table('event_invites')->where('invite_id', $booking['invite_id'])->get()->getRowArray();
        $user = $this->db->table('app_users')->where('user_id', $booking['user_id'])->get()->getRowArray();

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Details found',
            'data' => [
                'booking_id' => $booking['booking_id'],
                'booking_code' => $booking['booking_code'],
                'event_name' => $event['event_name'] ?? '',
                'ticket_type' => $category['category_name'] ?? '',
                'entry_type' => $invite['entry_type'] ?? '',
                'user_name' => $user['name'] ?? '',
                'profile_image' => $user['profile_image'] ?? '',
                'invite_id' => $invite['invite_id'] ?? null,
                'partner' => $invite['partner'] ?? null,
            ]
        ]);
    }


}
