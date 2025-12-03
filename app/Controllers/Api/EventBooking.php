<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\EventBookingModel;
use App\Models\Api\EventInviteModel;
use App\Models\Api\EventCategoryModel;
use App\Models\Api\EventCountsModel;

use CodeIgniter\HTTP\ResponseInterface;

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
        $this->eventCountsModel = new EventCountsModel();
        $this->db = \Config\Database::connect();
    }
    public function getAllEventBookingCounts()
    {
        $builder = $this->db->table('event_booking eb');

        $builder->select("
        eb.event_id,
        e.event_name,
        e.event_code,
        e.event_location,
        e.event_city,
        e.event_date_start,
        e.event_time_start,
        e.event_date_end,
        e.event_time_end,
        e.total_seats AS event_total_seats, 
        
        c.category_id,
        c.category_name,
        c.total_seats,

        -- TOTAL PEOPLE COUNT
        (
            SUM(CASE WHEN ei.entry_type = 1 THEN 1 ELSE 0 END) +   -- Male
            SUM(CASE WHEN ei.entry_type = 2 THEN 1 ELSE 0 END) +   -- Female
            SUM(CASE WHEN ei.entry_type = 3 THEN 1 ELSE 0 END) +   -- Other
            SUM(CASE WHEN ei.entry_type = 4 THEN 2 ELSE 0 END)     -- Couple = 2
        ) AS total_booking,

        -- Quantity of bookings
        SUM(eb.quantity) AS total_quantity,

        -- BREAKDOWN
        SUM(CASE WHEN ei.entry_type = 1 THEN 1 ELSE 0 END) AS total_male_booking,
        SUM(CASE WHEN ei.entry_type = 2 THEN 1 ELSE 0 END) AS total_female_booking,
        SUM(CASE WHEN ei.entry_type = 3 THEN 1 ELSE 0 END) AS total_other_booking,
        SUM(CASE WHEN ei.entry_type = 4 THEN 1 ELSE 0 END) AS total_couple_booking   -- count couples only once
    ");

        $builder->join('events e', 'e.event_id = eb.event_id', 'left');
        $builder->join('event_ticket_category c', 'c.category_id = eb.category_id', 'left');
        $builder->join('event_invites ei', 'ei.invite_id = eb.invite_id', 'left');

        $builder->groupBy('eb.event_id, eb.category_id');

        $rows = $builder->get()->getResultArray();

        $finalData = [];

        foreach ($rows as $row) {

            $eventId = $row['event_id'];
            $categoryText = $row['category_name']; // Category name text directly
            $categoryKey = strtolower($categoryText);

            if (!isset($finalData[$eventId])) {
                $finalData[$eventId] = [
                    'event_id' => $eventId,
                    'event_name' => $row['event_name'],
                    'event_code' => $row['event_code'],
                    'event_location' => $row['event_location'],
                    'event_city' => $row['event_city'],
                    'event_date_start' => $row['event_date_start'],
                    'event_time_start' => $row['event_time_start'],
                    'event_date_end' => $row['event_date_end'],
                    'event_time_end' => $row['event_time_end'],

                    'categories' => [],
                    'overall_total' => [
                        'total_seats' => (int) $row['event_total_seats'],
                        'total_booking' => 0,
                        'total_quantity' => 0,
                        'total_male_booking' => 0,
                        'total_female_booking' => 0,
                        'total_other_booking' => 0,
                        'total_couple_booking' => 0
                    ]
                ];
            }

            // CATEGORY WISE
            $finalData[$eventId]['categories'][$categoryText] = [
                'seats' => (int) $row['total_seats'],
                'total_booking' => (int) $row['total_booking'],
                'total_quantity' => (int) $row['total_quantity'],
                'total_male_booking' => (int) $row['total_male_booking'],
                'total_female_booking' => (int) $row['total_female_booking'],
                'total_other_booking' => (int) $row['total_other_booking'],
                'total_couple_booking' => (int) $row['total_couple_booking']
            ];

            // OVERALL TOTALS
            $finalData[$eventId]['overall_total']['total_booking'] += (int) $row['total_booking'];
            $finalData[$eventId]['overall_total']['total_quantity'] += (int) $row['total_quantity'];
            $finalData[$eventId]['overall_total']['total_male_booking'] += (int) $row['total_male_booking'];
            $finalData[$eventId]['overall_total']['total_female_booking'] += (int) $row['total_female_booking'];
            $finalData[$eventId]['overall_total']['total_other_booking'] += (int) $row['total_other_booking'];
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

        // MAIN BUILDER
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
            app_users.profile_status,
            event_invites.entry_type,  
            event_invites.partner,     
            event_counts.total_booking,
            event_counts.total_male_booking,
            event_counts.total_female_booking,
            event_counts.total_other_booking,
            event_counts.total_couple_booking
        ")
            ->join('events', 'events.event_id = event_booking.event_id', 'left')
            ->join('event_ticket_category', 'event_ticket_category.category_id = event_booking.category_id', 'left')
            ->join('app_users', 'app_users.user_id = event_booking.user_id', 'left')
            ->join('event_invites', 'event_invites.invite_id = event_booking.invite_id', 'left')
            ->join('event_counts', 'event_counts.event_id = event_booking.event_id', 'left')
            ->where('event_booking.status !=', 4)
            ->groupBy('event_booking.booking_id');

        // FILTERS
        if (!empty($event_id)) {
            $builder->where('event_booking.event_id', $event_id);
        }

        if (!empty($search)) {
            $builder->groupStart()
                ->like('events.event_name', $search)
                ->orLike('events.event_city', $search)
                ->orLike('app_users.name', $search)
                ->orLike('app_users.phone', $search)
                ->orLike('app_users.email', $search)
                ->groupEnd();
        }

        // ⚠️ CLONE BUILDER FOR COUNT
        $countBuilder = clone $builder;
        $total = $countBuilder->countAllResults();

        // FETCH PAGINATED RESULTS
        $bookings = $builder
            ->orderBy('event_booking.booking_id', 'DESC')
            ->findAll($limit, $offset);

        // FORMAT DATA...
        foreach ($bookings as &$booking) {
            // your processing logic
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
            "total_other_invites" => 0,
            "total_couple_invites" => 0,

            "total_booking" => 0,
            "total_male_booking" => 0,
            "total_female_booking" => 0,
            "total_other_booking" => 0,
            "total_couple_booking" => 0,

            "total_checkin" => 0,
            "total_male_checkin" => 0,
            "total_female_checkin" => 0,
            "total_other_checkin" => 0,
            "total_couple_checkin" => 0
        ];

        foreach ($categoryRows as $row) {

            // Add row to response
            $categories[] = [
                "category_id" => $row['category_id'],
                "total_invites" => $row['total_invites'],
                "total_male_invites" => $row['total_male_invites'],
                "total_female_invites" => $row['total_female_invites'],
                "total_other_invites" => $row['total_other_invites'],
                "total_couple_invites" => $row['total_couple_invites'],

                "total_booking" => $row['total_booking'],
                "total_male_booking" => $row['total_male_booking'],
                "total_female_booking" => $row['total_female_booking'],
                "total_other_booking" => $row['total_other_booking'],
                "total_couple_booking" => $row['total_couple_booking'],

                "total_checkin" => $row['total_checkin'],
                "total_male_checkin" => $row['total_male_checkin'],
                "total_female_checkin" => $row['total_female_checkin'],
                "total_other_checkin" => $row['total_other_checkin'],
                "total_couple_checkin" => $row['total_couple_checkin'],
            ];

            // Add to overall totals
            $overall["total_invites"] += $row['total_invites'];
            $overall["total_male_invites"] += $row['total_male_invites'];
            $overall["total_female_invites"] += $row['total_female_invites'];
            $overall["total_other_invites"] += $row['total_other_invites'];
            $overall["total_couple_invites"] += $row['total_couple_invites'];

            $overall["total_booking"] += $row['total_booking'];
            $overall["total_male_booking"] += $row['total_male_booking'];
            $overall["total_female_booking"] += $row['total_female_booking'];
            $overall["total_other_booking"] += $row['total_other_booking'];
            $overall["total_couple_booking"] += $row['total_couple_booking'];

            $overall["total_checkin"] += $row['total_checkin'];
            $overall["total_male_checkin"] += $row['total_male_checkin'];
            $overall["total_female_checkin"] += $row['total_female_checkin'];
            $overall["total_other_checkin"] += $row['total_other_checkin'];
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
    protected function getAdminIdFromToken()
    {
        $authHeader = $this->request->getHeaderLine('Authorization');
        if (!$authHeader)
            return null;

        $token = str_replace('Bearer ', '', $authHeader);
        if (!$token)
            return null;

        try {
            $key = getenv('JWT_SECRET') ?: 'default_fallback_key';
            $decoded = \Firebase\JWT\JWT::decode($token, new \Firebase\JWT\Key($key, 'HS256'));
            return $decoded->data->admin_id ?? null;
        } catch (\Throwable $e) {
            return null;
        }
    }
    public function scanQr()
    {
        $data = $this->request->getJSON(true);
        $qrData = $data['qr_data'] ?? null;

        if (!$qrData) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'QR Data missing'
            ]);
        }

        // Decode QR JSON payload
        $decoded = json_decode($qrData, true);

        if (!isset($decoded['booking_code']) || !isset($decoded['token'])) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Invalid QR format'
            ]);
        }

        $booking_code = $decoded['booking_code'];
        $token = $decoded['token'];

        // Verify HMAC token
        $secretKey = getenv('EVENT_QR_SECRET');
        $expectedToken = hash_hmac('sha256', $booking_code, $secretKey);

        if (!hash_equals($expectedToken, $token)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'QR Tampered or Invalid Token'
            ]);
        }

        // Fetch booking
        $booking = $this->bookingModel->where('booking_code', $booking_code)->first();
        if (!$booking) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Booking Code Not Found'
            ]);
        }
        //ticket type
        $category = $this->categoryModel->find($booking['category_id']);

        $ticketType = '';
        if (!empty($category)) {
            $ticketType = $category['category_name'] == 1 ? 'VIP' :
                ($category['category_name'] == 2 ? 'Normal' : 'Unknown');
        }

        // Fetch invite
        $invite = $this->inviteModel->find($booking['invite_id']);

        // Load event details
        $event = $this->db->table('events')
            ->where('event_id', $booking['event_id'])
            ->get()->getRowArray();

        if (!$event) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Event not found'
            ]);
        }

        // --- EVENT DATE / TIME VALIDATION ---


        $tz = new \DateTimeZone('Asia/Kolkata');

        // Fix end time if it is 00:00:00 → consider full day till 23:59:59
        $endTime = ($event['event_time_end'] === null)
            ? '23:59:59'
            : $event['event_time_end'];

        // Event start/end
        $eventStartDateTime = new \DateTime(
            $event['event_date_start'] . ' ' . $event['event_time_start'],
            $tz
        );

        $eventEndDateTime = new \DateTime(
            $event['event_date_end'] . ' ' . $endTime,
            $tz
        );

        // Check-in window (5 hours before start)
        $checkinStartTime = (clone $eventStartDateTime)->modify('-5 hours');

        // Current time
        $now = new \DateTime('now', $tz);

        // Before check-in window
        if ($now < $checkinStartTime) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Event check-in has not started yet'
            ]);
        }

        // After event end
        if ($now > $eventEndDateTime) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Event check-in closed'
            ]);
        }



        // --- CHECK IF ALREADY CHECKED-IN IN checkin table (prefer this over booking.status alone) ---
        $existingCheckin = $this->db->table('checkin')
            ->where('booking_code', $booking['booking_code'])
            ->where('entry_status', 1)
            ->get()
            ->getRowArray();

        if ($existingCheckin) {
            return $this->response->setJSON([
                'status' => false,
                'message' => "User already checked in. Checkin by {$existingCheckin['checkedin_by']} at {$existingCheckin['checkin_time']}"
            ]);
        }

        // Also keep the booking.status check as an additional safeguard
        if ((int) $booking['status'] === 3) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Already checked in'
            ]);
        }

        // Partner comment logic (merge from markAsIn)
        $entry_type = $invite['entry_type'] ?? null;
        $partner_id = $invite['partner'] ?? null;
        $partner_in = $data['partner'] ?? null; // partner presence can be passed with scan payload or default 0
        $entry_comment = "";

        if ($entry_type == "Male" && $partner_id > 0) {
            if ($partner_in == 0)
                $entry_comment = "Female partner didn't come";
            elseif ($partner_in == 2)
                $entry_comment = "Female partner came, but booked male partner";
        }

        if ($entry_type == "Female" && $partner_id > 0) {
            if ($partner_in == 0)
                $entry_comment = "Male partner didn't come";
            elseif ($partner_in == 1)
                $entry_comment = "Male partner came, but booked female partner";
        }

        // Get admin id from token
        $admin_id = $this->getAdminIdFromToken();

        if (!$admin_id) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Unauthorized - Admin ID missing in token'
            ]);
        }

        // Fetch admin name
        $adminDetails = $this->db->table('admin_users')
            ->where('admin_id', $admin_id)
            ->get()
            ->getRowArray();

        $admin_name = $adminDetails['name'] ?? "Unknown";

        // Save check-in into checkin table (same fields as markAsIn)
        $checkinTime = date('Y-m-d H:i:s');
        $checkinData = [
            'user_id' => $booking['user_id'],
            'event_id' => $booking['event_id'],
            'booking_code' => $booking['booking_code'],
            'partner' => $partner_in,
            'category_id' => $booking['category_id'],
            'invite_id' => $booking['invite_id'],
            'entry_status' => 1,
            'checkin_time' => $checkinTime,
            'checkedin_by' => $admin_name,
            'entry_type' => $entry_type,
            'entry_comment' => $entry_comment,
            'booking_id' => $booking['booking_id']
        ];


        // Load user details for response
        $user = $this->db->table('app_users')
            ->where('user_id', $booking['user_id'])
            ->get()->getRowArray();

        $genderMap = [
            1 => 'Male',
            2 => 'Female',
            3 => 'Other',
            4 => 'Couple'
        ];

        $userGender = $genderMap[$user['gender']] ?? 'Unknown';

        $baseURL = base_url();
        $profileImage = !empty($user['profile_image'])
            ? $baseURL . '/uploads/profile/' . $user['profile_image']
            : $baseURL . '/uploads/profile/default.jpg';



        return $this->response->setJSON([
            'status' => true,
            'message' => 'Details Found.',
            'data' => [
                'booking_id' => $booking['booking_id'],
                'booking_code' => $booking['booking_code'],
                'event_name' => $event['event_name'] ?? '',
                'user_name' => $user['name'] ?? ($invite['name'] ?? ''),
                'ticket_type' => $ticketType,
                'entry_type' => $entry_type,
                'gender' => $userGender,
                'profile_image' => $profileImage,
                'checked_in_at' => $checkinTime,
                'checked_in_by' => $admin_name
            ]
        ]);
    }
}
