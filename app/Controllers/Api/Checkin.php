<?php
namespace App\Controllers\Api;

use App\Controllers\BaseController;
use App\Models\Api\EventInviteModel;
use App\Models\Api\EventBookingModel;
use App\Models\Api\EventModel;
use App\Models\Api\EventCountsModel;
use App\Models\Api\EventCategoryModel;
use App\Models\Api\AppUserModel;

use CodeIgniter\HTTP\ResponseInterface;

class Checkin extends BaseController
{
    protected $userModel;
    protected $eventModel;
    protected $inviteModel;
    protected $bookingModel;
    protected $categoryModel;
    protected $eventCountsModel;



    public function __construct()
    {
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");


        $this->eventModel = new EventModel();
        $this->userModel = new AppUserModel();
        $this->eventCountsModel = new EventCountsModel();
        $this->inviteModel = new EventInviteModel();
        $this->bookingModel = new EventBookingModel();
        $this->categoryModel = new EventCategoryModel();

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





    // -------------------------------------------------------------------
    //  GET DETAILS USING BOOKING CODE
    // -------------------------------------------------------------------




    public function getCheckinDetails()
    {
        $data = $this->request->getJSON(true);
        $booking_code = $data['booking_code'] ?? null;

        if (!$booking_code) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'booking_code is required'
            ]);
        }

        // -------------------------
        // Validate Admin Token
        // -------------------------
        // $admin_id = $this->getAdminIdFromToken();

        // if (!$admin_id) {
        //     return $this->response->setJSON([
        //         'status' => false,
        //         'message' => 'Unauthorized - Admin ID missing in token'
        //     ]);
        // }

        $admin_id = $this->getAdminIdFromToken();

        if (!$admin_id) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON([
                    'status' => 401,
                    'success' => false,
                    'message' => 'Unauthorized - Admin ID missing in token'
                ]);
        }

        $db = db_connect();

        $adminDetails = $db->table('admin_users')
            ->where('admin_id', $admin_id)
            ->get()
            ->getRowArray();

        $admin_name = $adminDetails['name'] ?? 'Unknown';
        // -------------------------

        $booking = $this->bookingModel->where('booking_code', $booking_code)->first();
        if (!$booking) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Invalid booking code'
            ]);
        }

        $invite = $this->inviteModel->find($booking['invite_id']);
        $event = $this->eventModel->find($booking['event_id']);
        $category = $this->categoryModel->find($booking['category_id']);
        $user = $this->userModel->find($booking['user_id']);

        $genderText = 'Unknown';

        if (!empty($user['gender'])) {
            switch ($user['gender']) {
                case 1:
                    $genderText = 'Male';
                    break;
                case 2:
                    $genderText = 'Female';
                    break;
                case 3:
                    $genderText = 'Other';
                    break;
                case 4:
                    $genderText = 'Couple';
                    break;
            }
        }

        // Prepare full URL for profile image
        $profileImage = '';
        if (!empty($user['profile_image'])) {
            if (!preg_match('/^https?:\/\//', $user['profile_image'])) {
                $profileImage = base_url('uploads/profile_images/' . $user['profile_image']);
            } else {
                $profileImage = $user['profile_image'];
            }
        }

        // -------------------------
        //  VALIDATIONS
        // -------------------------
        $today = date('Y-m-d');
        $eventDate = date('Y-m-d', strtotime($event['event_date'] ?? $today)); // assuming event_date exists

        // 1. Future event
        if ($today < $eventDate) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Ticket is not valid for today\'s event'
            ]);
        }

        // 2. Past event
        if ($today > $eventDate) {
            // Check if user already checked in
            $db = db_connect();
            $checkin = $db->table('checkin')
                ->where('booking_code', $booking['booking_code'])
                ->get()
                ->getRowArray();

            if (!$checkin) {

                // Get check-in admin name
                $checkedAdmin = $db->table('admin_users')
                    ->where('admin_id', $checkin['checkedin_by'])
                    ->get()
                    ->getRowArray();

                $checkedInBy = $checkedAdmin['name'] ?? 'Unknown';
                $time = date('d-m-Y H:i:s', strtotime($checkin['checkin_time']));

                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'Ticket is expired'
                ]);
            }
        }

        // 3. Already checked in
        $db = db_connect();
        $checkin = $db->table('checkin')
            ->where('booking_code', $booking['booking_code'])
            ->where('entry_status', 1) // already checked in
            ->get()
            ->getRowArray();

        if ($checkin) {

            // Fetch Admin Name
            $admin_row = $db->table('admin_users')
                ->where('admin_id', $checkin['checkedin_by'])
                ->get()
                ->getRowArray();

            $admin_name = $admin_row['name'] ?? 'Unknown';

            // Format check-in time
            $formattedTime = date('d-m-Y H:i:s', strtotime($checkin['checkin_time']));

            return $this->response->setJSON([
                'status' => false,
                'message' => "{$user['name']} already checked in at {$formattedTime} by {$admin_name}"
            ]);
        }

        $ticketType = '';
        if (!empty($category)) {
            $ticketType = $category['category_name'] == 1 ? 'VIP' :
                ($category['category_name'] == 2 ? 'Normal' : 'Unknown');
        }


        // -------------------------
        // EVENT DATE & TIME VALIDATION
        // -------------------------

        $tz = new \DateTimeZone('Asia/Kolkata');

        // Fix end time
        $endTime = empty($event['event_time_end'])
            ? '23:59:59'
            : $event['event_time_end'];

        // Event start & end datetime
        $eventStartDateTime = new \DateTime(
            $event['event_date_start'] . ' ' . $event['event_time_start'],
            $tz
        );

        $endDate = empty($event['event_date_end'])
            ? $event['event_date_start']
            : $event['event_date_end'];

        $eventEndDateTime = new \DateTime(
            $endDate . ' ' . $endTime,
            $tz
        );


        // Allow check-in 5 hours before start
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


        // -------------------------
        //  RETURN DETAILS
        // -------------------------
        return $this->response->setJSON([
            'status' => true,
            'message' => 'Details found',
            'data' => [
                'booking_id' => $booking['booking_id'],
                'booking_code' => $booking['booking_code'],
                'event_name' => $event['event_name'] ?? '',
                //'ticket_type' => $category['category_name'] ?? '',
                'ticket_type' => $ticketType,
                'entry_type' => $invite['entry_type'] ?? '',
                'user_name' => $user['name'] ?? '',
                'profile_image' => $profileImage,
                'invite_id' => $invite['invite_id'] ?? null,
                'partner' => $invite['partner'] ?? null,
                'gender' => $genderText
            ]
        ]);


    }




    //-----------------------------------------------------------------------
    // REMARKS
    //-----------------------------------------------------------------------

    public function getRemarks()
    {
        try {
            $db = \Config\Database::connect();

            // Fetch all remarks
            $remarks = $db->table('entry_remarks')
                ->orderBy('entry_remarks_group_id', 'ASC')
                ->orderBy('entry_remarks_id', 'ASC')
                ->get()
                ->getResultArray();

            if (!$remarks) {
                return $this->response->setJSON([
                    "status" => false,
                    "message" => "No remarks found"
                ]);
            }

            // Prepare grouped response
            $data = [
                "general" => [],
                "additional" => []
            ];

            foreach ($remarks as $row) {
                if ($row['entry_remarks_group_id'] == 1) {
                    $data['general'][] = [
                        "id" => $row['entry_remarks_id'],
                        "remark" => $row['entry_remarks']
                    ];
                } else {
                    $data['additional'][] = [
                        "id" => $row['entry_remarks_id'],
                        "remark" => $row['entry_remarks']
                    ];
                }
            }

            return $this->response->setJSON([
                "status" => true,
                "data" => $data
            ]);

        } catch (\Exception $e) {
            return $this->response->setJSON([
                "status" => false,
                "message" => "Error loading remarks",
                "error" => $e->getMessage()
            ]);
        }
    }

    // -------------------------------------------------------------------
    // MARK AS IN
    // -------------------------------------------------------------------




    public function markAsIn()
    {
        $data = $this->request->getJSON(true);

        if (empty($data['booking_code'])) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'booking_code is required'
            ]);
        }

        // Get Booking
        $booking = $this->bookingModel->where('booking_code', $data['booking_code'])->first();
        if (!$booking) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'No Booking Code Found.'
            ]);
        }

        $invite = $this->inviteModel->find($booking['invite_id']);

        // DB
        $db = db_connect();
        $event = $db->table('events')->where('event_id', $booking['event_id'])->get()->getRowArray();

        // Remarks
        $remark_ids = $data['entry_remarks_id'] ?? [];

        if (!empty($remark_ids)) {
            if (!is_array($remark_ids)) {
                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'entry_remarks_id must be an array'
                ]);
            }

            $validRemarks = $db->table('entry_remarks')
                ->whereIn('entry_remarks_id', $remark_ids)
                ->get()
                ->getResultArray();

            if (count($validRemarks) != count($remark_ids)) {
                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'One or more invalid remarks selected'
                ]);
            }
        }

        // ----- MINIMAL FIXES: ensure these exist before use -----
        $partner_in = $data['partner_in'] ?? $data['partner'] ?? null;
        $stag_type = $data['stag_type'] ?? null; // 1=Male 2=Female 3=Other
        $comment_id = null;
        $entry_comment = null;
        // -------------------------------------------------------

        // -------------------- ENTRY TYPE FIX --------------------
        $original_entry_type = (int) ($invite['entry_type'] ?? 4); // ✅ STORE ORIGINAL
        $entry_type = $original_entry_type;

        if ($partner_in == 1) {
            $entry_type = 4; // Couple
        }

        // Couple → Stag
        if ($entry_type == 4 && $partner_in == 0) {

            if (empty($stag_type)) {
                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'stag_type is required when partner_in = 0'
                ]);
            }

            $entry_type = (int) $stag_type;
        }

        $entryTypeMap = [
            1 => "Male",
            2 => "Female",
            3 => "Other",
            4 => "Couple"
        ];

        $entry_type_text = $entryTypeMap[$entry_type] ?? "Other";
        // -------------------------------------------------------

        $partner_id = ($entry_type == 4) ? $invite['partner'] : null;

        // -------------------- ENTRY COMMENT FIX --------------------
        /**
         * Apply ONLY when:
         * - Originally booked as Couple
         * - Partner did NOT attend
         */
        if ($original_entry_type == 4 && $partner_in == 0) {

            switch ((int) $stag_type) {
                case 1: // Male attended → Female missing
                    $comment_id = 1;
                    break;

                case 2: // Female attended → Male missing
                    $comment_id = 3;
                    break;

                case 3: // Other attended
                    $comment_id = 5;
                    break;
            }

            if ($comment_id) {
                $comment_row = $db->table('entry_comments')
                    ->where('entry_comments_id', $comment_id)
                    ->get()
                    ->getRowArray();

                $entry_comment = $comment_row['entry_comments'] ?? null;
            }
        }
        // --------------------------------------------------------

        // Date validation
        $today = date('Y-m-d');
        $eventDate = date('Y-m-d', strtotime($event['event_date'] ?? $today));

        if ($today < $eventDate) {
            return $this->response->setJSON([
                'status' => false,
                'message' => "Ticket is not valid for today's event"
            ]);
        }

        if ($today > $eventDate) {
            $checkin = $db->table('checkin')
                ->where('booking_code', $booking['booking_code'])
                ->get()
                ->getRowArray();

            if (!$checkin) {
                return $this->response->setJSON([
                    'status' => false,
                    'message' => 'Ticket is expired'
                ]);
            }
        }

        $checkin = $db->table('checkin')
            ->where('booking_code', $booking['booking_code'])
            ->where('entry_status', 1)
            ->get()
            ->getRowArray();

        if ($checkin) {
            $userDetails = $this->userModel->find($booking['user_id']);
            $user_name = $userDetails['name'] ?? 'User';

            $admin_row = $db->table('admin_users')
                ->where('admin_id', $checkin['checkedin_by'])
                ->get()
                ->getRowArray();

            $admin_name = $admin_row['name'] ?? 'Unknown';

            return $this->response->setJSON([
                'status' => false,
                'message' => "{$user_name} already checked in. Checkin by {$admin_name} at {$checkin['checkin_time']}"
            ]);
        }

        $admin_id = $this->getAdminIdFromToken();

        if (!$admin_id) {
            return $this->response
                ->setStatusCode(401)
                ->setJSON([
                    'status' => 401,
                    'success' => false,
                    'message' => 'Unauthorized - Admin ID missing in token'
                ]);
        }

        $adminDetails = $db->table('admin_users')
            ->where('admin_id', $admin_id)
            ->get()
            ->getRowArray();

        $admin_name = $adminDetails['name'] ?? "Unknown";

        $userDetails = $db->table('app_users')
            ->where('user_id', $booking['user_id'])
            ->get()
            ->getRowArray();

        $user_name = $userDetails['name'] ?? 'Guest';

        // -------------------- SAVE CHECK-IN --------------------
        $checkinData = [
            'user_id' => $booking['user_id'],
            'event_id' => $booking['event_id'],
            'booking_code' => $booking['booking_code'],
            'partner' => $partner_id,
            'category_id' => $booking['category_id'],
            'invite_id' => $booking['invite_id'],
            'entry_comments_id' => $comment_id,
            'entry_status' => 1,
            'checkin_time' => date('Y-m-d H:i:s'),
            'checkedin_by' => $admin_id,
            'entry_type' => $entry_type,
            'booking_id' => $booking['booking_id'],
            'entry_remarks_id' => json_encode($remark_ids)
        ];

        $db->table("checkin")->insert($checkinData);

        // booking update
        $this->bookingModel->update($booking['booking_id'], [
            'status' => 3,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

        // -------------------- EVENT COUNTS --------------------
        $counts = $this->eventCountsModel
            ->where('event_id', $booking['event_id'])
            ->where('category_id', $booking['category_id'])
            ->first();

        if ($counts) {

            $update = [
                'total_checkin' => $counts['total_checkin'],
                'total_male_checkin' => $counts['total_male_checkin'],
                'total_female_checkin' => $counts['total_female_checkin'],
                'total_couple_checkin' => $counts['total_couple_checkin'],
                'total_other_checkin' => $counts['total_other_checkin'],
            ];

            if ($entry_type == 1) {
                $update['total_checkin'] += 1;
                $update['total_male_checkin'] += 1;
            } elseif ($entry_type == 2) {
                $update['total_checkin'] += 1;
                $update['total_female_checkin'] += 1;
            } elseif ($entry_type == 3) {
                $update['total_checkin'] += 1;
                $update['total_other_checkin'] += 1;
            } elseif ($entry_type == 4) {
                $update['total_checkin'] += 2;
                $update['total_couple_checkin'] += 1;
            }

            $this->eventCountsModel->update($counts['id'], $update);
        }

        return $this->response->setJSON([
            'status' => true,
            'message' => 'Marked as IN successfully and booking status updated',
            'data' => [
                'booking_id' => $booking['booking_id'],
                'booking_code' => $booking['booking_code'],
                'event_name' => $event['event_name'] ?? '',
                'user_name' => $invite['name'] ?? '',
                'checked_in_at' => $checkinData['checkin_time'],
                'checked_in_by' => $admin_name,
                'entry_comment' => $entry_comment
            ]
        ]);
    }




    // -------------------------------------------------------------------
    // MARK AS IN LIST
    // -------------------------------------------------------------------


    public function listCheckins($event_id = null, $search = null)
    {
        $db = db_connect();

        $page = (int) $this->request->getGet('current_page') ?: 1;
        $limit = (int) $this->request->getGet('per_page') ?: 10;
        $search = $search ?: ($this->request->getGet('keyword') ?? $this->request->getGet('search'));
        $offset = ($page - 1) * $limit;

        // Base query to fetch check-ins with user, category, and event info
        $builder = $db->table('checkin c')
            ->select('
        c.partner,
        c.entry_type,
        c.category_id,
        etc.category_name,
        c.entry_status,
        c.entry_remarks_id,
        c.entry_comments_id,
        c.booking_code,
        c.checkedin_by,
        c.checkin_time,
        
        u.user_id AS user_id,
        u.name AS user_name,
        u.phone AS user_phone,
        u.email AS user_email,
        u.insta_id AS user_insta_id,
        u.profile_image AS user_profile_image,
        u.profile_status AS profile_status, 

        c.event_id AS event_id, 
        e.event_name,
        e.event_city,
        e.event_location,
        e.event_code,
        e.event_date_start,
        e.event_time_start,
        e.event_date_end,
        e.event_time_end,

        COUNT(c.checkin_id) AS total_checkins,
        SUM(CASE WHEN c.entry_type = 1 THEN 1 ELSE 0 END) AS male_checkins,
        SUM(CASE WHEN c.entry_type = 2 THEN 1 ELSE 0 END) AS female_checkins,
        SUM(CASE WHEN c.entry_type = 3 THEN 1 ELSE 0 END) AS other_checkins,
        SUM(CASE WHEN c.entry_type = 4 THEN 1 ELSE 0 END) AS couple_checkins
    ')
            ->join('events e', 'e.event_id = c.event_id', 'left')
            ->join('app_users u', 'u.user_id = c.user_id', 'left') // 👈 NEW JOIN
            ->join('event_ticket_category etc', 'etc.category_id = c.category_id', 'left')
            ->where('c.entry_status', 1)
            ->groupBy('c.event_id');



        if (!empty($event_id)) {
            $builder->where('c.event_id', $event_id);
            $builder->groupBy('c.checkin_id'); // FIX: show each checkin separately
        }


        if (!empty($search)) {
            $builder->groupStart()
                ->like('e.event_name', $search)
                ->orLike('e.event_city', $search)
                ->orLike('e.event_location', $search)

                ->orLike('c.booking_code', $search)
                ->orLike('u.name', $search)
                ->orLike('u.phone', $search)
                ->orLike('u.email', $search)

                ->groupEnd();
        }

        // total records (corrected)
        $totalQuery = clone $builder;
        $total = count($totalQuery->get()->getResultArray());

        // get paginated
        $checkins = $builder
            ->orderBy('e.event_date_start', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        //     if (empty($checkins)) {
        //     return $this->response->setJSON([
        //         'status' => 200,
        //         'success' => true,
        //         'data' => [
        //             'current_page' => $page,
        //             'per_page' => $limit,
        //             'keyword' => $search,
        //             'total_records' => 0,
        //             'total_pages' => 0,
        //             'checkins' => []
        //         ]
        //     ]);
        // }


        foreach ($checkins as &$checkin) {

            // USER PROFILE IMAGE URL
            $userProfileImage = '';

            if (!empty($checkin['user_profile_image'])) {
                if (!preg_match('/^https?:\/\//', $checkin['user_profile_image'])) {
                    $userProfileImage = base_url('uploads/profile_images/' . $checkin['user_profile_image']);
                } else {
                    $userProfileImage = $checkin['user_profile_image'];
                }
            }

            $checkin['user_profile_image'] = $userProfileImage;

            // Status text mapping
            $statusMap = [
                0 => 'Pending',
                1 => 'Checked In',
                2 => 'Rejected'
            ];
            // $checkin['status_text'] = $statusMap[$checkin['entry_status']] ?? 'Unknown';
            $entryStatus = $checkin['entry_status'] ?? 0;
            $checkin['status_text'] = $statusMap[$entryStatus] ?? 'Pending';


            // Entry type text
            $entryTypeMap = [
                1 => 'Male',
                2 => 'Female',
                3 => 'Other',
                4 => 'Couple'
            ];

            $entryType = $checkin['entry_type'] ?? null;

            $checkin['entry_type_text'] = $entryTypeMap[$entryType] ?? 'N/A';

            // 1. Fetch admin name from admin_id
            $adminRow = $db->table('admin_users')
                ->select('name')
                ->where('admin_id', $checkin['checkedin_by'])
                ->get()
                ->getRowArray();

            // Replace admin_id with admin name in response
            $checkin['checkedin_by'] = $adminRow['name'] ?? 'Unknown';

            $checkin['profile_status'] = isset($checkin['profile_status']) 
                ? (string) $checkin['profile_status'] 
                : null;


            // 2. Format the checkin time
            $checkin['checkin_time_formatted'] = !empty($checkin['checkin_time'])
                ? date('d-m-Y H:i:s', strtotime($checkin['checkin_time']))
                : null;


            // Partner details (if any)
            $partner = $db->table('app_users')
                ->select('user_id, name, phone, email, insta_id, profile_image')
                ->where('user_id', $checkin['partner'])
                ->get()
                ->getRow();


            $profileImage = '';
            if (!empty($partner->profile_image)) {
                if (!preg_match('/^https?:\/\//', $partner->profile_image)) {
                    $profileImage = base_url('uploads/profile_images/' . $partner->profile_image);
                } else {
                    $profileImage = $partner->profile_image;
                }
            }

            $checkin['partner_details'] = $partner ? [
                'user_id' => $partner->user_id,
                'name' => $partner->name,
                'phone' => $partner->phone,
                'email' => $partner->email,
                'insta_id' => $partner->insta_id,
                'profile_image' => $profileImage
            ] : null;


            

            // ENTRY REMARKS (TEXT VALUES)
            $remarkIDs = json_decode($checkin['entry_remarks_id'], true);

            if (!empty($remarkIDs) && is_array($remarkIDs)) {

                $remarksList = $db->table('entry_remarks')
                    ->select('entry_remarks')
                    ->whereIn('entry_remarks_id', $remarkIDs)
                    ->get()
                    ->getResultArray();

                $checkin['entry_remarks'] = array_column($remarksList, 'entry_remarks');
            } else {
                $checkin['entry_remarks'] = [];
            }






            // ENTRY COMMENTS (STRING VALUE)
            $checkin['entry_comments'] = null;

            if (!empty($checkin['entry_comments_id'])) {

                $commentID = json_decode($checkin['entry_comments_id'], true);

                // If JSON contains array, take first value
                if (is_array($commentID)) {
                    $commentID = $commentID[0] ?? null;
                }

                if (!empty($commentID)) {
                    $commentRow = $db->table('entry_comments')
                        ->select('entry_comments')
                        ->where('entry_comments_id', $commentID)
                        ->get()
                        ->getRowArray();

                    $checkin['entry_comments'] = $commentRow['entry_comments'] ?? null;
                }
            }





            // Event counts including total booked
            $eventCounts = $db->table('event_counts')
                ->select('
                SUM(total_booking) AS total_booking,
                SUM(total_checkin) AS total_checkin,
                SUM(total_male_checkin) AS total_male_checkin,
                SUM(total_female_checkin) AS total_female_checkin,
                SUM(total_couple_checkin) AS total_couple_checkin,
                SUM(total_other_checkin) AS total_other_checkin
            ')
                ->where('event_id', $checkin['event_id'])
                ->get()
                ->getRowArray();



            $checkin['event_counts'] = $eventCounts ? [
                'total_booking' => (int) $eventCounts['total_booking'],
                'total_checkin' => (int) $eventCounts['total_checkin'],
                'total_male_checkin' => (int) $eventCounts['total_male_checkin'],
                'total_female_checkin' => (int) $eventCounts['total_female_checkin'],
                'total_couple_checkin' => (int) $eventCounts['total_couple_checkin'],
                'total_other_checkin' => (int) $eventCounts['total_other_checkin'],
            ] : [
                'total_booking' => 0,
                'total_checkin' => 0,
                'total_male_checkin' => 0,
                'total_female_checkin' => 0,
                'total_couple_checkin' => 0,
            ];

            // Add event times and location to match the new requirements
            $checkin['event_times'] = [
                'start_date' => $checkin['event_date_start'],
                'start_time' => $checkin['event_time_start'],
                'end_date' => $checkin['event_date_end'],
                'end_time' => $checkin['event_time_end'],
            ];

            $checkin['event_location'] = $checkin['event_location'];
            $checkin['event_city'] = $checkin['event_city'];
            $checkin['event_code'] = $checkin['event_code'];
        }

        return $this->response->setJSON([
            'status' => 200,
            'success' => true,
            'data' => [
                'current_page' => $page,
                'per_page' => $limit,
                'keyword' => $search,
                'total_records' => $total,
                'total_pages' => ceil($total / $limit),
                'checkins' => $checkins // Key same as invites
            ]
        ]);
    }

}








