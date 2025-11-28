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
    protected $inviteModel;
    protected $bookingModel;
    protected $eventModel;
    protected $categoryModel;
    protected $userModel;


    public function __construct()
    {
        header('Access-Control-Allow-Origin: *');
        header("Access-Control-Allow-Methods: GET, POST, OPTIONS, PUT, DELETE");
        header("Access-Control-Allow-Headers: Content-Type, Authorization");

        $this->inviteModel = new EventInviteModel();
        $this->bookingModel = new EventBookingModel();
        $this->eventModel = new EventModel();
        $this->categoryModel = new EventCategoryModel();
        $this->userModel = new AppUserModel();
        $this->eventCountsModel = new EventCountsModel();

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

            // Directly use the value stored in checkin table
            $checkedInBy = $checkin['checkedin_by'] ?? 'Unknown';

            // If empty string, replace with Unknown
            if (trim($checkedInBy) === '') {
                $checkedInBy = 'Unknown';
            }

            return $this->response->setJSON([
                'status' => false,
                'message' => "{$user['name']} already checked in at {$checkin['checkin_time']} by {$checkedInBy}"
            ]);
        }

        $ticketType = '';
        if (!empty($category)) {
            $ticketType = $category['category_name'] == 1 ? 'VIP' :
                        ($category['category_name'] == 2 ? 'Normal' : 'Unknown');
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
                // 'ticket_type' => $category['category_name'] ?? '',
                'ticket_type' => $ticketType,
                'entry_type' => $invite['entry_type'] ?? '',
                'user_name' => $user['name'] ?? '',
                'profile_image' => $profileImage,
                'invite_id' => $invite['invite_id'] ?? null,
                'partner' => $invite['partner'] ?? null,
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
            "data"   => $data
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

        // FIX HERE — Create DB connection
        $db = db_connect();
        $event = $db->table('events')->where('event_id', $booking['event_id'])->get()->getRowArray();

        // RECEIVE remark ID
        $remark_ids = $data['entry_remarks_id'] ?? [];


        // VALIDATE remark
        if (empty($remark_ids) || !is_array($remark_ids)) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'entry_remarks_id is required as an array'
            ]);
        }

        // CHECK if remark exists in DB
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

        // -------------------- PARTNER & ENTRY TYPE --------------------
            $partner_in = $data['partner'] ?? null;
            $entry_type = $invite['entry_type'];
            $entry_comment = "";

            // Partner comment logic
            $partner_id = $invite['partner'] ?? null;

            if ($entry_type == "Male" && $partner_id > 0) {
                if ($partner_in == null)
                    $entry_comment = "Female partner didn't come";
                elseif ($partner_in == 2)
                    $entry_comment = "Female partner came, but booked male partner";
            }

            if ($entry_type == "Female" && $partner_id > 0) {
                if ($partner_in == null)
                    $entry_comment = "Male partner didn't come";
                elseif ($partner_in == 1)
                    $entry_comment = "Male partner came, but booked female partner";
            }


        

        $today = date('Y-m-d');
        $eventDate = date('Y-m-d', strtotime($event['event_date'] ?? $today));

        // ------------------------- VALIDATIONS -------------------------
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
            // Fetch actual app user name
            $userDetails = $this->userModel->find($booking['user_id']);
            $user_name = $userDetails['name'] ?? 'User';

            return $this->response->setJSON([
                'status' => false,
                'message' => "{$user_name} already checked in. Checkin by {$checkin['checkedin_by']} at {$checkin['checkin_time']}"
            ]);
        }

       
        //getadmin tocken

        $admin_id = $this->getAdminIdFromToken();

        if (!$admin_id) {
            return $this->response->setJSON([
                'status' => false,
                'message' => 'Unauthorized - Admin ID missing in token'
            ]);
        }

        // Fetch admin name using admin_id
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


        // Save check-in
        $checkinData = [
            'user_id' => $booking['user_id'],
            'event_id' => $booking['event_id'],
            'booking_code' => $booking['booking_code'],
            'partner' => $partner_in,
            'category_id' => $booking['category_id'],
            'invite_id' => $booking['invite_id'],
            'entry_status' => 1,
            'checkin_time' => date('Y-m-d H:i:s'),
            'checkedin_by' => $admin_name,
            'entry_type' => $entry_type,
            'entry_comment' => $entry_comment,
            'booking_id' => $booking['booking_id'],
            'entry_remarks_id' => json_encode($remark_ids)
        ];

        $db->table("checkin")->insert($checkinData);

        // Update booking status
        $this->bookingModel->update($booking['booking_id'], [
            'status' => 3,
            'updated_at' => date('Y-m-d H:i:s')
        ]);

       // Update event counts
        $counts = $this->eventCountsModel
            ->where('event_id', $booking['event_id'])
            ->where('category_id', $booking['category_id'])
            ->first();

        if ($counts) {

            // Initialize update array
            $update = [
                'total_checkin' => $counts['total_checkin'],
                'total_male_checkin' => $counts['total_male_checkin'],
                'total_female_checkin' => $counts['total_female_checkin'],
                'total_couple_checkin' => $counts['total_couple_checkin'],
                'total_other_checkin' => $counts['total_other_checkin'],
            ];

            // Entry type conditions
            if ($entry_type == "Male") {
                $update['total_checkin'] = $counts['total_checkin'] + 1;
                $update['total_male_checkin'] = $counts['total_male_checkin'] + 1;
            } 
            
            elseif ($entry_type == "Female") {
                $update['total_checkin'] = $counts['total_checkin'] + 1;
                $update['total_female_checkin'] = $counts['total_female_checkin'] + 1;
            } 
            
            elseif ($entry_type == "Other") {
                $update['total_checkin'] = $counts['total_checkin'] + 1;
                $update['total_other_checkin'] = $counts['total_other_checkin'] + 1;
            } 
            
            elseif ($entry_type == "Couple") {
                $update['total_checkin'] = $counts['total_checkin'] + 2; // 2 people
                $update['total_couple_checkin'] = $counts['total_couple_checkin'] + 1; // 1 couple
            }

            $this->eventCountsModel->update($counts['id'], $update);
        }



        // return $this->response->setJSON([
        //     'status' => true,
        //     'message' => 'Marked as IN successfully and booking status updated'
        // ]);
        return $this->response->setJSON([
            'status' => true,
            'message' => 'Marked as IN successfully and booking status updated',
            'data' => [
                'booking_id' => $booking['booking_id'],
                'booking_code' => $booking['booking_code'],
                'event_name' => $event['event_name'] ?? '',
                'user_name' => $invite['name'] ?? '',
                'checked_in_at' => $checkinData['checkin_time'],
                'checked_in_by' => $admin_name
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
        $builder = $db->table('events e')
        ->select('
            c.*,
            e.event_id AS event_main_id,
            e.event_name,
            e.event_city,
            e.event_location,
            e.event_code,
            e.event_date_start,
            e.event_time_start,
            e.event_date_end,
            e.event_time_end,
            ec.category_name,
            u.name AS user_name,
            u.phone AS user_phone,
            u.email AS user_email,
            u.insta_id AS user_insta_id,
            u.profile_image AS user_profile_image
        ')
        ->join('checkin c', 'c.event_id = e.event_id AND c.entry_status = 1', 'left')
        ->join('event_ticket_category ec', 'ec.category_id = c.category_id', 'left')
        ->join('app_users u', 'u.user_id = c.user_id', 'left')
        ->groupBy('c.checkin_id');


        if (!empty($event_id)) {
            // $builder->where('c.event_id', $event_id);
            $builder->where('e.event_id', $event_id);

        }

        if (!empty($search)) {
            $builder->groupStart()
                ->like('e.event_name', $search)
                ->orLike('e.event_city', $search)
                ->orLike('u.name', $search)
                ->orLike('u.phone', $search)
                ->orLike('u.email', $search)
                ->orLike('ec.category_name', $search)
                ->groupEnd();
        }

        // Total records
        $total = $builder->countAllResults(false);

        // Fetch paginated results
        $checkins = $builder
            ->orderBy('c.checkin_time', 'DESC')
            ->limit($limit, $offset)
            ->get()
            ->getResultArray();

        foreach ($checkins as &$checkin) {

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



            // Partner details (if any)
            $partner = $db->table('app_users')
                ->select('user_id, name, phone, email, insta_id, profile_image')
                ->where('user_id', $checkin['partner'])
                ->get()
                ->getRow();

            $checkin['partner_details'] = $partner ? [
                'user_id' => $partner->user_id,
                'name' => $partner->name,
                'phone' => $partner->phone,
                'email' => $partner->email,
                'insta_id' => $partner->insta_id,
                'profile_image' => $partner->profile_image
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

            // Event counts including total booked
            $eventCounts = $db->table('event_counts')
                ->select('total_booking, total_checkin, total_male_checkin, total_female_checkin, total_couple_checkin, total_other_checkin')
                ->where('event_id', $checkin['event_id'])
                ->orderBy('id', 'DESC')
                ->limit(1)
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
